<?php

declare(strict_types=1);

namespace Lisowiecw\MediaLibrary\Forms\Components;

use Closure;
use Filament\Actions\Action;
use Filament\Forms\Components\Concerns\CanLimitItemsLength;
use Filament\Forms\Components\Field;
use Filament\Forms\Components\FileUpload;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Support\Components\Attributes\ExposedLivewireMethod;
use Filament\Support\Enums\Width;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;
use Lisowiecw\MediaLibrary\Attachments\AttachmentReconciler;
use Lisowiecw\MediaLibrary\Derivatives\Derivatives;
use Lisowiecw\MediaLibrary\Ingest\IngestRules;
use Lisowiecw\MediaLibrary\Ingest\IngestService;
use Lisowiecw\MediaLibrary\Ingest\Placement;
use Lisowiecw\MediaLibrary\Library\OfferScope;
use Lisowiecw\MediaLibrary\Models\MediaAsset;
use Lisowiecw\MediaLibrary\Models\MediaAttachment;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;

/**
 * The single field component that renders a host model's attachments for one
 * field context and opens the library modal.
 *
 * The field is virtual: the host table has no media column, so the field is
 * never dehydrated into the host's attributes, and the Attachment rows are the
 * only copy of the fact. Its state is always an ordered list of bare asset
 * ids, whatever the cardinality, and the position an id sits at in that list
 * is the position it is attached at.
 *
 * Nothing is written until the host record exists: the reconcile runs as a
 * relationship save, after the record is persisted, so an abandoned create
 * form leaves no host-less rows behind.
 */
class MediaPicker extends Field
{
    use CanLimitItemsLength;

    protected string $view = 'media-library::forms.components.media-picker';

    /**
     * @var array<string> | Closure | null
     */
    protected array|Closure|null $acceptedFileTypes = null;

    protected string|Closure|null $disk = null;

    protected string|Closure|null $directory = null;

    protected string|Closure|null $visibility = null;

    protected int|Closure|null $maxSize = null;

    protected bool|Closure|null $isMultiple = null;

    protected bool|Closure $isReorderable = false;

    protected bool|Closure $isDroppable = true;

    protected ?Closure $scopeLibrary = null;

    protected ?Closure $thumbnailUsing = null;

    protected Width|string|Closure|null $modalWidth = null;

    protected string|Closure|null $defaultTab = null;

    protected function setUp(): void
    {
        parent::setUp();

        $this->default([]);

        // Virtual: the host has no column to write to, but the id list is
        // still validated, so cardinality and availability are enforced.
        $this->dehydrated(false);

        $this->afterStateHydrated(static function (MediaPicker $component, mixed $state): void {
            $component->state($component->normalisePickerValue($state));
        });

        $this->loadStateFromRelationshipsUsing(static function (MediaPicker $component): void {
            $component->state($component->getAttachedIds());
        });

        $this->saveRelationshipsUsing(static function (MediaPicker $component): void {
            $component->reconcile();
        });

        $this->rule(static fn (MediaPicker $component): Closure => $component->getAvailabilityRule());

        $this->registerActions([
            fn (MediaPicker $component): Action => $component->getLibraryAction(),
        ]);
    }

    /**
     * @param  array<string> | Closure | null  $types
     */
    public function acceptedFileTypes(array|Closure|null $types): static
    {
        $this->acceptedFileTypes = $types;

        return $this;
    }

    public function disk(string|Closure|null $disk): static
    {
        $this->disk = $disk;

        return $this;
    }

    public function directory(string|Closure|null $directory): static
    {
        $this->directory = $directory;

        return $this;
    }

    public function visibility(string|Closure|null $visibility): static
    {
        $this->visibility = $visibility;

        return $this;
    }

    /**
     * The field's own upload ceiling, in kilobytes. A field may move it in
     * either direction, since the package limit is a default rather than a
     * floor.
     */
    public function maxSize(int|Closure|null $size): static
    {
        $this->maxSize = $size;

        return $this;
    }

    /**
     * Whether this field holds more than one asset at a time. Saying so
     * explicitly is the field author's way in; a picker that was never told is
     * a single selection, which is what a cover image is.
     */
    public function multiple(bool|Closure $condition = true): static
    {
        $this->isMultiple = $condition;

        return $this;
    }

    /**
     * Let the attached items be put in a different order, by dragging and by
     * the arrow controls beside each one. Order is the Picker value's order,
     * so reordering is nothing but a rewrite of the list.
     */
    public function reorderable(bool|Closure $condition = true): static
    {
        $this->isReorderable = $condition;

        return $this;
    }

    /**
     * Whether this field accepts a dropped or chosen file at all. Turning it
     * off makes the field reuse-only: no drop surface anywhere, and no Upload
     * tab in the modal.
     */
    public function droppable(bool|Closure $condition = true): static
    {
        $this->isDroppable = $condition;

        return $this;
    }

    /**
     * A narrowing of what this field offers, for a topology the package's own
     * rules cannot name. It can only narrow: see OfferScope.
     */
    public function scopeLibrary(?Closure $callback): static
    {
        $this->scopeLibrary = $callback;

        return $this;
    }

    /**
     * How this field resolves an asset's preview image. The default is the
     * asset's own URL, which is what a grid card paints when View allows it.
     */
    public function thumbnailUsing(?Closure $callback): static
    {
        $this->thumbnailUsing = $callback;

        return $this;
    }

    public function modalWidth(Width|string|Closure|null $width): static
    {
        $this->modalWidth = $width;

        return $this;
    }

    /**
     * Which tab the modal opens on, `library` or `upload`. A field that is not
     * droppable has no Upload tab, so it opens on the Library tab whatever it
     * was told.
     */
    public function defaultTab(string|Closure|null $tab): static
    {
        $this->defaultTab = $tab;

        return $this;
    }

    public function isReorderable(): bool
    {
        return $this->isMultiple() && (bool) $this->evaluate($this->isReorderable);
    }

    public function isDroppable(): bool
    {
        return (bool) $this->evaluate($this->isDroppable);
    }

    public function getModalWidth(): Width|string|null
    {
        /** @var Width|string|null $width */
        $width = $this->evaluate($this->modalWidth);

        return $width;
    }

    /**
     * The 1-based tab index Filament wants, from the tab name a field author
     * gave. It is read off the tabs that were actually built, so a field that
     * asked for a tab it does not have opens on the first one rather than on
     * whatever happens to sit at that position.
     */
    public function getDefaultTabIndex(): int
    {
        /** @var string|null $tab */
        $tab = $this->evaluate($this->defaultTab);

        $position = array_search($tab, array_keys($this->getLibraryTabs()), strict: true);

        return $position === false ? 1 : $position + 1;
    }

    /**
     * The preview URL for one asset under this field's own rule, which the
     * Library grid asks for through the closure this field hands it. A field
     * that was never told resolves through the derivative pipeline, which
     * answers with a rendering, with the original where the original is small
     * enough to be its own, and with null while there is nothing to paint yet.
     */
    public function getThumbnailUrl(MediaAsset $asset): ?string
    {
        if ($this->thumbnailUsing === null) {
            return Derivatives::thumbnailUrl($asset);
        }

        /** @var string|null $url */
        $url = $this->evaluate($this->thumbnailUsing, ['asset' => $asset], [MediaAsset::class => $asset]);

        return $url;
    }

    /**
     * How many assets this field may hold. A single selection is one whatever
     * `maxItems` says, so the two never disagree.
     */
    public function getSelectionLimit(): ?int
    {
        return $this->isMultiple() ? $this->getMaxItems() : 1;
    }

    /**
     * @return list<string>|null
     */
    public function getAcceptedFileTypes(): ?array
    {
        /** @var array<string>|null $types */
        $types = $this->evaluate($this->acceptedFileTypes);

        return $types === null ? null : array_values($types);
    }

    public function getMaxSize(): ?int
    {
        return $this->evaluate($this->maxSize);
    }

    /**
     * Where this field's uploads land and with what visibility. Field
     * configuration wins over package configuration; attaching an existing
     * asset never re-applies it.
     */
    public function getPlacement(): Placement
    {
        return Placement::resolve(
            disk: $this->evaluate($this->disk),
            directory: $this->evaluate($this->directory),
            visibility: $this->evaluate($this->visibility),
            field: $this->getName(),
        );
    }

    public function getIngestRules(): IngestRules
    {
        return IngestRules::resolve(
            maxUploadSize: $this->getMaxSize(),
            acceptedTypes: $this->getAcceptedFileTypes(),
        );
    }

    /**
     * What the field says about its own placement, so a person is never
     * surprised by an image that turns out to be private. It is the field's
     * default helper text and the modal's drop banner alike.
     */
    public function getPlacementSummary(): string
    {
        $placement = $this->getPlacement();

        return __('media-library::messages.picker.placement', [
            'disk' => $placement->disk,
            'directory' => $placement->directory === '' ? '/' : $placement->directory,
            'visibility' => __('media-library::messages.visibility.'.$placement->visibility->value),
        ]);
    }

    /**
     * The ids currently attached to this host in this field context, in
     * attachment order. The read is deliberately plain: no tenant scope and no
     * policy check, because reading a host's own field is not a request for
     * content, and ids alone are not content.
     *
     * @return list<int>
     */
    public function getAttachedIds(): array
    {
        $record = $this->getRecord();

        if (! ($record instanceof Model && $record->exists)) {
            return [];
        }

        /** @var list<int> $ids */
        $ids = MediaAttachment::query()
            ->forField($record, $this->getName())
            ->orderBy('order')
            ->pluck('media_asset_id')
            ->map(fn (int|string $id): int => (int) $id)
            ->all();

        return $ids;
    }

    /**
     * The Picker value as the field promises it: a list of bare ids, in order,
     * in both directions and whatever the cardinality.
     *
     * @return list<int>
     */
    public function normalisePickerValue(mixed $state): array
    {
        return array_values(array_unique(array_map(
            intval(...),
            array_filter(
                Arr::wrap($state),
                fn (mixed $id): bool => is_int($id) || (is_string($id) && ctype_digit($id)),
            ),
        )));
    }

    /**
     * @return list<int>
     */
    public function getPickerValue(): array
    {
        return $this->normalisePickerValue($this->getState());
    }

    /**
     * @return Collection<int, MediaAsset>
     */
    public function getSelectedAssets(): Collection
    {
        return MediaAsset::inNamedOrder($this->getPickerValue());
    }

    /**
     * Whether this field holds more than one asset at a time. An explicit
     * `->multiple()` settles it; otherwise the field's own cardinality does,
     * so `->maxItems(12)` alone is still a gallery.
     */
    public function isMultiple(): bool
    {
        $explicit = $this->evaluate($this->isMultiple);

        if ($explicit !== null) {
            return (bool) $explicit;
        }

        return ($this->getMaxItems() ?? 1) > 1 || ($this->getMinItems() ?? 0) > 1;
    }

    /**
     * Take a selection into the Picker value, in the order it was picked.
     * Single selection replaces what is there; the previous asset is left
     * alone, since a replacement is not a destruction.
     *
     * An id already in the list is not added a second time: one asset sits at
     * one position in a field context, so a re-pick is a no-op rather than a
     * duplicate row the reconcile would have to collapse later.
     *
     * @param  list<int>  $ids
     */
    public function select(array $ids): void
    {
        $ids = $this->normalisePickerValue($ids);

        if ($ids === []) {
            return;
        }

        if (! $this->isMultiple()) {
            $this->putPickerValue(array_slice($ids, -1));

            return;
        }

        $selection = $this->normalisePickerValue([...$this->getPickerValue(), ...$ids]);
        $limit = $this->getMaxItems();

        // What is attached already wins over what is arriving: dropping five
        // files into a field with room for two must not silently evict the two
        // that were there.
        if ($limit !== null && count($selection) > $limit) {
            $selection = array_slice($selection, 0, $limit);

            $this->warn(__('media-library::messages.picker.full', ['count' => $limit]));
        }

        $this->putPickerValue($selection);
    }

    /**
     * Detach one item from the Picker value. Nothing is written and nothing is
     * deleted: the row goes on the next save's diff, and the asset is
     * untouched either way.
     */
    #[ExposedLivewireMethod]
    public function removeItem(int $id): void
    {
        $this->putPickerValue(array_values(array_filter(
            $this->getPickerValue(),
            fn (int $selected): bool => $selected !== $id,
        )));
    }

    /**
     * Put the items in a new order, as a drag ends. The order given is only
     * honoured when it is a rearrangement of what is already there, so a stale
     * or tampered list can neither attach nor detach anything.
     *
     * @param  list<int|string>  $ids
     */
    #[ExposedLivewireMethod]
    public function reorderItems(array $ids): void
    {
        if (! $this->isReorderable()) {
            return;
        }

        $reordered = $this->normalisePickerValue($ids);
        $current = $this->getPickerValue();

        if (count($reordered) !== count($current) || array_diff($reordered, $current) !== []) {
            return;
        }

        $this->putPickerValue($reordered);
    }

    /**
     * The same rearrangement, one step at a time, for the arrow controls that
     * make reordering work from the keyboard. A step off either end is a
     * no-op rather than a wrap.
     */
    #[ExposedLivewireMethod]
    public function moveItem(int $id, int $step): void
    {
        $ids = $this->getPickerValue();
        $from = array_search($id, $ids, strict: true);

        if ($from === false) {
            return;
        }

        $to = $from + $step;

        if ($to < 0 || $to >= count($ids)) {
            return;
        }

        [$ids[$from], $ids[$to]] = [$ids[$to], $ids[$from]];

        $this->reorderItems(array_values($ids));
    }

    /**
     * Where a dropped file is staged while the browser uploads it: beside the
     * field's own state rather than inside it, because the Picker value is a
     * list of ids and nothing else, in both directions.
     */
    public function getDropStatePath(): string
    {
        return $this->getStatePath().'_dropped';
    }

    /**
     * Ingest whatever the browser has just staged at the drop path. This is
     * the drop's whole commit: a drop attaches at once, unlike a click in the
     * Library tab, which waits for the modal's confirm.
     */
    #[ExposedLivewireMethod]
    public function dropped(): void
    {
        $livewire = $this->getLivewire();
        $path = $this->getDropStatePath();

        /** @var list<TemporaryUploadedFile> $files */
        $files = array_values(array_filter(
            Arr::wrap(data_get($livewire, $path)),
            fn (mixed $file): bool => $file instanceof TemporaryUploadedFile,
        ));

        data_set($livewire, $path, []);

        if ($files === [] || ! $this->isDroppable()) {
            return;
        }

        // A fumbled drop on a cover image is not an error page: the first file
        // is what was meant, and the rest are named as ignored.
        if (! $this->isMultiple() && count($files) > 1) {
            $this->warn(__('media-library::messages.picker.single_drop', ['count' => count($files)]));

            $files = [$files[0]];
        }

        // The cap is on the gesture, not just on the list it leaves behind:
        // what the field has no room for is never ingested in the first place.
        $files = $this->withinRoom($files);

        foreach ($files as $file) {
            $this->upload($file);
        }
    }

    /**
     * As many of the dropped files as the field still has room for, saying so
     * once when it has room for fewer.
     *
     * @param  list<TemporaryUploadedFile>  $files
     * @return list<TemporaryUploadedFile>
     */
    private function withinRoom(array $files): array
    {
        $limit = $this->getSelectionLimit();

        if ($limit === null) {
            return $files;
        }

        $room = max(0, $limit - count($this->getPickerValue()));

        if (count($files) <= $room) {
            return $files;
        }

        $this->warn(__('media-library::messages.picker.full', ['count' => $limit]));

        return array_slice($files, 0, $room);
    }

    /**
     * Ingest one uploaded file with this field's resolved Placement and select
     * it.
     */
    public function upload(TemporaryUploadedFile $file): MediaAsset
    {
        $asset = app(IngestService::class)->ingest($file, $this->getPlacement(), $this->getIngestRules());

        $this->select([$asset->id]);

        return $asset;
    }

    /**
     * @param  list<int>  $ids
     */
    private function putPickerValue(array $ids): void
    {
        $this->state($this->normalisePickerValue($ids));

        $this->callAfterStateUpdated();
    }

    /**
     * Something the person should know about a gesture that half worked. It is
     * a notification rather than a validation error, because nothing they did
     * was invalid and there is nothing for them to correct.
     */
    private function warn(string $message): void
    {
        Notification::make()->warning()->title($message)->send();
    }

    /**
     * What the Library tab offers this field: an accepted-type match, plus
     * public or this field uploading private, minus the blocked types. The
     * field's placement disk and directory say nothing about it.
     */
    public function getOfferScope(): OfferScope
    {
        return new OfferScope($this->getIngestRules(), $this->getPlacement()->visibility, $this->scopeLibrary);
    }

    /**
     * The one library modal: browse what the library already holds, or upload
     * something new, and confirm to attach the selection in the order it was
     * picked.
     */
    public function getLibraryAction(): Action
    {
        return Action::make('library')
            ->label(__('media-library::messages.picker.actions.library.label'))
            ->modalHeading($this->getLabel())
            ->modalDescription(fn (): string => $this->getPlacementSummary())
            ->modalWidth($this->getModalWidth())
            ->modalSubmitActionLabel(__('media-library::messages.picker.actions.library.submit'))
            ->schema([
                Tabs::make()
                    ->activeTab(fn (): int => $this->getDefaultTabIndex())
                    ->tabs(array_values($this->getLibraryTabs())),
            ])
            ->action(function (array $data): void {
                /** @var array<string, mixed> $library */
                $library = $data['library'] ?? [];

                /** @var list<int> $selection */
                $selection = is_array($library['selection'] ?? null) ? $library['selection'] : [];

                $this->select($selection);

                foreach (Arr::wrap($data['file'] ?? []) as $file) {
                    if ($file instanceof TemporaryUploadedFile) {
                        $this->upload($file);
                    }
                }
            });
    }

    /**
     * The modal's tabs. A field that is not droppable has no Upload tab at
     * all, rather than one that refuses: the surface itself is the statement
     * that this field is reuse-only.
     *
     * @return array<string, Tab> keyed by the name `defaultTab()` uses
     */
    protected function getLibraryTabs(): array
    {
        $tabs = [
            'library' => Tab::make(__('media-library::messages.picker.tabs.library'))
                ->schema([
                    LibraryGrid::make('library')
                        ->offerScope(fn (): OfferScope => $this->getOfferScope())
                        ->thumbnailUsing(fn (MediaAsset $asset): ?string => $this->getThumbnailUrl($asset))
                        ->selectionLimit(fn (): ?int => $this->getSelectionLimit())
                        ->dropTargetKey(fn (): ?string => $this->isDroppable() ? $this->getKey() : null)
                        ->dropStatePath(fn (): string => $this->getDropStatePath()),
                ]),
        ];

        if (! $this->isDroppable()) {
            return $tabs;
        }

        $rules = $this->getIngestRules();

        $tabs['upload'] = Tab::make(__('media-library::messages.picker.tabs.upload'))
            ->schema([
                FileUpload::make('file')
                    ->hiddenLabel()
                    ->storeFiles(false)
                    ->multiple($this->isMultiple())
                    ->when(
                        filled($accepted = $rules->acceptedTypes),
                        fn (FileUpload $upload): FileUpload => $upload->acceptedFileTypes($accepted ?? []),
                    )
                    ->maxSize($rules->maxUploadSize),
            ]);

        return $tabs;
    }

    /**
     * The reconcile the host's save runs, once the record exists.
     */
    public function reconcile(): void
    {
        $record = $this->getRecord();

        if (! $record instanceof Model || ! $record->exists) {
            return;
        }

        app(AttachmentReconciler::class)->reconcile($record, $this->getName(), $this->getPickerValue());
    }

    /**
     * An id the viewer cannot have rejects the whole save rather than being
     * quietly dropped, and the message names the field alone: naming the id
     * back would confirm that an asset the viewer cannot reach exists.
     */
    public function getAvailabilityRule(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail): void {
            $ids = $this->normalisePickerValue($value);

            if ($ids === []) {
                return;
            }

            $available = MediaAsset::query()->whereIn('id', $ids)->count();

            if ($available !== count($ids)) {
                $label = $this->getLabel();

                $fail(__('media-library::messages.picker.unavailable', [
                    'field' => $label instanceof Htmlable ? $label->toHtml() : $label,
                ]));
            }
        };
    }
}
