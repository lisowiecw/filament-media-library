<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\Collection;
use Lisowiecw\MediaLibrary\Models\MediaAttachment;
use Workbench\App\Models\Article;
use Workbench\App\Models\User as MediaHostUser;

/**
 * The batch read's own fixture: hosts with a thumbnail each and a gallery on
 * the first, so a test can tell a constrained load from an unconstrained one.
 *
 * @return Collection<int, Article>
 */
function articlesWithMedia(int $count = 3): Collection
{
    /** @var Collection<int, Article> */
    return new Collection(array_map(function (int $index): Article {
        $host = article('Post '.$index);
        attachToField($host, 'thumbnail', libraryAsset());
        attachToField($host, 'gallery', libraryAsset());

        return $host;
    }, range(1, $count)));
}

it('costs the same number of queries however many hosts the scope loads', function (): void {
    articlesWithMedia(2);

    $small = queriesFor(fn () => Article::query()->withMedia('thumbnail')->get()
        ->each(fn (Article $host) => $host->media('thumbnail')));

    articlesWithMedia(6);

    $large = queriesFor(fn () => Article::query()->withMedia('thumbnail')->get()
        ->each(fn (Article $host) => $host->media('thumbnail')));

    expect($small)->toBe($large);
});

it('reads a scoped field out of memory', function (): void {
    $host = articlesWithMedia(1)->first();
    $expected = $host->media('thumbnail')->pluck('id')->all();

    $loaded = Article::query()->withMedia('thumbnail')->findOrFail($host->id);

    expect(queriesFor(fn () => $loaded->media('thumbnail')))->toBe(0)
        ->and($loaded->media('thumbnail')->pluck('id')->all())->toBe($expected);
});

it('loads only the fields the scope names', function (): void {
    $host = articlesWithMedia(1)->first();

    $loaded = Article::query()->withMedia('thumbnail')->findOrFail($host->id);

    expect($loaded->getRelation('mediaAttachments')->pluck('field_name')->unique()->all())
        ->toBe(['thumbnail']);
});

it('queries for a field the scope did not name rather than answering empty', function (): void {
    $host = articlesWithMedia(1)->first();
    $expected = $host->media('gallery')->pluck('id')->all();

    $loaded = Article::query()->withMedia('thumbnail')->findOrFail($host->id);

    expect($loaded->media('gallery')->pluck('id')->all())->toBe($expected);
});

it('takes several fields at once', function (): void {
    $host = articlesWithMedia(1)->first();

    $loaded = Article::query()->withMedia('thumbnail', 'gallery')->findOrFail($host->id);

    expect(queriesFor(function () use ($loaded): void {
        $loaded->media('thumbnail');
        $loaded->media('gallery');
    }))->toBe(0);
});

it('loads media onto hosts already in memory', function (): void {
    articlesWithMedia(3);

    $hosts = Article::query()->get();

    $queries = queriesFor(fn () => $hosts->loadMedia('thumbnail'));

    expect($hosts)->toHaveCount(3)
        ->and($queries)->toBe(2)
        ->and(queriesFor(fn () => $hosts->each(fn (Article $host) => $host->media('thumbnail'))))->toBe(0);
});

it('loads media onto a single host already in memory', function (): void {
    $host = articlesWithMedia(1)->first();
    $expected = $host->media('thumbnail')->pluck('id')->all();

    $loaded = Article::query()->findOrFail($host->id);
    $loaded->loadMedia('thumbnail');

    expect(queriesFor(fn () => $loaded->media('thumbnail')))->toBe(0)
        ->and($loaded->media('thumbnail')->pluck('id')->all())->toBe($expected);
});

it('narrows the field set when a load follows a wider one', function (): void {
    $host = articlesWithMedia(1)->first();
    $expected = $host->media('gallery')->pluck('id')->all();

    $loaded = Article::query()->withMedia('thumbnail', 'gallery')->findOrFail($host->id);
    $loaded->loadMedia('thumbnail');

    expect($loaded->media('gallery')->pluck('id')->all())->toBe($expected);
});

it('leaves a host without the trait alone in a mixed collection', function (): void {
    articlesWithMedia(1);

    $hosts = new Collection([...Article::query()->get()->all(), libraryAsset()]);

    $hosts->loadMedia('thumbnail');

    expect($hosts->first()->relationLoaded('mediaAttachments'))->toBeTrue();
});

it('loads every host class in a collection of more than one', function (): void {
    articlesWithMedia(1);

    $other = MediaHostUser::create(['name' => 'Ada']);
    attachToField($other, 'thumbnail', libraryAsset());

    $hosts = new Collection([...Article::query()->get()->all(), $other]);

    $hosts->loadMedia('thumbnail');

    expect(queriesFor(fn () => $hosts->each(fn ($host) => $host->media('thumbnail'))))->toBe(0)
        ->and($other->media('thumbnail'))->toHaveCount(1)
        ->and($hosts->first()->media('thumbnail'))->toHaveCount(1);
});

it('loads nothing when no field is named', function (): void {
    $host = articlesWithMedia(1)->first();
    $expected = $host->media('thumbnail')->pluck('id')->all();

    $loaded = Article::query()->withMedia()->findOrFail($host->id);
    $loaded->loadMedia();

    expect($loaded->media('thumbnail')->pluck('id')->all())->toBe($expected);
});

/**
 * The rule "these rows, this host, this field" has one home, so the query and
 * the in-memory read cannot drift apart: a condition added to the scope, a
 * tenant check being the obvious candidate, is felt on both paths or neither.
 * Read off the source, because agreeing on today's rows is what two spellings
 * do right up until one of them changes.
 */
it('answers the field rule from one place on both paths', function (): void {
    $bodies = array_map(function (string $method): string {
        $reflected = new ReflectionMethod(MediaAttachment::class, $method);
        $lines = file((string) $reflected->getFileName()) ?: [];

        return implode('', array_slice(
            $lines,
            (int) $reflected->getStartLine() - 1,
            (int) $reflected->getEndLine() - (int) $reflected->getStartLine() + 1,
        ));
    }, ['scopeForField', 'matchesField']);

    foreach ($bodies as $body) {
        expect($body)->toContain('fieldConditions(');
    }
});

it('agrees between the queried and the in-memory field rule', function (): void {
    $host = articlesWithMedia(1)->first();
    $other = article('Elsewhere');
    attachToField($other, 'thumbnail', libraryAsset());

    $queried = MediaAttachment::query()->forField($host, 'thumbnail')->pluck('id')->all();
    $matched = MediaAttachment::query()->get()
        ->filter(fn (MediaAttachment $attachment): bool => $attachment->matchesField($host, 'thumbnail'))
        ->pluck('id')->all();

    expect($matched)->toBe($queried)->and($queried)->toHaveCount(1);
});
