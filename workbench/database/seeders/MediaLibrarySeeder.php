<?php

declare(strict_types=1);

namespace Workbench\Database\Seeders;

use Closure;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Lisowiecw\MediaLibrary\Attachments\AttachmentReconciler;
use Lisowiecw\MediaLibrary\Enums\Visibility;
use Lisowiecw\MediaLibrary\Ingest\IngestService;
use Lisowiecw\MediaLibrary\Ingest\Placement;
use Lisowiecw\MediaLibrary\Models\MediaAsset;
use Workbench\App\Models\Article;
use Workbench\App\Models\User;

/**
 * Enough of a library that the parts worth looking at have something to do.
 *
 * Nothing here is a shortcut past the package: every asset is made by handing a
 * file to `IngestService`, which is the one path in, so the seeded rows are the
 * rows an upload through the panel would have written. That also means the
 * derivatives and the placeholder painting are produced by the real jobs, which is
 * why the workbench runs the queue synchronously.
 *
 * The shape is chosen for the sidebar. Every facet needs more than one option
 * before it is worth drawing, so the seed spreads assets across both
 * visibilities, images and documents, two uploaders, four weeks, and attached
 * against unattached.
 */
class MediaLibrarySeeder extends Seeder
{
    use WithoutModelEvents;

    public function run(): void
    {
        /** @var list<User> $uploaders */
        $uploaders = [
            User::query()->where('email', 'test@example.com')->firstOrFail(),
            User::factory()->create(['name' => 'Second Editor', 'email' => 'editor@example.com']),
        ];

        $articles = Article::factory()
            ->count(4)
            ->sequence(
                ['title' => 'The harbour at first light'],
                ['title' => 'Notes on a slow railway'],
                ['title' => 'What the archive kept'],
                ['title' => 'An article with no media yet'],
            )
            ->create();

        $public = [];
        $private = [];

        foreach ($this->plan() as $index => $spec) {
            Auth::setUser($uploaders[$index % count($uploaders)]);

            $asset = $this->ingest($spec);

            // Spread over the spans the Uploaded facet counts in, so its
            // numbers differ from each other.
            $asset->forceFill(['created_at' => now()->subDays($spec['age'])])->save();

            if ($asset->visibility === Visibility::Public) {
                $public[] = $asset;
            } else {
                $private[] = $asset;
            }
        }

        Auth::forgetUser();

        $reconciler = app(AttachmentReconciler::class);

        // Three of the four articles carry media, and the private assets are
        // spread unevenly, so the usage facet and the usage list on the
        // management page both have something other than one answer to give.
        $reconciler->reconcile($articles[0], 'cover_image', [$public[0]->id]);
        $reconciler->reconcile($articles[0], 'gallery', [$private[0]->id, $private[1]->id, $private[2]->id]);

        $reconciler->reconcile($articles[1], 'cover_image', [$public[1]->id]);
        $reconciler->reconcile($articles[1], 'gallery', [$private[1]->id]);

        $reconciler->reconcile($articles[2], 'cover_image', [$public[2]->id]);
    }

    /**
     * The files to make, before any of them exists. Each names its own bytes
     * rather than pointing at a fixture: shipping a dozen binaries in the
     * repository to demonstrate a media library would be its own small joke.
     *
     * @return list<array{name: string, visibility: string, age: int, contents: Closure(): string}>
     */
    private function plan(): array
    {
        return [
            ['name' => 'harbour-dawn.jpg', 'visibility' => 'public', 'age' => 0, 'contents' => fn (): string => $this->image('jpeg', 1600, 1000)],
            ['name' => 'slow-railway.jpg', 'visibility' => 'public', 'age' => 3, 'contents' => fn (): string => $this->image('jpeg', 1400, 900)],
            ['name' => 'the-archive.png', 'visibility' => 'public', 'age' => 12, 'contents' => fn (): string => $this->image('png', 1200, 1200)],
            ['name' => 'quayside.jpg', 'visibility' => 'public', 'age' => 40, 'contents' => fn (): string => $this->image('jpeg', 1500, 800)],
            ['name' => 'signal-box.png', 'visibility' => 'public', 'age' => 200, 'contents' => fn (): string => $this->image('png', 1000, 1400)],

            ['name' => 'contact-sheet-01.jpg', 'visibility' => 'private', 'age' => 0, 'contents' => fn (): string => $this->image('jpeg', 1600, 1200)],
            ['name' => 'contact-sheet-02.jpg', 'visibility' => 'private', 'age' => 1, 'contents' => fn (): string => $this->image('jpeg', 1600, 1200)],
            ['name' => 'contact-sheet-03.png', 'visibility' => 'private', 'age' => 5, 'contents' => fn (): string => $this->image('png', 1300, 1000)],
            ['name' => 'unpublished-portrait.jpg', 'visibility' => 'private', 'age' => 20, 'contents' => fn (): string => $this->image('jpeg', 900, 1500)],
            ['name' => 'reader-survey.pdf', 'visibility' => 'private', 'age' => 60, 'contents' => fn (): string => $this->pdf()],
            ['name' => 'style-guide.txt', 'visibility' => 'private', 'age' => 300, 'contents' => fn (): string => $this->text()],
        ];
    }

    /**
     * @param  array{name: string, visibility: string, age: int, contents: Closure(): string}  $spec
     */
    private function ingest(array $spec): MediaAsset
    {
        $path = sys_get_temp_dir().DIRECTORY_SEPARATOR.'workbench-seed-'.Str::random(16);

        File::put($path, ($spec['contents'])());

        try {
            return app(IngestService::class)->ingest(
                new UploadedFile($path, $spec['name'], test: true),
                Placement::resolve(visibility: $spec['visibility']),
            );
        } finally {
            File::delete($path);
        }
    }

    /**
     * A soft two-axis gradient, which is enough for a thumbnail to look like
     * something and for the BlurHash to be more than one flat colour, and large
     * enough in both bytes and pixels that the small-original shortcut does not
     * skip its derivatives.
     *
     * @param  positive-int  $width
     * @param  positive-int  $height
     */
    private function image(string $format, int $width, int $height): string
    {
        $canvas = imagecreatetruecolor($width, $height);

        $hue = random_int(0, 255);

        for ($y = 0; $y < $height; $y += 4) {
            for ($x = 0; $x < $width; $x += 4) {
                $colour = imagecolorallocate(
                    $canvas,
                    $this->channel($hue + 200 * $x / $width),
                    $this->channel(60 + 160 * $y / $height),
                    $this->channel(200 - 120 * $x / $width),
                );

                if ($colour !== false) {
                    imagefilledrectangle($canvas, $x, $y, $x + 3, $y + 3, $colour);
                }
            }
        }

        ob_start();

        $format === 'png' ? imagepng($canvas) : imagejpeg($canvas, null, 90);

        $bytes = (string) ob_get_clean();

        imagedestroy($canvas);

        return $bytes;
    }

    /**
     * One colour channel, clamped to what GD will take.
     *
     * @return int<0, 255>
     */
    private function channel(float $value): int
    {
        return max(0, min(255, (int) $value));
    }

    /**
     * The smallest thing a sniffer will call `application/pdf`. The library
     * never opens a document, so a page of nothing is a fair stand-in for one.
     */
    private function pdf(): string
    {
        return "%PDF-1.4\n1 0 obj<</Type/Catalog/Pages 2 0 R>>endobj\n"
            ."2 0 obj<</Type/Pages/Kids[3 0 R]/Count 1>>endobj\n"
            ."3 0 obj<</Type/Page/Parent 2 0 R/MediaBox[0 0 595 842]>>endobj\n"
            ."trailer<</Root 1 0 R>>\n%%EOF\n";
    }

    private function text(): string
    {
        return "House style, in brief.\n\nSentence case in headings. No em-dashes.\n";
    }
}
