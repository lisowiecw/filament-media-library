<?php

declare(strict_types=1);

namespace Lisowiecw\MediaLibrary\Ingest;

use DOMDocument;
use enshrined\svgSanitize\Sanitizer;
use Lisowiecw\MediaLibrary\Exceptions\IngestRefused;
use Lisowiecw\MediaLibrary\Ingest\Svg\StrictTags;
use Throwable;

/**
 * Sanitizing an uploaded SVG so it can be treated as an ordinary inline image.
 * It runs once, at upload, on the bytes about to be written: nothing is ever
 * re-sanitized at rest, and an SVG that cannot be sanitized is refused rather
 * than stored.
 *
 * On public placement it runs a second, narrower pass, because a public asset
 * never reaches the Delivery route and so is never covered by the content
 * policy that carries the residual for every other SVG (ADR-0005).
 */
final readonly class SvgSanitization
{
    /** @var list<string> */
    private const array MIME_TYPES = ['image/svg+xml', 'image/svg'];

    /**
     * The sanitizer is a hard require, so the probe is a fail-closed guard for
     * an installation that has been taken apart rather than a supported mode.
     */
    public function __construct(
        private bool $sanitizerAvailable = true,
    ) {}

    public static function applies(?string $extension, ?string $mimeType): bool
    {
        return ($extension !== null && strtolower($extension) === 'svg')
            || ($mimeType !== null && in_array(strtolower(trim($mimeType)), self::MIME_TYPES, strict: true));
    }

    /**
     * The bytes to write, or a refusal. On a public placement the narrow pass
     * decides, and the element it dropped is found by diffing the two passes,
     * so the message names what actually failed rather than guessing.
     */
    public function sanitize(string $markup, string $readableName, bool $strict): string
    {
        $sanitized = $this->pass($markup, strict: false);

        if ($sanitized === null) {
            throw IngestRefused::unsanitizableSvg($readableName);
        }

        if (! $strict) {
            return $sanitized;
        }

        $narrow = $this->pass($markup, strict: true);

        if ($narrow === null) {
            throw IngestRefused::unsanitizableSvg($readableName);
        }

        $dropped = $this->droppedElement($sanitized, $narrow);

        if ($dropped !== null) {
            throw IngestRefused::strictSvgDropped($readableName, $dropped);
        }

        return $narrow;
    }

    /**
     * One pass, with the three-way failure check the sanitizer needs: a false
     * return, a thrown exception, and a result whose root element is not an
     * `svg`, which is how a file that merely parses gets past the first two.
     */
    private function pass(string $markup, bool $strict): ?string
    {
        if (! $this->sanitizerAvailable || ! class_exists(Sanitizer::class)) {
            return null;
        }

        $sanitizer = new Sanitizer;
        $sanitizer->removeRemoteReferences(true);

        if ($strict) {
            $sanitizer->setAllowedTags(new StrictTags);
        }

        try {
            $sanitized = $sanitizer->sanitize($markup);
        } catch (Throwable) {
            return null;
        }

        if (! is_string($sanitized) || trim($sanitized) === '') {
            return null;
        }

        return $this->rootIsSvg($sanitized) ? $sanitized : null;
    }

    private function rootIsSvg(string $markup): bool
    {
        return $this->read($markup)?->documentElement?->localName === 'svg';
    }

    /**
     * The first element the narrow pass took away, in document order, so the
     * message names one concrete thing to fix rather than a list.
     */
    private function droppedElement(string $sanitized, string $narrow): ?string
    {
        $before = $this->elementsIn($sanitized);
        $after = $this->elementsIn($narrow);

        foreach ($before as $element) {
            $seen = array_search($element, $after, strict: true);

            if ($seen === false) {
                return $element;
            }

            unset($after[$seen]);
        }

        return null;
    }

    /**
     * @return list<string>
     */
    private function elementsIn(string $markup): array
    {
        $document = $this->read($markup);

        if ($document === null) {
            return [];
        }

        $elements = [];

        foreach ($document->getElementsByTagName('*') as $element) {
            $elements[] = $element->localName;
        }

        return $elements;
    }

    private function read(string $markup): ?DOMDocument
    {
        $document = new DOMDocument;

        $previous = libxml_use_internal_errors(true);

        try {
            $loaded = $document->loadXML($markup);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }

        return $loaded === false ? null : $document;
    }
}
