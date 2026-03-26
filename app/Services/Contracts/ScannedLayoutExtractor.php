<?php

namespace App\Services\Contracts;

interface ScannedLayoutExtractor
{
    /**
     * @return array<int, array<string, mixed>>
     */
    public function extract(string $inputPath, array $options = []): array;
}
