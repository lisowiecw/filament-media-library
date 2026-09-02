<?php

declare(strict_types=1);

namespace Lisowiecw\MediaLibrary\Tests\Browser;

use BladeUI\Heroicons\BladeHeroiconsServiceProvider;
use BladeUI\Icons\BladeIconsServiceProvider;
use Filament\Actions\ActionsServiceProvider;
use Filament\FilamentServiceProvider;
use Filament\Forms\FormsServiceProvider;
use Filament\Infolists\InfolistsServiceProvider;
use Filament\Notifications\NotificationsServiceProvider;
use Filament\QueryBuilder\QueryBuilderServiceProvider;
use Filament\Schemas\SchemasServiceProvider;
use Filament\Support\SupportServiceProvider;
use Filament\Tables\TablesServiceProvider;
use Filament\Widgets\WidgetsServiceProvider;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Lisowiecw\MediaLibrary\Attachments\AttachmentReconciler;
use Lisowiecw\MediaLibrary\Delivery\DeliveryRoute;
use Lisowiecw\MediaLibrary\Ingest\IngestService;
use Lisowiecw\MediaLibrary\Ingest\Placement;
use Lisowiecw\MediaLibrary\MediaLibraryServiceProvider;
use Lisowiecw\MediaLibrary\Models\MediaAsset;
use Livewire\LivewireServiceProvider;
use Orchestra\Testbench\Concerns\WithLaravelMigrations;
use Orchestra\Testbench\Concerns\WithWorkbench;
use Orchestra\Testbench\TestCase as Orchestra;
use Pest\Browser\Api\AwaitableWebpage;
use Pest\Browser\Api\PendingAwaitablePage;
use Pest\Browser\Playwright\Playwright;
use Workbench\App\Models\Article;
use Workbench\App\Models\User;
use Workbench\App\Providers\WorkbenchPanelProvider;
use Workbench\App\Providers\WorkbenchServiceProvider;

/**
 * The application a browser test drives: the workbench, unchanged. Nothing here
 * substitutes a fixture for a part of it, because the point of these tests is
 * that the panel a person opens is the panel that works.
 *
 * The disks are the workbench's own pair, a public one with a URL of its own
 * and a private one without, so a Delivery response and a disk URL can be told
 * apart in a browser rather than only in an assertion about state.
 */
abstract class BrowserTestCase extends Orchestra
{
    use RefreshDatabase;
    use WithLaravelMigrations;
    use WithWorkbench;

    /**
     * Where this test's bytes live, under the disks the workbench names, and
     * removed again afterwards: a browser test writes real files rather than
     * faked ones, because the browser has to be able to fetch them.
     */
    protected string $directory = 'browser-media';

    /** Where files handed to a file input are staged. */
    protected string $files = '';

    /**
     * The file input once Filepond has adopted it. Filepond takes over the
     * field's own input rather than making one, and only then marks it, so
     * this selector matches nothing until there is a pond behind it.
     */
    protected string $pondInput = '.filepond--root input.filepond--browser';

    protected function setUp(): void
    {
        parent::setUp();

        // Five seconds is the plugin's default, and it is short for this
        // panel: an upload here is sent, put back together by the middleware
        // below, ingested, and rendered on an inline queue before the page
        // settles, which on a loaded machine takes longer than that.
        Playwright::setTimeout(15_000);

        $this->files = sys_get_temp_dir().'/browser-uploads-'.Str::random(8);

        // The in-process test server drops multipart files; put them back.
        $this->app->make(HttpKernel::class)->prependMiddleware(RecoverUploadedFiles::class);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->files);

        File::deleteDirectory(storage_path('app/public/'.$this->directory));
        File::deleteDirectory(storage_path('app/private/'.$this->directory));

        parent::tearDown();
    }

    /**
     * @return array<int, class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [
            BladeIconsServiceProvider::class,
            BladeHeroiconsServiceProvider::class,
            SupportServiceProvider::class,
            ActionsServiceProvider::class,
            FormsServiceProvider::class,
            InfolistsServiceProvider::class,
            NotificationsServiceProvider::class,
            QueryBuilderServiceProvider::class,
            SchemasServiceProvider::class,
            TablesServiceProvider::class,
            WidgetsServiceProvider::class,
            FilamentServiceProvider::class,
            // Registered after Filament, for the same reason the suite's own
            // test case registers it there.
            LivewireServiceProvider::class,
            MediaLibraryServiceProvider::class,
            WorkbenchServiceProvider::class,
            WorkbenchPanelProvider::class,
        ];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('database.default', 'testing');
        $app['config']->set('cache.default', 'array');
        $app['config']->set('session.driver', 'file');

        // Derivatives and the placeholder painting are queued work, and a
        // browser test asserts on what a card paints, so the queue runs inline
        // exactly as `composer serve` runs it.
        $app['config']->set('queue.default', 'sync');

        // A path rather than an absolute URL built from APP_URL: the address a
        // public asset hands out has to be one the test server listens on, and
        // the server's port is not known until it starts.
        $app['config']->set('filesystems.disks.public.url', '/storage');

        // Livewire hardcodes temporary uploads onto a "tmp-for-tests" disk
        // whenever it is running under a test runner, and normally its own
        // fake defines that disk. A real browser upload needs it to exist.
        $app['config']->set('filesystems.disks.tmp-for-tests', [
            'driver' => 'local',
            'root' => storage_path('app/tmp-for-tests'),
        ]);
        $app['config']->set('media-library.public_disk', 'public');
        $app['config']->set('media-library.private_disk', 'local');
        $app['config']->set('media-library.disk', null);
        $app['config']->set('media-library.directory', $this->directory);
        $app['config']->set('media-library.visibility', 'private');
    }

    /**
     * Sign in the way a person does, through the panel's own login page, and
     * hand back the page they landed on.
     */
    protected function signIn(?User $user = null): AwaitableWebpage
    {
        $user ??= User::factory()->create(['password' => 'password']);

        // Filament's login inputs carry an id and a Livewire binding but no
        // name attribute, so they are addressed by that id.
        return visit('/admin/login')
            ->fill('[id="form.email"]', $user->email)
            ->fill('[id="form.password"]', 'password')
            // By selector rather than by text: the page's heading says "Sign
            // in" too, and the heading is what a text match finds first.
            ->click('form button[type="submit"]')
            // The panel has no dashboard, so signing in lands on whichever
            // page it lists first. That it is no longer the login page is the
            // part a caller depends on.
            ->assertPathBeginsWith('/admin')
            ->assertPathIsNot('/admin/login');
    }

    /**
     * An asset made the only way the package makes one: by handing a file to
     * the ingest service, so the row, the bytes and the renderings are the ones
     * an upload through the panel would have left.
     */
    protected function ingest(string $name, string $visibility = 'private', ?string $contents = null): MediaAsset
    {
        $path = sys_get_temp_dir().DIRECTORY_SEPARATOR.'browser-'.Str::random(16);

        File::put($path, $contents ?? $this->image());

        try {
            return app(IngestService::class)->ingest(
                new UploadedFile($path, $name, test: true),
                Placement::resolve(visibility: $visibility),
            );
        } finally {
            File::delete($path);
        }
    }

    /**
     * An asset uploaded by somebody, since an uploader is what the library's
     * own facet counts and what a management page reads back.
     */
    protected function ingestAs(User $user, string $name, string $visibility = 'private'): MediaAsset
    {
        Auth::setUser($user);

        try {
            return $this->ingest($name, $visibility);
        } finally {
            Auth::forgetUser();
        }
    }

    /**
     * A value as JavaScript source, for the small fetches a delivery test runs
     * inside the page.
     */
    protected function js(mixed $value): string
    {
        return (string) json_encode($value, JSON_THROW_ON_ERROR);
    }

    /**
     * The Delivery route's own URL for an asset, asked for the only supported
     * way, so a test never builds the shape by hand.
     */
    protected function deliveryUrl(MediaAsset $asset, bool $download = false): string
    {
        return $download ? $asset->downloadUrl() : DeliveryRoute::signedUrl($asset);
    }

    protected function article(string $title = 'A post'): Article
    {
        return Article::create(['title' => $title]);
    }

    protected function attach(Article $host, string $field, MediaAsset ...$assets): void
    {
        app(AttachmentReconciler::class)->reconcile(
            $host,
            $field,
            array_map(static fn (MediaAsset $asset): int => $asset->id, $assets),
        );
    }

    /**
     * The selector for a record action in a table row, addressed by the action
     * it mounts rather than by its label: several labels on the page start
     * with the same word, and the row action is the one carrying a record key.
     */
    protected function rowAction(string $name): string
    {
        return 'tbody button[wire\\:click^="mountAction(\''.$name.'\'"]';
    }

    /**
     * Ask the page a yes-or-no question until it says yes, and fail the test
     * with the given complaint if the deadline passes first.
     *
     * `$question` is JavaScript that answers truthy when the wait is over. It
     * is run inside the page, so it reads the live DOM rather than a snapshot
     * of it.
     *
     * The poll is a timer rather than a frame callback: a headless page is not
     * obliged to paint, and a wait that only advances on a repaint can sit
     * still for its whole deadline.
     *
     * The suite's assertions read the page once and do not wait, which is the
     * right default: a test that waits on everything cannot say when something
     * took too long. What does need waiting on is work the page starts on its
     * own, a tab rendering or Filepond finishing a send, and waiting on the
     * thing that settles is the ADR 16 answer to a flaky test rather than a
     * sleep or a retry.
     */
    protected function until(AwaitableWebpage|PendingAwaitablePage $page, string $question, string $complaint, int $seconds = 10): AwaitableWebpage|PendingAwaitablePage
    {
        $milliseconds = $seconds * 1000;

        $answered = $page->script(<<<JS
            new Promise((resolve) => {
                const deadline = Date.now() + {$milliseconds};
                const ask = () => {
                    {$question}
                };
                const look = () => {
                    if (ask()) {
                        return resolve(true);
                    }

                    if (Date.now() > deadline) {
                        return resolve(false);
                    }

                    setTimeout(look, 50);
                };

                look();
            })
        JS);

        expect($answered)->toBeTrue("{$complaint} within {$seconds} seconds, and it never did.");

        return $page;
    }

    /**
     * Wait for a selector to be in the DOM, and answer with the page.
     */
    protected function present(AwaitableWebpage|PendingAwaitablePage $page, string $selector, int $seconds = 10): AwaitableWebpage|PendingAwaitablePage
    {
        return $this->until(
            $page,
            "return document.querySelector({$this->js($selector)});",
            "Expected element [{$selector}] to appear",
            $seconds,
        );
    }

    /**
     * Wait for a selector to be in the DOM *and* to have had its Alpine
     * handlers bound, and answer with the page.
     *
     * Presence is not readiness. A drop surface is an element carrying
     * `x-data`, and Alpine walks the tree after the markup lands, so an event
     * dispatched in that gap is delivered to an element with nothing listening
     * on it: no request, no notification, no change of any kind, which is
     * indistinguishable from the feature being broken. An initialised element
     * carries `_x_dataStack`, so that is what is waited on. An element that
     * declares no `x-data` (Filepond's own root) is ready as soon as it is
     * present.
     */
    protected function bound(AwaitableWebpage|PendingAwaitablePage $page, string $selector, int $seconds = 10): AwaitableWebpage|PendingAwaitablePage
    {
        return $this->until(
            $page,
            <<<JS
                const target = document.querySelector({$this->js($selector)});

                return target && (! target.hasAttribute('x-data') || target._x_dataStack);
            JS,
            "Expected element [{$selector}] to appear and bind its handlers",
            $seconds,
        );
    }

    /**
     * Drop a file onto a surface the way a person does, by dispatching a real
     * drop event carrying a DataTransfer. Playwright can fill a file input but
     * cannot drag from the desktop, so the file is carried into the page as
     * base64 and rebuilt there.
     */
    protected function drop(AwaitableWebpage|PendingAwaitablePage $page, string $selector, string $name, ?string $contents = null): AwaitableWebpage|PendingAwaitablePage
    {
        $payload = base64_encode($contents ?? $this->image());

        // The target has to be there, and listening, before the event is aimed
        // at it: a drop dispatched at nothing throws inside the page, and one
        // dispatched between the element arriving and Alpine binding its
        // handler is swallowed in silence. Either way the test then fails on
        // the absence of everything that drop was supposed to cause.
        $this->bound($page, $selector);

        $page->script(<<<JS
            (() => {
                const binary = atob({$this->js($payload)});
                const bytes = Uint8Array.from(binary, (character) => character.charCodeAt(0));
                const file = new File([bytes], {$this->js($name)}, { type: 'image/jpeg' });
                const transfer = new DataTransfer();
                transfer.items.add(file);
                const target = document.querySelector({$this->js($selector)});
                const box = target.getBoundingClientRect();
                // The pointer position matters: a drop surface that tracks the
                // cursor (Filepond does) ignores an event that claims to be at
                // the top left corner of the page.
                const at = { clientX: box.left + box.width / 2, clientY: box.top + box.height / 2 };
                const fire = (type, on) => on.dispatchEvent(new DragEvent(type, {
                    dataTransfer: transfer,
                    bubbles: true,
                    cancelable: true,
                    ...at,
                }));

                fire('dragenter', target);
                fire('dragover', target);
                fire('drop', target);
            })()
        JS);

        return $page;
    }

    /**
     * The selector for the button that confirms whatever modal is open, which
     * is its submit rather than its label: a confirmation modal says "Confirm"
     * and an action modal says whatever the action called it.
     */
    protected function confirm(): string
    {
        return '.fi-modal-footer-actions button[type="submit"]:visible';
    }

    /**
     * Hand a file to the Upload tab's pond, and answer with the page.
     *
     * The wait before the attach is the whole point of the helper. The modal's
     * text lands about a tenth of a second before Filepond exists at all, and
     * Filepond adopts the field's own file input rather than making one: it
     * reads whatever is already on that input once, at creation, then moves
     * the node into its root, marks it `filepond--browser` and binds its
     * change handler. A file set on the input inside that gap is read by
     * nobody. Nothing is thrown, no item joins the pond, and the test sits out
     * its whole deadline waiting for a send that was never started.
     *
     * So this waits for the far end of the gap: an input Filepond has taken
     * over, inside a root Filepond answers for. Attaching after that is
     * attaching to a listening pond.
     */
    protected function pour(AwaitableWebpage|PendingAwaitablePage $page, string $path, int $seconds = 10): AwaitableWebpage|PendingAwaitablePage
    {
        $adopted = $this->js($this->pondInput);

        $this->until(
            $page,
            <<<JS
                const browser = document.querySelector({$adopted});

                if (! browser) {
                    return false;
                }

                try {
                    return window.FilePond.find(browser.closest('.filepond--root'));
                } catch (error) {
                    // The markup is there but no pond is registered against it
                    // yet, which is a moment to look again.
                    return false;
                }
            JS,
            'Expected a Filepond ready for a file',
            $seconds,
        );

        return $page->attach($this->pondInput, $path);
    }

    /**
     * Wait until the Upload tab has finished sending what it was given, and
     * answer with the page.
     *
     * The state is read off the pond's item rather than its "Upload complete"
     * label, which is a passing one: a small file can be sent and the label
     * gone again before a test looks for it. The send is asynchronous however
     * the file arrived, by an attach or by a drop, so this waits rather than
     * reading once.
     */
    protected function staged(AwaitableWebpage|PendingAwaitablePage $page): AwaitableWebpage|PendingAwaitablePage
    {
        return $this->present($page, '.filepond--item[data-filepond-item-state="processing-complete"]');
    }

    /**
     * A file on disk for the browser to attach to a file input, with the
     * contents given or a generated image, cleaned up with the test.
     */
    protected function file(string $name, ?string $contents = null): string
    {
        $path = $this->files.'/'.$name;

        File::ensureDirectoryExists(dirname($path));
        file_put_contents($path, $contents ?? $this->image());

        return $path;
    }

    /**
     * A gradient large enough in both bytes and pixels that the ingest does not
     * take its small-original shortcut past the renderings.
     */
    protected function image(string $format = 'jpeg', int $width = 1200, int $height = 800): string
    {
        $canvas = imagecreatetruecolor($width, $height);

        for ($y = 0; $y < $height; $y += 4) {
            for ($x = 0; $x < $width; $x += 4) {
                $colour = imagecolorallocate(
                    $canvas,
                    (int) (255 * $x / $width),
                    (int) (60 + 160 * $y / $height),
                    (int) (200 - 120 * $x / $width),
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
}
