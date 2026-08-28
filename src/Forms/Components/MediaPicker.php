<?php

declare(strict_types=1);

namespace Lisowiecw\MediaLibrary\Forms\Components;

use Closure;
use Filament\Actions\Action;
use Filament\Forms\Components\Concerns\CanLimitItemsLength;
use Filament\Forms\Components\Field;
use Filament\Forms\Components\FileUpload;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Tabs\Tab;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;
use Lisowiecw\MediaLibrary\Attachments\AttachmentReconciler;
use Lisowiecw\MediaLibrary\Ingest\IngestRules;
use Lisowiecw\MediaLibrary\Ingest\IngestService;
use Lisowiecw\MediaLibrary\Ingest\Placement;
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
            fn (MediaPicker $component): Action => $component->getUploadAction(),
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
        $ids = $this->getPickerValue();

        return MediaAsset::query()
            ->whereIn('id', $ids)
            ->get()
            ->sortBy(fn (MediaAsset $asset): int => (int) array_search($asset->id, $ids, strict: true))
            ->values();
    }

    /**
     * Ingest one uploaded file with this field's resolved Placement and select
     * it. Single selection replaces what is there; the previous asset is left
     * alone, since a replacement is not a destruction.
     */
    public function upload(TemporaryUploadedFile $file): MediaAsset
    {
        $asset = app(IngestService::class)->ingest($file, $this->getPlacement(), $this->getIngestRules());

        $this->state([$asset->id]);
        $this->callAfterStateUpdated();

        return $asset;
    }

    public function getUploadAction(): Action
    {
        $rules = $this->getIngestRules();

        return Action::make('upload')
            ->label(__('media-library::messages.picker.actions.upload.label'))
            ->modalHeading($this->getLabel())
            ->modalDescription(fn (): string => $this->getPlacementSummary())
            ->modalSubmitActionLabel(__('media-library::messages.picker.actions.upload.submit'))
            ->schema([
                Tabs::make()->tabs([
                    Tab::make(__('media-library::messages.picker.tabs.upload'))
                        ->schema([
                            FileUpload::make('file')
                                ->hiddenLabel()
                                ->storeFiles(false)
                                ->required()
                                ->when(
                                    filled($accepted = $rules->acceptedTypes),
                                    fn (FileUpload $upload): FileUpload => $upload->acceptedFileTypes($accepted ?? []),
                                )
                                ->maxSize($rules->maxUploadSize),
                        ]),
                ]),
            ])
            ->action(function (array $data): void {
                $file = Arr::first(Arr::wrap($data['file'] ?? []));

                if (! $file instanceof TemporaryUploadedFile) {
                    return;
                }

                $this->upload($file);
            });
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
