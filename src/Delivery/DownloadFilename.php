<?php

declare(strict_types=1);

namespace Lisowiecw\MediaLibrary\Delivery;

use Closure;
use Illuminate\Support\Str;
use Lisowiecw\MediaLibrary\Ingest\ReadableName;
use Lisowiecw\MediaLibrary\Models\MediaAsset;
use Symfony\Component\HttpFoundation\HeaderUtils;

/**
 * What a Media Asset is called once it has been saved to the viewer's disk.
 *
 * One resolver answers for the whole application, and both places that can
 * name a saved file ask it: the Delivery route on the way out, and the Stored
 * headers written at upload. Two derivations of the same name would drift, and
 * a disk that ignores response overrides serves the stored one, so both ask
 * here. The Stored header still binds at upload and is never rewritten, so it
 * holds the answer as it stood then rather than the answer as it stands.
 *
 * The resolver is handed the asset and nothing else. An asset can be attached
 * in many places, so there is no single host to hand it, and the stored header
 * is written before any attachment exists.
 */
final class DownloadFilename
{
    /**
     * Registered once, on the plugin, and read from every panel and from the
     * queue alike, which is why it lives here rather than on a panel.
     */
    private static ?Closure $resolver = null;

    /**
     * What a file is called when nothing else about it can be: no resolver
     * answer, no name of its own, not even an identifier. A saved file has to
     * arrive called something, and this is what a browser calls one anyway.
     */
    private const string LAST_RESORT = 'download';

    public static function resolveUsing(?Closure $resolver): void
    {
        self::$resolver = $resolver;
    }

    public static function forget(): void
    {
        self::$resolver = null;
    }

    /**
     * What the file is called, scrubbed by the Readable name rules whoever
     * produced it. A resolver is application code rather than a trusted
     * source, so its answer is treated exactly like a client filename: a
     * resolver cannot inject a header, a path or a bidi override.
     *
     * An answer that scrubs away to nothing falls back to the package's own,
     * and one empty in its turn falls back to the asset's own identifier.
     */
    public static function for(MediaAsset $asset): string
    {
        $resolved = self::$resolver === null ? null : (string) (self::$resolver)($asset);

        // Where there is no resolver, or its answer scrubs away to nothing,
        // the package's own answer stands: the name the uploader's file had,
        // and the editable Display name only where there is none.
        return self::presentable($resolved, $asset)
            ?? self::presentable($asset->original_client_filename, $asset)
            ?? self::presentable($asset->display_name, $asset)
            ?? self::presentable($asset->ulid, $asset)
            ?? self::LAST_RESORT;
    }

    /**
     * The whole `Content-Disposition` value, built rather than concatenated,
     * so a quote in the name is escaped and a non-ASCII name still arrives
     * intact beside an ASCII fallback.
     */
    public static function header(Disposition $disposition, MediaAsset $asset): string
    {
        $filename = self::for($asset);

        return HeaderUtils::makeDisposition($disposition->value, $filename, self::fallback($filename, $asset));
    }

    /**
     * Scrub, then append the asset's recorded extension where the answer is a
     * stem without one, so a resolver naming a file after a product title
     * still hands the browser something it can open.
     */
    private static function presentable(?string $filename, MediaAsset $asset): ?string
    {
        if ($filename === null) {
            return null;
        }

        $name = ReadableName::from($filename);

        if ($name->originalClientFilename === '') {
            return null;
        }

        return $name->extension === null
            ? self::withExtension($name->originalClientFilename, $asset)
            : $name->originalClientFilename;
    }

    private static function withExtension(string $stem, MediaAsset $asset): string
    {
        return $asset->extension === null ? $stem : $stem.'.'.$asset->extension;
    }

    /**
     * The ASCII name a client that cannot read the encoded one falls back to.
     * It has to be printable ASCII and free of percent signs; where a name
     * transliterates to nothing at all, the asset's identifier stands in.
     */
    private static function fallback(string $filename, MediaAsset $asset): string
    {
        $fallback = str_replace('%', '', Str::ascii($filename));

        return trim($fallback) === '' ? self::presentable($asset->ulid, $asset) ?? self::LAST_RESORT : $fallback;
    }
}
