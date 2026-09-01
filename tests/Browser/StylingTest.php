<?php

declare(strict_types=1);

/**
 * The picker is styled by the package, not by whoever installs it. The check is
 * a computed one rather than a snapshot: that the stylesheet is on the page at
 * all, and that the grid is actually laying out as a grid. Both of those go
 * quietly if the asset stops being registered or published, which is exactly
 * how the picker once shipped as a column of unstyled text.
 */
it('loads the package stylesheet and lays the library out as a grid', function (): void {
    $this->signIn();

    $this->ingest('styled-one.jpg');
    $this->ingest('styled-two.jpg');

    $article = $this->article('Styling');

    $page = visit("/admin/articles/{$article->id}/edit");

    expect($page->script('
        [...document.querySelectorAll("link[rel=stylesheet]")]
            .some((link) => link.href.includes("media-library"))
    '))->toBeTrue();

    $page->click('[data-field="gallery"] .fi-ml-picker-trigger button')
        ->waitForText('Library');

    // A grid rather than a stack: the columns are what the stylesheet is for.
    expect($page->script('
        getComputedStyle(document.querySelector(".fi-ml-library-grid")).display
    '))->toBe('grid');

    expect($page->script('
        getComputedStyle(document.querySelector(".fi-ml-library-grid"))
            .gridTemplateColumns.split(" ").length
    '))->toBeGreaterThan(1);

    // The sidebar sits beside the results rather than above them.
    expect($page->script('
        const facets = document.querySelector(".fi-ml-library-facets").getBoundingClientRect()
        const grid = document.querySelector(".fi-ml-library-grid").getBoundingClientRect()

        facets.right <= grid.left + 1
    '))->toBeTrue();
});
