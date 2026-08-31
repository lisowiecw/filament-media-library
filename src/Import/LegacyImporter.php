<?php

declare(strict_types=1);

namespace Lisowiecw\MediaLibrary\Import;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Facades\Storage;
use League\Flysystem\UnableToRetrieveMetadata;
use Lisowiecw\MediaLibrary\Enums\MediaSource;
use Lisowiecw\MediaLibrary\Enums\Visibility;
use Lisowiecw\MediaLibrary\Exceptions\ImportRefused;
use Lisowiecw\MediaLibrary\Ingest\IngestRules;
use Lisowiecw\MediaLibrary\Ingest\Placement;
use Lisowiecw\MediaLibrary\Ingest\ReadableName;
use Lisowiecw\MediaLibrary\Models\MediaAsset;
use Lisowiecw\MediaLibrary\Models\MediaAttachment;

/**
 * Adopts objects an application already has into the library, by reading the
 * column that holds their paths.
 *
 * Adoption is not ingest and deliberately does not go through it: the bytes
 * are already stored, so there is nothing to refuse about their size or their
 * name, and refusing one would leave a host row pointing at an asset that does
 * not exist. The one rule both share is the denylist, because a `.phar` is not
 * something the library should hold however it got there.
 *
 * The run writes to the source disk in exactly one case, `--copy`, and even
 * then only to a key nothing occupies. There is no move mode: nothing here
 * ever deletes or rewrites what the application already had.
 *
 * Adoption is only half of it: the point of reading the host row is that the
 * row can find its assets again afterwards, so a run with a field context
 * writes the attachments too, in the order the column held them.
 */
class LegacyImporter
{
    public function import(ImportRequest $request): ImportReport
    {
        if (! $this->diskIsConfigured($request->disk)) {
            throw ImportRefused::unknownDisk($request->disk);
        }

        // A declared visibility the disk cannot deliver is a configuration
        // error, and the one an import is most likely to make: it names a
        // legacy bucket the package has never placed anything on. Fail before
        // the first row rather than after the last.
        if ($request->visibility !== null) {
            Placement::assertDeliverable($request->disk, $request->visibility, $request->field);
        }

        $report = new ImportReport($request);
        $disk = Storage::disk($request->disk);
        $rules = IngestRules::resolve();

        try {
            $discovery = $request->discovery;

            if ($discovery instanceof TraversalDiscovery) {
                $this->traverse($discovery, $request, $report, $disk, $rules);

                return $report;
            }

            if ($discovery instanceof ColumnDiscovery) {
                $model = $this->host($discovery, $request);

                $rows = $model->newQuery()
                    ->whereNotNull($discovery->column)
                    ->lazyById($request->chunk);

                foreach ($rows as $row) {
                    $report->examined++;

                    $this->adoptRow($row, $discovery, $request, $report, $disk, $rules);
                }
            }
        } catch (ImportRefused $refusal) {
            // The run ends here, but what it adopted before the bad row is
            // already written, so the refusal carries the report rather than
            // leaving the operator to guess how far it got.
            throw $refusal->withReport($report);
        }

        return $report;
    }

    /**
     * The degraded half: a prefix on the disk, walked lazily, with no host row
     * behind any of it. Every key is examined on its own, so a bucket larger
     * than memory is still importable and nothing is attached to anything.
     */
    private function traverse(
        TraversalDiscovery $discovery,
        ImportRequest $request,
        ImportReport $report,
        FilesystemAdapter $disk,
        IngestRules $rules,
    ): void {
        foreach (DiskTraversal::keys($disk, $discovery->prefix) as $key) {
            $report->examined++;

            $this->adopt($key, $request, $report, $disk, $rules);
        }
    }

    /**
     * One host row: its column read against the declared cardinality, each key
     * adopted in turn, then the attachments written for whatever survived.
     *
     * A shape the run cannot honestly handle throws out of here rather than
     * being reported, because it is a statement about the column rather than
     * about this row, and the rows behind it are about to be misread the same
     * way.
     */
    private function adoptRow(
        Model $row,
        ColumnDiscovery $discovery,
        ImportRequest $request,
        ImportReport $report,
        FilesystemAdapter $disk,
        IngestRules $rules,
    ): void {
        $shape = ColumnShape::read($row->getAttribute($discovery->column), $discovery->cardinality(), $this->rowLabel($row));

        if ($shape->isEmpty()) {
            $report->omit($this->rowLabel($row), ImportOmission::EmptyValue);

            return;
        }

        foreach ($shape->skips as $skip) {
            $report->omit($skip['path'], $skip['reason'], null, $skip['index']);
        }

        $assets = [];

        // A single-value column has no elements to number, so its omissions
        // stay row-level: an index of zero would read as one bad element of
        // several, which is exactly what it is not.
        $numbersElements = $discovery->cardinality() === Cardinality::Many;

        foreach ($shape->elements as $index => $key) {
            $asset = $this->adopt($key, $request, $report, $disk, $rules, $row, $numbersElements ? $index : null);

            if ($asset !== null) {
                $assets[$index] = $asset;
            }
        }

        $this->attach($row, $request, $report, $assets);
    }

    /**
     * Wire the row to what was adopted for it, at the index the column held it.
     *
     * The index is taken verbatim rather than renumbered, so a position in the
     * report is the position in the legacy column even where an element in
     * front of it was skipped. Writes resolve rather than insert, on the host,
     * field and asset the unique index is built from, so a re-run adds nothing
     * and, crucially, never touches the order of a row that already exists:
     * that order may be one a person set in the picker after the last run.
     *
     * A skipped element therefore leaves a gap in the sequence, which is safe:
     * every reader sorts on the column rather than counting through it, and
     * the reconciler renumbers the field from zero the first time a person
     * saves it.
     *
     * @param  array<int, MediaAsset>  $assets
     */
    private function attach(Model $row, ImportRequest $request, ImportReport $report, array $assets): void
    {
        if (! $request->attaches()) {
            return;
        }

        foreach ($assets as $order => $asset) {
            $attachment = MediaAttachment::query()->firstOrCreate([
                'media_asset_id' => $asset->getKey(),
                'host_type' => $row->getMorphClass(),
                'host_id' => (string) $row->getKey(),
                'field_name' => $request->field,
            ], [
                'order' => $order,
            ]);

            if ($attachment->wasRecentlyCreated) {
                $report->attached++;
            }
        }
    }

    /**
     * One legacy path: everything that can decline it happens before a row is
     * written, and the row itself is resolved rather than inserted, so a
     * re-run finds what the last one left and edits nothing.
     *
     * The asset comes back so the caller can attach it, and an asset a previous
     * run already adopted comes back too: it is not new, but it is exactly what
     * the host row should be pointing at.
     *
     * @param  Model|null  $row  the host row the key came from, where there was one
     * @param  int|null  $element  the index in a multi-value column, for the report
     */
    private function adopt(
        string $key,
        ImportRequest $request,
        ImportReport $report,
        FilesystemAdapter $disk,
        IngestRules $rules,
        ?Model $row = null,
        ?int $element = null,
    ): ?MediaAsset {
        $name = ReadableName::from($key);

        if ($rules->blocks($name->extension, null)) {
            $report->omit($key, ImportOmission::BlockedType, $name->extension, $element);

            return null;
        }

        // Identity is settled before anything is read from the disk: a re-run
        // that has already adopted this pair must cost one query and no object
        // reads at all, which is what makes `--sniff` affordable to repeat.
        $objectKey = $request->copy ? $this->copyKey($request, $key, $name->extension) : $key;

        $existing = $this->existing($request->disk, $objectKey);

        if ($existing !== null) {
            $report->alreadyPresent++;

            if ($request->checkDrift) {
                $this->checkDrift($existing, $objectKey, $request, $report, $disk, $element);
            }

            return $existing;
        }

        if (! $disk->exists($key)) {
            $report->omit($key, ImportOmission::MissingObject, null, $element);

            return null;
        }

        $mime = MimeLadder::resolve($disk, $key, $name->extension, $request->sniff);

        // The denylist is matched on the resolved type as well, so an
        // extension that says nothing cannot carry a blocked type past it.
        if ($rules->blocks($name->extension, $mime->mimeType)) {
            $report->omit($key, ImportOmission::BlockedType, $mime->mimeType ?? $name->extension, $element);

            return null;
        }

        $size = $this->size($disk, $key);

        if ($size === null) {
            $report->omit($key, ImportOmission::UnreadableMetadata, null, $element);

            return null;
        }

        if ($request->copy && ! $disk->missing($objectKey)) {
            $report->omit($key, ImportOmission::DestinationOccupied, $objectKey, $element);

            return null;
        }

        $visibility = ImportVisibility::resolve($request->disk, $key, $request->visibility);

        // The same invariant every placement is held to: an asset recorded
        // private on a bucket the application declares public is a row that
        // lies about who can fetch it, however it got here.
        Placement::assertDeliverable($request->disk, $visibility, $request->field);

        if ($request->dryRun) {
            $report->registered++;

            if ($request->copy) {
                $report->copied++;
            }

            return null;
        }

        if ($request->copy) {
            $this->copy($disk, $key, $objectKey, $visibility);
            $report->copied++;
        }

        $asset = MediaAsset::query()->firstOrCreate([
            'disk' => $request->disk,
            'object_key' => $objectKey,
        ], [
            'display_name' => $name->displayName,
            'original_client_filename' => $name->originalClientFilename,
            'extension' => $name->extension,
            'mime_type' => $mime->mimeType,
            'mime_source' => $mime->source,
            'size' => $size,
            'visibility' => $visibility,
            'source' => MediaSource::Import,
            'import_source' => $request->importSource(),
            'uploaded_by' => $row === null ? null : $this->uploader($row, $request),
            'tenant_id' => $request->tenant,
        ]);

        $report->registered++;

        return $asset;
    }

    /**
     * What the disk says about an already-present asset, against what the row
     * records. Nothing is written: a drift is reported and the item is exited,
     * because a repair is a decision an operator makes and not one a run makes
     * on their behalf.
     *
     * An object that has gone is reported on its own and ends the check, since
     * every other comparison would then be against nothing and would read as
     * though the values had merely moved.
     *
     * The mime type is compared only where this run's ladder landed on the
     * same rung the row records. Which rung answers is decided by whether the
     * run was given `--sniff`, so a type read off a different rung is a
     * statement about the two runs rather than about the object, and reporting
     * it would call a sniffed row drifted the moment somebody checked without
     * paying for a read.
     *
     * The key named is the one that was read, which under `--copy` is the
     * destination rather than the legacy path an omission would name: the
     * drift is about the object the row points at, and that is the copy.
     */
    private function checkDrift(
        MediaAsset $asset,
        string $objectKey,
        ImportRequest $request,
        ImportReport $report,
        FilesystemAdapter $disk,
        ?int $element,
    ): void {
        if (! $disk->exists($objectKey)) {
            $report->drift($objectKey, ImportDrift::MissingObject, element: $element);

            return;
        }

        $size = $this->size($disk, $objectKey);

        if ($size === null) {
            $report->drift($objectKey, ImportDrift::UnreadableMetadata, element: $element);
        } elseif ($size !== $asset->size) {
            $report->drift($objectKey, ImportDrift::Size, (string) $asset->size, (string) $size, $element);
        }

        $mime = MimeLadder::resolve($disk, $objectKey, $asset->extension, $request->sniff);

        if ($mime->source === $asset->mime_source && $mime->mimeType !== $asset->mime_type) {
            $report->drift($objectKey, ImportDrift::MimeType, $asset->mime_type, $mime->mimeType, $element);
        }
    }

    /**
     * The asset already registered for this pair, if there is one. Identity is
     * the disk and object key, backed by a unique index, so this is the same
     * question `firstOrCreate` asks and the reason a re-run is a no-op.
     */
    private function existing(string $disk, string $objectKey): ?MediaAsset
    {
        return MediaAsset::query()
            ->where('disk', $disk)
            ->where('object_key', $objectKey)
            ->first();
    }

    /**
     * Where a copied object lands: under the configured media directory, keyed
     * by a digest of the pair it came from rather than by a fresh identifier,
     * so a second run resolves to the same key and adopts nothing twice. The
     * key stays opaque and carries no readable name.
     *
     * Deliberately not the ingest service's ULID generation, which would mint a
     * new key per run and copy every object again. See ADR 14.
     */
    private function copyKey(ImportRequest $request, string $sourceKey, ?string $extension): string
    {
        $digest = hash('sha256', $request->disk.':'.$sourceKey);
        $suffix = $extension === null ? '' : '.'.$extension;

        /** @var string $directory */
        $directory = config('media-library.directory', 'media');
        $directory = trim($directory, '/');

        return ($directory === '' ? '' : $directory.'/').substr($digest, 0, 32).$suffix;
    }

    /**
     * Copy without reading the whole object into memory, and without touching
     * the source: the legacy column keeps working afterwards, which is the
     * whole reason there is no move mode. The copy is written at the
     * visibility the row records, so the two never disagree.
     */
    private function copy(FilesystemAdapter $disk, string $sourceKey, string $destinationKey, Visibility $visibility): void
    {
        $stream = $disk->readStream($sourceKey);

        if (! is_resource($stream)) {
            return;
        }

        try {
            $disk->writeStream($destinationKey, $stream, ['visibility' => $visibility->value]);
        } finally {
            fclose($stream);
        }
    }

    /**
     * The size as the disk reports it. A disk that will not say is a reason to
     * skip the object, never a reason to store a zero.
     */
    private function size(FilesystemAdapter $disk, string $key): ?int
    {
        try {
            return $disk->size($key);
        } catch (UnableToRetrieveMetadata) {
            return null;
        }
    }

    /**
     * Provenance comes from the row that holds the path, or stays null. An
     * uploader is never fabricated: not the person running the command, and
     * not a stand-in account.
     */
    private function uploader(Model $row, ImportRequest $request): ?string
    {
        if ($request->uploader === null) {
            return null;
        }

        $uploader = $row->getAttribute($request->uploader);

        return $uploader === null || $uploader === '' ? null : (string) $uploader;
    }

    private function host(ColumnDiscovery $discovery, ImportRequest $request): Model
    {
        $named = $discovery->model;

        if (! class_exists($named)) {
            throw ImportRefused::unknownModel($named);
        }

        $model = new $named;

        $schema = $model->getConnection()->getSchemaBuilder();

        if (! $schema->hasColumn($model->getTable(), $discovery->column)) {
            throw ImportRefused::unknownColumn($named, $discovery->column);
        }

        // A mistyped uploader column reads exactly like an object nobody can
        // attribute, so it is caught here rather than silently producing a
        // library with no provenance at all.
        if ($request->uploader !== null && ! $schema->hasColumn($model->getTable(), $request->uploader)) {
            throw ImportRefused::unknownColumn($named, $request->uploader);
        }

        return $model;
    }

    private function diskIsConfigured(string $disk): bool
    {
        return config('filesystems.disks.'.$disk) !== null;
    }

    /**
     * What an omission with no path of its own is named by, since a report
     * that says only "empty" is not one anybody can act on.
     */
    private function rowLabel(Model $row): string
    {
        return $row->getMorphClass().'#'.((string) $row->getKey());
    }
}
