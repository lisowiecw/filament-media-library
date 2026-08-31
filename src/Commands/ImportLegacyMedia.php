<?php

declare(strict_types=1);

namespace Lisowiecw\MediaLibrary\Commands;

use Illuminate\Console\Command;
use Illuminate\Contracts\Console\Isolatable;
use Illuminate\Database\Eloquent\Model;
use Lisowiecw\MediaLibrary\Enums\Visibility;
use Lisowiecw\MediaLibrary\Exceptions\ImportRefused;
use Lisowiecw\MediaLibrary\Exceptions\PlacementMisconfigured;
use Lisowiecw\MediaLibrary\Import\Cardinality;
use Lisowiecw\MediaLibrary\Import\ColumnDiscovery;
use Lisowiecw\MediaLibrary\Import\DiscoverySource;
use Lisowiecw\MediaLibrary\Import\DiskTraversal;
use Lisowiecw\MediaLibrary\Import\ImportOmission;
use Lisowiecw\MediaLibrary\Import\ImportReport;
use Lisowiecw\MediaLibrary\Import\ImportRequest;
use Lisowiecw\MediaLibrary\Import\LegacyImporter;
use Lisowiecw\MediaLibrary\Import\TraversalDiscovery;

/**
 * Adopts the uploads an application already has, by reading the column that
 * holds their paths.
 *
 * The default mode registers each object exactly where it is, so every URL
 * that worked before the run still works after it. Run it with `--dry-run`
 * first: the report is the same either way, and only the writing differs.
 */
class ImportLegacyMedia extends Command implements Isolatable
{
    protected $signature = 'media:import
        {--source=column : Where paths are discovered: the declared column, or a degraded walk of the disk}
        {--model= : The host model whose column holds the legacy paths}
        {--column= : The column on that model}
        {--cardinality=single : Whether that column holds one path or a list of them. Never inferred}
        {--prefix= : The prefix to walk under --source=disk. Required there, and meaningless elsewhere}
        {--disk= : The disk those paths resolve against. Required: an import never guesses one}
        {--field= : The field context the paths are attached in}
        {--uploader= : A column on the host row to record as the uploader, else none is recorded}
        {--visibility= : Record every adopted object as public or private, rather than resolving it}
        {--copy : Copy the bytes to a fresh key under the media directory instead of adopting in place}
        {--sniff : Read the bytes to resolve the mime type, at one full read per object}
        {--chunk=500 : Host rows read per batch}
        {--report= : Where to write the machine-readable report}
        {--dry-run : Report what would happen and write nothing}';

    protected $description = 'Adopt an application\'s existing uploads as Media Assets, in place.';

    public function handle(LegacyImporter $importer): int
    {
        try {
            $request = $this->request();

            if ($request === null) {
                return self::FAILURE;
            }

            $report = $importer->import($request);
        } catch (ImportRefused|PlacementMisconfigured $refusal) {
            $this->components->error($refusal->getMessage());

            // A refusal partway through still leaves rows adopted, so what the
            // run managed is reported exactly as a successful run reports it.
            if ($refusal instanceof ImportRefused && $refusal->report !== null) {
                $this->summarize($refusal->report);
                $this->write($refusal->report);
            }

            return self::FAILURE;
        }

        $this->summarize($report);
        $this->write($report);

        return self::SUCCESS;
    }

    /**
     * What this run was asked to do, or null when the options do not describe
     * a run at all. The disk is the one option with no default anywhere: the
     * same legacy path is meaningful on several disks, and adopting against
     * the wrong one produces a row pointing at somebody else's bytes.
     */
    private function request(): ?ImportRequest
    {
        $source = $this->source();

        /** @var string|null $disk */
        $disk = $this->option('disk');

        if ($disk === null) {
            $this->components->error('Name the disk the legacy paths resolve against: --disk is required.');

            return null;
        }

        /** @var string|null $field */
        $field = $this->option('field');

        /** @var string|null $uploader */
        $uploader = $this->option('uploader');

        /** @var string $chunk */
        $chunk = $this->option('chunk');

        if ($source === DiscoverySource::Disk) {
            return $this->traversalRequest($disk, $field, $uploader);
        }

        /** @var string|null $model */
        $model = $this->option('model');

        /** @var string|null $column */
        $column = $this->option('column');

        if ($model === null || $column === null) {
            $this->components->error('Name the host model and its column: --model and --column are both required.');

            return null;
        }

        /** @var class-string<Model> $model */
        return new ImportRequest(
            discovery: new ColumnDiscovery($model, $column, $this->cardinality()),
            disk: $disk,
            field: $field,
            uploader: $uploader,
            visibility: $this->visibility(),
            copy: (bool) $this->option('copy'),
            sniff: (bool) $this->option('sniff'),
            dryRun: (bool) $this->option('dry-run'),
            chunk: max((int) $chunk, 1),
        );
    }

    /**
     * A traversal run, which knows a key and nothing else. Everything only a
     * host row can answer is refused here rather than ignored: a run that
     * accepted `--field` and then attached nothing would be the option going
     * back to being a label, which is the thing it is not.
     */
    private function traversalRequest(string $disk, ?string $field, ?string $uploader): ImportRequest
    {
        /** @var string $chunk */
        $chunk = $this->option('chunk');

        /** @var string|null $prefix */
        $prefix = $this->option('prefix');

        if ($prefix === null || DiskTraversal::normalise($prefix) === '') {
            throw ImportRefused::prefixRequired();
        }

        if ($field !== null) {
            throw ImportRefused::unavailableInTraversal('field');
        }

        if ($uploader !== null) {
            throw ImportRefused::unavailableInTraversal('uploader');
        }

        return new ImportRequest(
            discovery: new TraversalDiscovery(DiskTraversal::normalise($prefix)),
            disk: $disk,
            visibility: $this->visibility(),
            copy: (bool) $this->option('copy'),
            sniff: (bool) $this->option('sniff'),
            dryRun: (bool) $this->option('dry-run'),
            chunk: max((int) $chunk, 1),
        );
    }

    /**
     * Where this run discovers paths. Traversal is spelled out rather than
     * fallen into: an operator reaches it by asking for it.
     */
    private function source(): DiscoverySource
    {
        /** @var string $named */
        $named = $this->option('source');

        return DiscoverySource::tryFrom($named) ?? throw ImportRefused::unknownSource($named);
    }

    /**
     * How many paths one column holds, as declared. Nothing here looks at a
     * value to decide: a column read the wrong way ends the run instead.
     */
    private function cardinality(): Cardinality
    {
        /** @var string $named */
        $named = $this->option('cardinality');

        return Cardinality::tryFrom($named) ?? throw ImportRefused::unknownCardinality($named);
    }

    /**
     * The asserted visibility, or null where the run resolves it per object.
     */
    private function visibility(): ?Visibility
    {
        /** @var string|null $named */
        $named = $this->option('visibility');

        if ($named === null) {
            return null;
        }

        return Visibility::tryFrom($named) ?? throw ImportRefused::unknownVisibility($named);
    }

    /**
     * Two imports of the same model and disk must not interleave, since both
     * would resolve the same object keys and race on creating their rows.
     * Distinct sources run concurrently as before.
     */
    public function isolatableId(): string
    {
        $named = array_map(
            fn (string $option): string => is_string($value = $this->option($option)) ? $value : '',
            ['source', 'model', 'column', 'prefix', 'disk'],
        );

        return implode(':', $named);
    }

    /**
     * The console half of the report: every omission and skip by path, then the
     * counts. Nothing adopted is listed, because the library already lists it.
     */
    private function summarize(ImportReport $report): void
    {
        foreach ($report->omissions as $omission) {
            $reason = ImportOmission::from($omission['reason'])->label();
            $detail = $omission['detail'] === null ? $reason : $reason.' ('.$omission['detail'].')';

            // A skipped element names its index, because the row it came from
            // was otherwise adopted and a reader has to know which part of it
            // was not.
            $this->components->twoColumnDetail(
                $omission['element'] === null ? $omission['path'] : $omission['path'].' ['.$omission['element'].']',
                $detail,
            );
        }

        $this->components->info(sprintf(
            '%d row(s) examined, %d %s, %d attached, %d already present, %d row(s) omitted, %d element(s) skipped.',
            $report->examined,
            $report->registered,
            $report->request->dryRun ? 'would be adopted' : 'adopted',
            $report->attached,
            $report->alreadyPresent,
            $report->omittedRows(),
            $report->skippedElements(),
        ));
    }

    /**
     * The machine-readable half, so two runs can be diffed. Written on a dry
     * run as well: the point of a dry run is to read this before the real one.
     */
    private function write(ImportReport $report): void
    {
        /** @var string|null $named */
        $named = $this->option('report');

        $path = $named ?? ImportReport::defaultPath();
        $directory = dirname($path);

        if (! is_dir($directory) && ! mkdir($directory, recursive: true) && ! is_dir($directory)) {
            $this->components->error('The report could not be written to '.$path.'.');

            return;
        }

        $written = file_put_contents($path, json_encode($report->toArray(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        // Said rather than swallowed: a run whose report never landed is one
        // nobody can check afterwards, which is most of the point of it.
        if ($written === false) {
            $this->components->error('The report could not be written to '.$path.'.');

            return;
        }

        $this->components->twoColumnDetail('Report', $path);
    }
}
