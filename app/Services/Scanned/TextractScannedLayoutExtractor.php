<?php

namespace App\Services\Scanned;

use App\Services\Contracts\ScannedLayoutExtractor;
use InvalidArgumentException;

class TextractScannedLayoutExtractor implements ScannedLayoutExtractor
{
    public function __construct(
        private readonly TextractLineNormalizer $lineNormalizer
    ) {}

    /**
     * @return array<int, array<string, mixed>>
     */
    public function extract(string $inputPath, array $options = []): array
    {
        $blocks = $options['blocks'] ?? null;
        if (! is_array($blocks)) {
            throw new InvalidArgumentException('TextractScannedLayoutExtractor expects an array of Textract blocks in options["blocks"].');
        }

        $pageDimensions = is_array($options['page_dimensions'] ?? null) ? $options['page_dimensions'] : [];

        return $this->lineNormalizer->normalize($blocks, $pageDimensions, $options);
    }
}
