<?php

namespace App\Filament\Resources\PdfJobResource\Pages;

use App\Filament\Resources\PdfJobResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListPdfJobs extends ListRecords
{
    protected static string $resource = PdfJobResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }
}
