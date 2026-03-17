<?php

namespace Tests\Unit;

use App\Services\PdfFpdiCompatibilityService;
use setasign\Fpdi\Fpdi;
use Tests\TestCase;

class PdfFpdiCompatibilityServiceTest extends TestCase
{
    public function test_it_returns_original_path_for_already_supported_pdfs(): void
    {
        $inputPath = sys_get_temp_dir() . '/legalline_test_compatible_original_' . uniqid() . '.pdf';
        $this->createSamplePdf($inputPath, 12);

        $service = app(PdfFpdiCompatibilityService::class);
        $result = $service->resolveProcessablePath($inputPath);

        try {
            $this->assertTrue($result['processable']);
            $this->assertFalse($result['normalized']);
            $this->assertSame($inputPath, $result['path']);
            $this->assertNull($result['temporary_path']);
        } finally {
            $service->cleanup($result['temporary_path']);
            @unlink($inputPath);
        }
    }

    public function test_it_normalizes_object_stream_pdfs_for_fpdi(): void
    {
        if (! $this->commandExists('qpdf')) {
            $this->markTestSkipped('qpdf command not available on this machine.');
        }

        $basePath = sys_get_temp_dir() . '/legalline_test_qpdf_base_' . uniqid() . '.pdf';
        $incompatiblePath = sys_get_temp_dir() . '/legalline_test_qpdf_incompatible_' . uniqid() . '.pdf';
        $this->createSamplePdf($basePath, 14);
        $this->createObjectStreamPdf($basePath, $incompatiblePath);

        $this->assertFalse($this->canOpenWithFpdi($incompatiblePath));

        $service = app(PdfFpdiCompatibilityService::class);
        $result = $service->resolveProcessablePath($incompatiblePath);

        try {
            $this->assertTrue($result['processable']);
            $this->assertTrue($result['normalized']);
            $this->assertNotSame($incompatiblePath, $result['path']);
            $this->assertSame($result['path'], $result['temporary_path']);
            $this->assertTrue($this->canOpenWithFpdi($result['path']));
        } finally {
            $service->cleanup($result['temporary_path']);
            @unlink($basePath);
            @unlink($incompatiblePath);
        }
    }

    private function createSamplePdf(string $path, int $lineCount): void
    {
        $pdf = new Fpdi('P', 'pt');
        $pdf->AddPage();
        $pdf->SetFont('Helvetica', '', 12);

        for ($line = 1; $line <= $lineCount; $line++) {
            $y = 72 + ($line * 20);
            $pdf->Text(72, $y, "Sample line {$line} text for compatibility");
        }

        $pdf->Output('F', $path);
    }

    private function createObjectStreamPdf(string $inputPath, string $outputPath): void
    {
        $command = sprintf(
            '%s %s --object-streams=generate %s 2>&1',
            escapeshellcmd($this->commandPath('qpdf')),
            escapeshellarg($inputPath),
            escapeshellarg($outputPath)
        );

        $output = [];
        $exitCode = 0;
        exec($command, $output, $exitCode);

        $this->assertSame(0, $exitCode, implode("\n", $output));
        $this->assertFileExists($outputPath);
    }

    private function canOpenWithFpdi(string $path): bool
    {
        try {
            $pdf = new Fpdi('P', 'pt');
            $pdf->setSourceFile($path);

            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    private function commandExists(string $command): bool
    {
        return $this->commandPath($command) !== null;
    }

    private function commandPath(string $command): ?string
    {
        $path = shell_exec(sprintf('command -v %s 2>/dev/null', escapeshellarg($command)));
        $path = is_string($path) ? trim($path) : '';

        return $path !== '' ? $path : null;
    }
}
