<?php

declare(strict_types=1);

namespace Lisowiecw\MediaLibrary\Commands;

use Illuminate\Console\Command;
use Illuminate\Contracts\Console\Isolatable;
use Illuminate\Database\Eloquent\Model;
use Lisowiecw\MediaLibrary\Enums\Visibility;
use Lisowiecw\MediaLibrary\Exceptions\ImportRefused;
use Lisowiecw\MediaLibrary\Exceptions\PlacementMisconfigured;
use Lisowiecw\MediaLibrary\Import\ImportOmission;
use Lisowiecw\MediaLibrary\Import\ImportReport;
use Lisowiecw\MediaLibrary\Import\ImportRequest;
use Lisowiecw\MediaLibrary\Import\LegacyImporter;

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
        {--model= : The host model whose column holds the legacy paths}
        {--column= : The column on that model}
        {--disk= : The disk those paths resolve against. Required: an import never guesses one}
        {--field= : The field context the paths belong to}
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
        /** @var string|null $model */
        $model = $this->option('model');

        /** @var string|null $column */
        $column = $this->option('column');

        /** @var string|null $disk */
        $disk = $this->option('disk');

        if ($model === null || $column === null || $disk === null) {
            $this->components->error('Name the host model, its column and the disk: --model, --column and --disk are all required.');

            return null;
        }

        /** @var string|null $field */
        $field = $this->option('field');

        /** @var string|null $uploader */
        $uploader = $this->option('uploader');

        /** @var string $chunk */
        $chunk = $this->option('chunk');

        /** @var class-string<Model> $model */
        return new ImportRequest(
            model: $model,
            column: $column,
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
            ['model', 'column', 'disk'],
        );

        return implode(':', $named);
    }

    /**
     * The console half of the report: every omission by path, then the counts.
     * Nothing adopted is listed, because the library already lists it.
     */
    private function summarize(ImportReport $report): void
    {
        foreach ($report->omissions as $omission) {
            $reason = ImportOmission::from($omission['reason'])->label();

            $this->components->twoColumnDetail(
                $omission['path'],
                $omission['detail'] === null ? $reason : $reason.' ('.$omission['detail'].')',
            );
        }

        $this->components->info(sprintf(
            '%d row(s) examined, %d %s, %d already present, %d omitted.',
            $report->examined,
            $report->registered,
            $report->request->dryRun ? 'would be adopted' : 'adopted',
            $report->alreadyPresent,
            count($report->omissions),
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
