<?php

declare(strict_types=1);

namespace Lisowiecw\MediaLibrary\Forms\Components;

use Filament\Notifications\Notification;
use Illuminate\Support\Arr;
use Lisowiecw\MediaLibrary\Exceptions\IngestRefused;
use Lisowiecw\MediaLibrary\Filament\Notifications\RefusalNotice;
use Lisowiecw\MediaLibrary\Ingest\IngestRules;
use Lisowiecw\MediaLibrary\Ingest\IngestService;
use Lisowiecw\MediaLibrary\Ingest\Placement;
use Lisowiecw\MediaLibrary\Models\MediaAsset;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;

/**
 * What a Drop surface does with the files the browser staged on it: ingest as
 * many of them as the field can hold, say what it would not take, and hand
 * back the assets.
 *
 * It is one module for all three surfaces, because the gesture is one concept
 * whether it commits at once on the inline trigger and the Library tab body or
 * stages behind the Upload tab's confirm. Only the moment of asking differs,
 * and that belongs to whoever asks.
 *
 * It sits beside the picker rather than beside `IngestService`: the cardinality
 * of a field and a notification a person reads are properties of the gesture,
 * and the ingest floor knows about neither. What it returns is assets rather
 * than a selection, so the field is the only thing that writes the Picker
 * value, and the intake can be asked without a field at all.
 */
final readonly class DropIntake
{
    /**
     * @param  bool  $multiple  Whether the field holds more than one asset, which is what
     *                          makes a fumbled multi-file drop a first file rather than an error.
     * @param  int|null  $limit  How many assets the field may hold in total, null for no
     *                           ceiling. It is what a multiple field is measured against; a
     *                           single selection replaces rather than filling up, so its own
     *                           ceiling of one is never what decides.
     */
    public function __construct(
        private Placement $placement,
        private IngestRules $rules,
        private bool $multiple = false,
        private ?int $limit = null,
    ) {}

    /**
     * Ingest whatever was staged, and return the assets in the order they
     * arrived.
     *
     * The cap is on the gesture rather than on the list it leaves behind: what
     * the field has no room for is never ingested in the first place, so a
     * drop of five onto a field with room for two does not leave three orphan
     * assets in the library.
     *
     * @param  mixed  $staged  Whatever sits at the staging path, filtered here rather
     *                         than by each surface: a cleared path holds an empty array.
     * @param  int  $held  How many assets the field holds already.
     * @return list<MediaAsset>
     */
    public function take(mixed $staged, int $held = 0): array
    {
        $files = $this->filesIn($staged);

        if ($files === []) {
            return [];
        }

        // A fumbled drop on a cover image is not an error page: the first file
        // is what was meant, and the rest are named as ignored.
        if (! $this->multiple && count($files) > 1) {
            $this->warn(__('media-library::messages.picker.single_drop', ['count' => count($files)]));

            $files = [$files[0]];
        }

        $assets = [];

        foreach ($this->withinRoom($files, $held) as $file) {
            $asset = $this->ingest($file);

            if ($asset instanceof MediaAsset) {
                $assets[] = $asset;
            }
        }

        return $assets;
    }

    /**
     * The staged files, and nothing else that happens to sit at the path.
     *
     * @return list<TemporaryUploadedFile>
     */
    private function filesIn(mixed $staged): array
    {
        return array_values(array_filter(
            Arr::wrap($staged),
            fn (mixed $file): bool => $file instanceof TemporaryUploadedFile,
        ));
    }

    /**
     * As many of the staged files as the field still has room for, saying so
     * once when it has room for fewer.
     *
     * @param  list<TemporaryUploadedFile>  $files
     * @return list<TemporaryUploadedFile>
     */
    private function withinRoom(array $files, int $held): array
    {
        // A single selection replaces what is there, so the field has room for
        // the one file whatever it holds already: a cover image that is full
        // is exactly the one a person drops a new picture onto.
        if (! $this->multiple) {
            return $files;
        }

        if ($this->limit === null) {
            return $files;
        }

        $room = max(0, $this->limit - $held);

        if (count($files) <= $room) {
            return $files;
        }

        $this->warn(__('media-library::messages.picker.full', ['count' => $this->limit]));

        return array_slice($files, 0, $room);
    }

    /**
     * Ingest one file, or say why the ingest floor would not have it.
     *
     * A refusal is absorbed rather than rethrown for the same reason a
     * half-worked drop is: one refused file must not cost the person the rest
     * of the gesture. It is absorbed here rather than by each surface, because
     * a forgotten catch is silent and the file simply never appears.
     */
    private function ingest(TemporaryUploadedFile $file): ?MediaAsset
    {
        try {
            return app(IngestService::class)->ingest($file, $this->placement, $this->rules);
        } catch (IngestRefused $refusal) {
            $this->warn(RefusalNotice::text($refusal));

            return null;
        }
    }

    /**
     * Something the person should know about a gesture that half worked, a
     * refusal included. It is a notification rather than a validation error,
     * because nothing they did was invalid and there is nothing on the field
     * for them to correct: the file is simply not one the library takes.
     */
    private function warn(string $message): void
    {
        Notification::make()->warning()->title($message)->send();
    }
}
