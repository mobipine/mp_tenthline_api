<?php

namespace App\Filament\Resources\SupportTicketResource\Pages;

use App\Enums\SupportTicketStatus;
use App\Filament\Resources\PaymentResource;
use App\Filament\Resources\PdfJobResource;
use App\Filament\Resources\SupportTicketResource;
use App\Filament\Resources\UserResource;
use App\Models\SupportTicket;
use Filament\Actions;
use Filament\Infolists\Components\Actions as InfolistActions;
use Filament\Infolists\Components\Actions\Action;
use Filament\Infolists\Components\Grid;
use Filament\Infolists\Components\Section;
use Filament\Infolists\Components\TextEntry;
use Filament\Infolists\Infolist;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Filament\Support\Enums\FontWeight;
use Illuminate\Support\Facades\Storage;

class ViewSupportTicket extends ViewRecord
{
    protected static string $resource = SupportTicketResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('resolve')
                ->label('Mark Resolved')
                ->icon('heroicon-o-check-circle')
                ->color('success')
                ->requiresConfirmation()
                ->modalHeading('Resolve this ticket?')
                ->modalDescription('The ticket will be marked as resolved and the resolution time recorded.')
                ->visible(fn (): bool => ! $this->record->status->isTerminal())
                ->action(function (): void {
                    $this->record->markResolved();

                    Notification::make()
                        ->title('Ticket resolved')
                        ->body("Ticket {$this->record->reference} marked as resolved.")
                        ->success()
                        ->send();
                }),
            Actions\EditAction::make(),
        ];
    }

    public function infolist(Infolist $infolist): Infolist
    {
        $fileExists = fn (SupportTicket $record, string $file): bool => $record->pdf_job_id !== null
            && Storage::disk('local')->exists("pdf-jobs/{$record->pdf_job_id}/{$file}");

        return $infolist->schema([

            Section::make('Ticket')->schema([
                Grid::make(3)->schema([
                    TextEntry::make('reference')
                        ->copyable()
                        ->weight(FontWeight::SemiBold),
                    TextEntry::make('status')
                        ->badge()
                        ->color(fn (SupportTicketStatus $state): string => match ($state) {
                            SupportTicketStatus::Open => 'warning',
                            SupportTicketStatus::Investigating => 'info',
                            SupportTicketStatus::WaitingForCustomer => 'primary',
                            SupportTicketStatus::Resolved => 'success',
                            SupportTicketStatus::Closed => 'gray',
                        })
                        ->formatStateUsing(fn (SupportTicketStatus $state): string => $state->label()),
                    TextEntry::make('created_at')
                        ->label('Raised at')
                        ->dateTime(),
                    TextEntry::make('email')
                        ->copyable(),
                    TextEntry::make('resolved_at')
                        ->dateTime()
                        ->placeholder('Not resolved yet'),
                ]),
                TextEntry::make('subject')
                    ->weight(FontWeight::SemiBold)
                    ->columnSpanFull(),
                TextEntry::make('description')
                    ->columnSpanFull(),
                TextEntry::make('admin_notes')
                    ->placeholder('—')
                    ->columnSpanFull(),
            ]),

            Section::make('Linked Records')->schema([
                Grid::make(3)->schema([
                    TextEntry::make('user.email')
                        ->label('Raised by')
                        ->placeholder('Guest (no account)')
                        ->color('primary')
                        ->icon('heroicon-o-user')
                        ->url(fn (SupportTicket $record): ?string => $record->user_id
                            ? UserResource::getUrl('view', ['record' => $record->user_id])
                            : null),
                    TextEntry::make('pdfJob.filename')
                        ->label('Document job')
                        ->placeholder('No document linked')
                        ->color('primary')
                        ->icon('heroicon-o-document-text')
                        ->url(fn (SupportTicket $record): ?string => $record->pdf_job_id
                            ? PdfJobResource::getUrl('view', ['record' => $record->pdf_job_id])
                            : null),
                    TextEntry::make('pdfJob.id')
                        ->label('Job ID')
                        ->copyable()
                        ->visible(fn (SupportTicket $record): bool => $record->pdf_job_id !== null),
                    TextEntry::make('pdfJob.status')
                        ->label('Job status')
                        ->badge()
                        ->color(fn (string $state): string => match ($state) {
                            'completed'        => 'success',
                            'processing'       => 'info',
                            'awaiting_payment' => 'warning',
                            'failed'           => 'danger',
                            default            => 'gray',
                        })
                        ->visible(fn (SupportTicket $record): bool => $record->pdf_job_id !== null),
                    TextEntry::make('pdfJob.payment.reference')
                        ->label('Payment')
                        ->placeholder('No payment on this job')
                        ->color('primary')
                        ->icon('heroicon-o-currency-dollar')
                        ->url(fn (SupportTicket $record): ?string => ($payment = $record->pdfJob?->payment)
                            ? PaymentResource::getUrl('view', ['record' => $payment->id])
                            : null)
                        ->visible(fn (SupportTicket $record): bool => $record->pdf_job_id !== null),
                    TextEntry::make('pdfJob.payment.amount')
                        ->label('Amount')
                        ->money(fn (SupportTicket $record): string => strtolower($record->pdfJob?->payment?->currency ?? 'kes'))
                        ->visible(fn (SupportTicket $record): bool => $record->pdfJob?->payment !== null),
                    TextEntry::make('pdfJob.payment.status')
                        ->label('Payment status')
                        ->badge()
                        ->color(fn (string $state): string => match ($state) {
                            'completed' => 'success',
                            'pending'   => 'warning',
                            'failed'    => 'danger',
                            default     => 'gray',
                        })
                        ->visible(fn (SupportTicket $record): bool => $record->pdfJob?->payment !== null),
                ]),
            ]),

            Section::make('Documents')
                ->description('Original upload and the tenthlined output for the linked job.')
                ->visible(fn (SupportTicket $record): bool => $record->pdf_job_id !== null)
                ->schema([
                    TextEntry::make('storage_notice')
                        ->label('')
                        ->getStateUsing(fn (): string => 'The files for this job have been deleted from storage (retention policy).')
                        ->color('warning')
                        ->icon('heroicon-o-exclamation-triangle')
                        ->visible(fn (SupportTicket $record): bool => ! $fileExists($record, 'input.pdf') && ! $fileExists($record, 'output.pdf')),
                    InfolistActions::make([
                        Action::make('view_original')
                            ->label('View original')
                            ->icon('heroicon-o-eye')
                            ->color('gray')
                            ->url(fn (SupportTicket $record): string => route('admin.pdf-jobs.file', ['pdfJob' => $record->pdf_job_id, 'type' => 'original']), shouldOpenInNewTab: true)
                            ->visible(fn (SupportTicket $record): bool => $fileExists($record, 'input.pdf')),
                        Action::make('download_original')
                            ->label('Download original')
                            ->icon('heroicon-o-arrow-down-tray')
                            ->color('gray')
                            ->url(fn (SupportTicket $record): string => route('admin.pdf-jobs.file', ['pdfJob' => $record->pdf_job_id, 'type' => 'original', 'download' => 1]))
                            ->visible(fn (SupportTicket $record): bool => $fileExists($record, 'input.pdf')),
                        Action::make('view_processed')
                            ->label('View tenthlined')
                            ->icon('heroicon-o-eye')
                            ->color('primary')
                            ->url(fn (SupportTicket $record): string => route('admin.pdf-jobs.file', ['pdfJob' => $record->pdf_job_id, 'type' => 'processed']), shouldOpenInNewTab: true)
                            ->visible(fn (SupportTicket $record): bool => $fileExists($record, 'output.pdf')),
                        Action::make('download_processed')
                            ->label('Download tenthlined')
                            ->icon('heroicon-o-arrow-down-tray')
                            ->color('primary')
                            ->url(fn (SupportTicket $record): string => route('admin.pdf-jobs.file', ['pdfJob' => $record->pdf_job_id, 'type' => 'processed', 'download' => 1]))
                            ->visible(fn (SupportTicket $record): bool => $fileExists($record, 'output.pdf')),
                    ]),
                ]),

        ]);
    }
}
