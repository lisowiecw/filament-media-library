<?php

declare(strict_types=1);

namespace Lisowiecw\MediaLibrary\Commands;

use Illuminate\Console\Command;
use Illuminate\Contracts\Console\Isolatable;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Facades\Storage;
use Lisowiecw\MediaLibrary\Enums\MimeSource;
use Lisowiecw\MediaLibrary\Import\MimeLadder;
use Lisowiecw\MediaLibrary\Models\MediaAsset;

/**
 * The second step of a migration: run the mime ladder again over the rows a
 * first pass could only guess at, and pay for the bytes this time.
 *
 * It exists because an import adopts objects rather than reading them, so a
 * freshly imported library is full of types derived from a filename, and an
 * extension-derived type is never rendered in place. This is where an operator
 * decides to buy the confidence back, per rung and out loud: the run selects
 * on `--from` and reads no bytes at all unless `--sniff` says to, because a
 * sniff is one full read of every object it touches.
 *
 * Nothing here ever happens on the Delivery route. Resolution is a write and,
 * with `--sniff`, a fetch, and a read path performs neither.
 */
class ResolveMimeTypes extends Command implements Isolatable
{
    /**
     * How many rows are read at once, so a large library is walked rather than
     * loaded.
     */
    private const int CHUNK = 200;

    protected $signature = 'media:resolve-mimes
        {--from=extension : The rung to re-resolve: header, sniffed, extension or unknown}
        {--sniff : Read the bytes, at one full read per object. Never implicit}
        {--dry-run : Report what would be rewritten and write nothing}';

    protected $description = 'Re-run the mime ladder over the assets on one rung, upgrading the type and the rung together.';

    public function handle(): int
    {
        $from = $this->rung();

        if ($from === null) {
            return self::FAILURE;
        }

        $sniff = (bool) $this->option('sniff');
        $dryRun = (bool) $this->option('dry-run');
        $rewritten = 0;

        $assets = MediaAsset::query()
            ->where('mime_source', $from->value)
            ->orderBy('id')
            ->lazyById(self::CHUNK);

        foreach ($assets as $asset) {
            $resolved = MimeLadder::resolve(
                $this->disk($asset->disk),
                $asset->object_key,
                $asset->extension,
                sniff: $sniff,
            );

            if (! $this->improves($resolved, $asset)) {
                continue;
            }

            $rewritten++;

            $this->components->twoColumnDetail(
                $asset->ulid.' '.$asset->display_name,
                $asset->mime_source->value.' -> '.$resolved->source->value.' '.($resolved->mimeType ?? 'no type'),
            );

            if ($dryRun) {
                continue;
            }

            // The pair is written in one update, never one column at a time,
            // so a row can never claim a rung it did not come from.
            $asset->update([
                'mime_type' => $resolved->mimeType,
                'mime_source' => $resolved->source,
            ]);
        }

        $this->components->info($dryRun
            ? $rewritten.' asset(s) would be rewritten.'
            : $rewritten.' asset(s) rewritten.');

        return self::SUCCESS;
    }

    /**
     * Whether this answer is worth writing down. A re-resolution only ever
     * raises what a row claims: a weaker rung is the disk having less to say
     * today than it did at import, which is not news about the file.
     */
    private function improves(MimeLadder $resolved, MediaAsset $asset): bool
    {
        if ($resolved->source->outranks($asset->mime_source)) {
            return true;
        }

        return $resolved->source === $asset->mime_source
            && $resolved->mimeType !== null
            && $resolved->mimeType !== $asset->mime_type;
    }

    /**
     * The rung this run selects on, or null when the option names something
     * the ladder does not have.
     */
    private function rung(): ?MimeSource
    {
        /** @var string $named */
        $named = $this->option('from');

        $rung = MimeSource::tryFrom($named);

        if ($rung === null) {
            $this->components->error('Unknown mime source "'.$named.'". Known sources: '
                .implode(', ', array_column(MimeSource::cases(), 'value')).'.');
        }

        return $rung;
    }

    private function disk(string $name): FilesystemAdapter
    {
        /** @var FilesystemAdapter $disk */
        $disk = Storage::disk($name);

        return $disk;
    }
}
