<?php

declare(strict_types=1);

namespace Lisowiecw\MediaLibrary\Tests\Browser;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;

/**
 * The browser plugin's in-process HTTP server parses multipart bodies with
 * multibyte string functions, which can corrupt binary file contents. This
 * middleware reparses the raw body with byte-oriented operations and restores
 * the file bag with Laravel's test-upload instances.
 */
final class RecoverUploadedFiles
{
    public function handle(Request $request, Closure $next): mixed
    {
        $type = (string) $request->headers->get('content-type', '');
        $temporaryFiles = [];

        if (str_starts_with(strtolower($type), 'multipart/form-data')) {
            if (preg_match('/boundary="?([^";]+)"?/i', $type, $matches) === 1) {
                $temporaryFiles = $this->recover($request, $matches[1]);
            }
        }

        try {
            return $next($request);
        } finally {
            foreach ($temporaryFiles as $path) {
                if (is_file($path)) {
                    unlink($path);
                }
            }
        }
    }

    /**
     * @return list<string>
     */
    private function recover(Request $request, string $boundary): array
    {
        $body = $request->getContent();

        if (! is_string($body) || $body === '') {
            return [];
        }

        $files = [];
        $fields = [];
        $temporaryFiles = [];

        foreach (explode('--'.$boundary, $body) as $part) {
            $part = ltrim($part, "\r\n");

            if ($part === '' || str_starts_with($part, '--')) {
                continue;
            }

            $split = explode("\r\n\r\n", $part, 2);

            if (count($split) !== 2) {
                continue;
            }

            [$rawHeaders, $content] = $split;
            $content = preg_replace('/\r\n\z/', '', $content) ?? $content;

            if (preg_match('/name="([^"]*)"/', $rawHeaders, $name) !== 1) {
                continue;
            }

            if (preg_match('/filename="([^"]*)"/', $rawHeaders, $filename) !== 1) {
                $this->put($fields, $name[1], $content);

                continue;
            }

            $path = tempnam(sys_get_temp_dir(), 'browser-upload');

            if ($path === false) {
                continue;
            }

            file_put_contents($path, $content);
            $temporaryFiles[] = $path;

            preg_match('/Content-Type:\s*([^\r\n]+)/i', $rawHeaders, $mime);

            // Laravel's own UploadedFile rather than Symfony's: converting a
            // Symfony instance loses the test flag, and without it every
            // upload fails validation as "failed to upload".
            $this->put($files, $name[1], new UploadedFile(
                $path,
                $filename[1],
                isset($mime[1]) ? trim($mime[1]) : null,
                null,
                true,
            ));
        }

        $request->files->replace($files);
        $request->request->add($fields);

        return $temporaryFiles;
    }

    /**
     * Place a part under its field name, honouring the bracket syntax a
     * browser uses for multi-file inputs (files[], files[0], and so on).
     *
     * @param  array<mixed>  $target
     */
    private function put(array &$target, string $name, mixed $value): void
    {
        if (! str_contains($name, '[')) {
            $target[$name] = $value;

            return;
        }

        preg_match_all('/\[([^\]]*)\]/', $name, $keys);
        $cursor = &$target;
        $root = strtok($name, '[');
        $segments = array_merge([$root === false ? $name : $root], $keys[1]);

        foreach ($segments as $index => $segment) {
            $last = $index === count($segments) - 1;

            if ($segment === '') {
                if ($last) {
                    $cursor[] = $value;

                    return;
                }

                $segment = count($cursor);
            }

            if ($last) {
                $cursor[$segment] = $value;

                return;
            }

            if (! isset($cursor[$segment]) || ! is_array($cursor[$segment])) {
                $cursor[$segment] = [];
            }

            $cursor = &$cursor[$segment];
        }
    }
}
