<?php

use App\Models\PdfJob;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;

Route::get('/', function () {
    // return view('welcome');

    //redirect to the filament admin
    return redirect()->to('admin');
});

// Serves job PDFs (original upload / tenthlined output) to admins, inline or as download.
Route::get('/admin/pdf-jobs/{pdfJob}/file/{type}', function (PdfJob $pdfJob, string $type) {
    abort_unless(auth()->check() && auth()->user()->hasRole('super_admin'), 403);
    abort_unless(in_array($type, ['original', 'processed'], true), 404);

    $path = "pdf-jobs/{$pdfJob->id}/" . ($type === 'original' ? 'input.pdf' : 'output.pdf');
    abort_unless(Storage::disk('local')->exists($path), 404, 'File no longer in storage.');

    $base = pathinfo($pdfJob->filename ?: 'document.pdf', PATHINFO_FILENAME);
    $filename = $base . ($type === 'original' ? '-original.pdf' : '-tenthlined.pdf');

    if (request()->boolean('download')) {
        return Storage::disk('local')->download($path, $filename);
    }

    return response()->file(Storage::disk('local')->path($path), [
        'Content-Type' => 'application/pdf',
        'Content-Disposition' => 'inline; filename="' . str_replace('"', '', $filename) . '"',
    ]);
})->middleware('auth')->name('admin.pdf-jobs.file');
