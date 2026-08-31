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
use Illuminate\Database\Eloquent\Builder;
use Lisowiecw\MediaLibrary\Forms\Components\MediaPicker;
use Lisowiecw\MediaLibrary\Models\MediaAsset;
use Livewire\Component;
use Workbench\App\Models\Article;

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

        foreach ($this->picker as $setting => $value) {
            $picker->{$setting}(...($value === true ? [] : [$this->pickerArgument($setting, $value)]));
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

    /**
     * A closure cannot ride a Livewire property, so a test names the callback
     * it wants and the fixture supplies it.
     */
    protected function pickerArgument(string $setting, mixed $value): mixed
    {
        return match ([$setting, $value]) {
            ['scopeLibrary', 'archive'] => fn (Builder $query) => $query->where('disk', 'archive'),
            ['scopeLibrary', 'widen'] => fn (Builder $query) => $query->orWhere('visibility', 'private'),
            ['thumbnailUsing', 'stamped'] => fn (MediaAsset $asset): string => 'https://thumbs.test/'.$asset->id,
            default => $value,
        };
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
