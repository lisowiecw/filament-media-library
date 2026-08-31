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
 */
class LegacyImporter
{
    public function import(ImportRequest $request): ImportReport
    {
        $model = $this->host($request);

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

        $rows = $model->newQuery()
            ->whereNotNull($request->column)
            ->lazyById($request->chunk);

        foreach ($rows as $row) {
            $report->examined++;

            $this->adopt($row, $request, $report, $disk, $rules);
        }

        return $report;
    }

    /**
     * One legacy path: everything that can decline it happens before a row is
     * written, and the row itself is resolved rather than inserted, so a
     * re-run finds what the last one left and edits nothing.
     */
    private function adopt(
        Model $row,
        ImportRequest $request,
        ImportReport $report,
        FilesystemAdapter $disk,
        IngestRules $rules,
    ): void {
        $value = $row->getAttribute($request->column);

        if (! is_string($value) || trim($value) === '') {
            $report->omit($this->rowLabel($row), ImportOmission::EmptyValue);

            return;
        }

        $key = ltrim(trim($value), '/');
        $name = ReadableName::from($key);

        if ($rules->blocks($name->extension, null)) {
            $report->omit($key, ImportOmission::BlockedType, $name->extension);

            return;
        }

        // Identity is settled before anything is read from the disk: a re-run
        // that has already adopted this pair must cost one query and no object
        // reads at all, which is what makes `--sniff` affordable to repeat.
        $objectKey = $request->copy ? $this->copyKey($request, $key, $name->extension) : $key;

        if ($this->existing($request->disk, $objectKey) !== null) {
            $report->alreadyPresent++;

            return;
        }

        if (! $disk->exists($key)) {
            $report->omit($key, ImportOmission::MissingObject);

            return;
        }

        $mime = MimeLadder::resolve($disk, $key, $name->extension, $request->sniff);

        // The denylist is matched on the resolved type as well, so an
        // extension that says nothing cannot carry a blocked type past it.
        if ($rules->blocks($name->extension, $mime->mimeType)) {
            $report->omit($key, ImportOmission::BlockedType, $mime->mimeType ?? $name->extension);

            return;
        }

        $size = $this->size($disk, $key);

        if ($size === null) {
            $report->omit($key, ImportOmission::UnreadableMetadata);

            return;
        }

        if ($request->copy && ! $disk->missing($objectKey)) {
            $report->omit($key, ImportOmission::DestinationOccupied, $objectKey);

            return;
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

            return;
        }

        if ($request->copy) {
            $this->copy($disk, $key, $objectKey, $visibility);
            $report->copied++;
        }

        MediaAsset::query()->firstOrCreate([
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
            'uploaded_by' => $this->uploader($row, $request),
        ]);

        $report->registered++;
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

    private function host(ImportRequest $request): Model
    {
        if (! class_exists($request->model) || ! is_subclass_of($request->model, Model::class)) {
            throw ImportRefused::unknownModel($request->model);
        }

        $model = new $request->model;

        $schema = $model->getConnection()->getSchemaBuilder();

        if (! $schema->hasColumn($model->getTable(), $request->column)) {
            throw ImportRefused::unknownColumn($request->model, $request->column);
        }

        // A mistyped uploader column reads exactly like an object nobody can
        // attribute, so it is caught here rather than silently producing a
        // library with no provenance at all.
        if ($request->uploader !== null && ! $schema->hasColumn($model->getTable(), $request->uploader)) {
            throw ImportRefused::unknownColumn($request->model, $request->uploader);
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
