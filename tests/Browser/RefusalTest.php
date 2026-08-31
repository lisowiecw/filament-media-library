<?php

declare(strict_types=1);

use Lisowiecw\MediaLibrary\Models\MediaAsset;

/**
 * The ingest floor as a person meets it: the management page's own Upload
 * action, which is the surface that reports a refusal back in words rather
 * than only in the absence of a row.
 */
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

    visit('/admin/media-assets')
        ->click('button:has-text("Upload")')
        ->waitForText('Files')
        ->attach('input[type="file"]', $path)
        ->assertPresent($this->staged())
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
        ->attach('input[type="file"]', $path)
        ->assertPresent($this->staged())
        ->click($this->confirm())
        ->waitForText('Uploaded 0 file(s).');

    // What a person is left with, read out of the notification rather than
    // matched as visible text, because the body is clamped to two lines.
    //
    // The message ends mid sentence: a refusal names the element it dropped in
    // angle brackets, and the notification body is rendered as HTML, so the
    // browser parses "<style>" as a tag and eats the rest. Ticket #62.
    expect($page->text('.fi-no-notification'))
        ->toContain('The file "has-a-style-block.svg" contains a')
        ->and(MediaAsset::count())->toBe(0);
});
