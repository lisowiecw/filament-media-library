<?php

declare(strict_types=1);

use Lisowiecw\MediaLibrary\Ingest\UploadCeiling;

/**
 * The PHP ceilings depend on the machine running the suite, so the assertions
 * read the one line the test controls.
 *
 * @return list<string>
 */
function livewireWarnings(int $maxUploadSize): array
{
    return array_values(array_filter(
        UploadCeiling::warnings($maxUploadSize),
        fn (string $warning): bool => str_contains($warning, 'Livewire'),
    ));
}

it('stays quiet when the configured size is reachable', function (): void {
    config()->set('livewire.temporary_file_upload.rules', ['file', 'max:12288']);

    expect(UploadCeiling::warnings(1024))->toBe([]);
});

it('warns when the configured size is above the livewire ceiling', function (): void {
    config()->set('livewire.temporary_file_upload.rules', ['file', 'max:12288']);

    $livewire = livewireWarnings(50 * 1024);

    expect($livewire)->toHaveCount(1)
        ->and($livewire[0])->toContain('12288 KB');
});

it('reads the livewire ceiling out of a piped rule string', function (): void {
    config()->set('livewire.temporary_file_upload.rules', 'file|max:2048');

    expect(livewireWarnings(4096)[0])->toContain('2048 KB');
});
