<?php

declare(strict_types=1);

it('opens the panel login page', function (): void {
    $page = visit('/admin/login');

    $page->assertSee('Sign in');
});
