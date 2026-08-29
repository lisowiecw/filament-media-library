<?php

declare(strict_types=1);

namespace Lisowiecw\MediaLibrary\Enums;

/**
 * The named size a Derivative was generated at. The set is fixed by the
 * package and only the dimensions are configurable, so every installation's
 * matrix is the same shape and the key space stays predictable.
 */
enum DerivativeVariant: string
{
    case Thumb = 'thumb';
    case Preview = 'preview';

    /**
     * The fallback edges, used when configuration says nothing. They are the
     * package's promise rather than a suggestion: a variant with no configured
     * edge still has the size its name means.
     */
    private const array DEFAULT_EDGES = [
        'thumb' => 400,
        'preview' => 1600,
    ];

    public const int DEFAULT_QUALITY = 82;

    /**
     * The longest edge, in pixels, this variant is downscaled to. Never an
     * upscale target: an original already smaller than this is encoded at its
     * own size.
     */
    public function edge(): int
    {
        /** @var int|null $edge */
        $edge = config('media-library.derivatives.variants.'.$this->value.'.edge');

        return $edge ?? self::DEFAULT_EDGES[$this->value];
    }

    public function quality(): int
    {
        /** @var int|null $quality */
        $quality = config('media-library.derivatives.quality');

        return $quality ?? self::DEFAULT_QUALITY;
    }

    /**
     * A digest of the settings that produced a Derivative, so staleness is
     * detectable by comparison rather than by inspecting the object. It covers
     * the target edge and the quality and nothing else, so an encoder upgrade
     * never marks a whole library stale.
     */
    public function digest(): string
    {
        return substr(hash('sha256', $this->value.':'.$this->edge().':'.$this->quality()), 0, 16);
    }
}
