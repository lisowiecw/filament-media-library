<?php

declare(strict_types=1);

namespace Lisowiecw\MediaLibrary\Tests\Fixtures;

use Filament\Actions\Concerns\InteractsWithActions;
use Filament\Actions\Contracts\HasActions;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Concerns\InteractsWithSchemas;
use Filament\Schemas\Contracts\HasSchemas;
use Filament\Schemas\Schema;
use Illuminate\Contracts\View\View;
use Lisowiecw\MediaLibrary\Forms\Components\MediaPicker;
use Livewire\Component;

/**
 * A host form the picker is mounted in, so its behaviour is exercised the way
 * a real panel exercises it: fill, validate, save, reconcile.
 *
 * @property-read Schema $form
 */
class ArticleForm extends Component implements HasActions, HasSchemas
{
    use InteractsWithActions;
    use InteractsWithSchemas;

    public ?int $articleId = null;

    protected ?Article $cachedRecord = null;

    /** @var array<string, mixed> */
    public array $data = [];

    /**
     * The picker configuration under test, injected per test so one fixture
     * covers cardinality, accepted types and placement alike.
     *
     * @var array<string, mixed>
     */
    public array $picker = [];

    /**
     * @param  array<string, mixed>  $picker
     */
    public function mount(?int $articleId = null, array $picker = []): void
    {
        $this->picker = $picker;
        $this->articleId = $articleId;

        $this->form->fill($this->getRecord()?->attributesToArray() ?? ['title' => 'A post']);
    }

    public function getRecord(): ?Article
    {
        if ($this->articleId === null) {
            return null;
        }

        return $this->cachedRecord ??= Article::query()->find($this->articleId);
    }

    public function form(Schema $schema): Schema
    {
        $picker = MediaPicker::make('cover_image')
            ->label('Cover image');

        if (array_key_exists('acceptedFileTypes', $this->picker)) {
            /** @var array<string> $types */
            $types = $this->picker['acceptedFileTypes'];
            $picker->acceptedFileTypes($types);
        }

        foreach (['disk', 'directory', 'visibility'] as $setting) {
            if (array_key_exists($setting, $this->picker)) {
                /** @var string $value */
                $value = $this->picker[$setting];
                $picker->{$setting}($value);
            }
        }

        foreach (['maxSize', 'minItems', 'maxItems'] as $setting) {
            if (array_key_exists($setting, $this->picker)) {
                /** @var int $value */
                $value = $this->picker[$setting];
                $picker->{$setting}($value);
            }
        }

        if ($this->picker['required'] ?? false) {
            $picker->required();
        }

        return $schema
            ->model($this->getRecord() ?? Article::class)
            ->record($this->getRecord())
            ->statePath('data')
            ->components([
                TextInput::make('title')->required(),
                $picker,
            ]);
    }

    public function save(): void
    {
        $data = $this->form->getState();

        $record = $this->getRecord();

        if ($record === null) {
            $this->cachedRecord = Article::create($data);
            $this->articleId = $this->cachedRecord->getKey();
            $this->form->model($this->cachedRecord)->record($this->cachedRecord)->saveRelationships();

            return;
        }

        $record->update($data);
    }

    /**
     * Filament renders a mounted action's modal as a Livewire partial, which a
     * component test never sees. Asking for a full render puts the modal back
     * into the component's HTML so tests can read it.
     */
    public function renderEverything(): void
    {
        $this->forceRender();
    }

    public function render(): View
    {
        return view('media-library-tests::article-form');
    }
}
