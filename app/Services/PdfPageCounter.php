<?php

namespace App\Services;

use setasign\Fpdi\Fpdi;

class PdfPageCounter
{
    public function countPages(string $filePath): int
    {
        $pdf = new Fpdi('P', 'pt');

        return (int) $pdf->setSourceFile($filePath);
    }
}
