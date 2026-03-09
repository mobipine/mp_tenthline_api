<?php

namespace App\Filament\Resources\PdfJobResource\Pages;

use App\Filament\Resources\PdfJobResource;
use Filament\Actions;
use Filament\Resources\Pages\CreateRecord;

class CreatePdfJob extends CreateRecord
{
    protected static string $resource = PdfJobResource::class;
}
