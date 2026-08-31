<?php

declare(strict_types=1);

use Lisowiecw\MediaLibrary\Models\MediaAsset;

/**
 * The ingest floor as a person meets it, on every surface that can meet it:
 * the management page's own Upload action, and both of the picker's ways in.
 * A refusal has to come back in words, because the only other thing a person
 * has to go on is the absence of a row.
 */

/**
 * PHP under a name the browser is happy to carry. Nothing stops this file
 * before the ingest floor: the name and the declared type are an image, so
 * Filepond takes it and the temporary upload succeeds, and the sniff is the
 * first thing that reads the bytes and disagrees.
 */
function inDisguise(): string
{
    return "<?php echo 'not an image';";
}

/**
 * What the floor says about it, whole.
 */
function theDisguiseRefused(): string
{
    return 'The file "pretending.png" was declared as image/png but its contents are text/x-php, which is a different kind of file.';
}
it('refuses a file over the size limit', function (): void {
    $this->signIn();

    // Lowered here rather than in the environment, so the file the browser
    // carries stays small: what is being tested is the refusal, not the wait.
    config()->set('media-library.max_upload_size', 4);

    $path = $this->file('far-too-big.jpg');

    visit('/admin/media-assets')
        ->click('button:has-text("Upload")')
        ->waitForText('Files')
        ->attach('input[type="file"]', $path)
        // Filepond refuses it in the browser, before a byte is posted.
        ->waitForText('File is too large')
        ->assertSee('Maximum file size is 4 KB');

    expect(MediaAsset::count())->toBe(0);
});

it('refuses a blocked type', function (): void {
    $this->signIn();

    $path = $this->file('sneaky.php', "<?php echo 'hello';");

    $page = visit('/admin/media-assets')
        ->click('button:has-text("Upload")')
        ->waitForText('Files')
        ->attach('input[type="file"]', $path);

    $this->staged($page)
        ->click($this->confirm())
        ->waitForText('is of a blocked type');

    expect(MediaAsset::count())->toBe(0);
});

it('refuses an SVG the Strict pass takes an element out of', function (): void {
    $this->signIn();

    // The Strict pass only runs on a public placement, which is what a public
    // field uploads onto, so the page's default placement is made one here.
    config()->set('media-library.visibility', 'public');

    $markup = <<<'SVG'
    <svg xmlns="http://www.w3.org/2000/svg" width="10" height="10">
        <style>circle { fill: red; }</style>
        <circle cx="5" cy="5" r="4" />
    </svg>
    SVG;

    $path = $this->file('has-a-style-block.svg', $markup);

    $page = visit('/admin/media-assets')
        ->click('button:has-text("Upload")')
        ->waitForText('Files')
        ->attach('input[type="file"]', $path);

    $this->staged($page)
        ->click($this->confirm())
        ->waitForText('Uploaded 0 file(s).');

    // What a person is left with, read out of the notification rather than
    // matched as visible text, because the body is clamped to two lines.
    //
    // The whole sentence, angle brackets and all: the refusal names the
    // element it dropped, and the notification body goes through Filament's
    // HTML sanitizer, so the text arrives escaped or the browser parses
    // "<style>" as a tag and eats the rest of it.
    expect($page->text('.fi-no-notification'))
        ->toContain('The file "has-a-style-block.svg" contains a <style> element, which is not allowed on a public placement.')
        ->and(MediaAsset::count())->toBe(0);
});

it('tells the person why a drop on the field trigger was refused', function (): void {
    $this->signIn();

    $article = $this->article('Refusing');

    $page = visit("/admin/articles/{$article->id}/edit");

    $this->drop($page, '[data-field="cover_image"] .fi-ml-picker-trigger', 'pretending.png', inDisguise())
        ->waitForText('which is a different kind of file');

    // The refusal is the whole of what happened: nothing was stored, and
    // nothing was attached to the field.
    expect(MediaAsset::count())->toBe(0);

    $page->assertSee(theDisguiseRefused());
});
