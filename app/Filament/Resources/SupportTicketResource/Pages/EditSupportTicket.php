<?php

namespace App\Filament\Resources\SupportTicketResource\Pages;

use App\Filament\Resources\SupportTicketResource;
use App\Enums\SupportTicketStatus;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditSupportTicket extends EditRecord
{
    protected static string $resource = SupportTicketResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\ViewAction::make(),
            Actions\DeleteAction::make(),
        ];
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        $status = SupportTicketStatus::tryFrom($data['status'] ?? '');

        if ($status && $status->isTerminal() && ! $this->record->resolved_at) {
            $data['resolved_at'] = now()->toDateTimeString();
        }

        return $data;
    }
}
