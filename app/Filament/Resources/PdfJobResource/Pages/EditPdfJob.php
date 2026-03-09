<?php

namespace App\Filament\Resources\PdfJobResource\Pages;

use App\Filament\Resources\PdfJobResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditPdfJob extends EditRecord
{
    protected static string $resource = PdfJobResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\ViewAction::make(),
            Actions\DeleteAction::make(),
        ];
    }
}
