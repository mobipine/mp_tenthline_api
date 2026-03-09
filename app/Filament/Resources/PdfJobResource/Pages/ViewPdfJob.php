<?php

namespace App\Filament\Resources\PdfJobResource\Pages;

use App\Filament\Resources\PdfJobResource;
use Filament\Actions;
use Filament\Resources\Pages\ViewRecord;

class ViewPdfJob extends ViewRecord
{
    protected static string $resource = PdfJobResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\EditAction::make(),
        ];
    }
}
