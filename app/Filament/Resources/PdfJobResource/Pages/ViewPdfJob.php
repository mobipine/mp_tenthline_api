<?php

namespace App\Filament\Resources\PdfJobResource\Pages;

use App\Filament\Resources\PdfJobResource;
use Filament\Actions;
use Filament\Infolists\Components\Grid;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\Section;
use Filament\Infolists\Components\TextEntry;
use Filament\Infolists\Components\ViewEntry;
use Filament\Infolists\Infolist;
use Filament\Resources\Pages\ViewRecord;
use Filament\Support\Enums\FontWeight;

class ViewPdfJob extends ViewRecord
{
    protected static string $resource = PdfJobResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\EditAction::make(),
        ];
    }

    public function infolist(Infolist $infolist): Infolist
    {
        return $infolist->schema([

            Section::make('Job Overview')->schema([
                Grid::make(3)->schema([
                    TextEntry::make('filename')->weight(FontWeight::SemiBold),
                    TextEntry::make('status')
                        ->badge()
                        ->color(fn (string $state): string => match ($state) {
                            'completed'        => 'success',
                            'processing'       => 'info',
                            'awaiting_payment' => 'warning',
                            'failed'           => 'danger',
                            default            => 'gray',
                        }),
                    TextEntry::make('user.email')->label('User'),
                    TextEntry::make('total_pages')->label('Total Pages')->numeric(),
                    TextEntry::make('processed_pages')->label('Processed Pages')->numeric(),
                    TextEntry::make('created_at')->dateTime(),
                ]),
                TextEntry::make('error_message')
                    ->label('Error')
                    ->color('danger')
                    ->columnSpanFull()
                    ->hidden(fn ($record) => blank($record->error_message)),
            ]),

            Section::make('Processing Report')
                ->hidden(fn ($record) => $record->processingReport === null)
                ->schema([
                    Grid::make(4)->schema([
                        TextEntry::make('processingReport.uploaded_pages')
                            ->label('Total Pages'),
                        TextEntry::make('processingReport.successful_pages')
                            ->label('Successful')
                            ->color('success'),
                        TextEntry::make('processingReport.low_confidence_pages')
                            ->label('Low Confidence')
                            ->color('warning'),
                        TextEntry::make('processingReport.failed_pages')
                            ->label('Failed')
                            ->color('danger'),
                        TextEntry::make('processingReport.payable_pages')
                            ->label('Billable Pages'),
                        TextEntry::make('processingReport.unit_price')
                            ->label('Unit Price')
                            ->money(fn ($record): string => strtolower($record->processingReport?->currency ?? 'usd')),
                        TextEntry::make('processingReport.total_amount')
                            ->label('Total Amount')
                            ->money(fn ($record): string => strtolower($record->processingReport?->currency ?? 'usd'))
                            ->weight(FontWeight::Bold),
                        TextEntry::make('processingReport.bill_low_confidence_pages')
                            ->label('Bill Low Confidence')
                            ->badge()
                            ->formatStateUsing(fn (bool $state): string => $state ? 'Yes' : 'No')
                            ->color(fn (bool $state): string => $state ? 'warning' : 'gray'),
                    ]),
                ]),

            Section::make('Per-Page Results')
                ->hidden(fn ($record) => ($record->processingReport?->pageResults->isEmpty() ?? true))
                ->schema([
                    RepeatableEntry::make('processingReport.pageResults')
                        ->label('')
                        ->contained(false)
                        ->schema([
                            Grid::make(8)->schema([
                                TextEntry::make('page_number')
                                    ->label('#')
                                    ->weight(FontWeight::Bold),
                                TextEntry::make('status')
                                    ->badge()
                                    ->color(fn ($state): string => match ($state?->value ?? (string) $state) {
                                        'success'        => 'success',
                                        'low_confidence' => 'warning',
                                        'failed'         => 'danger',
                                        default          => 'gray',
                                    })
                                    ->formatStateUsing(fn ($state): string => match ($state?->value ?? (string) $state) {
                                        'success'        => 'Success',
                                        'low_confidence' => 'Low Confidence',
                                        'failed'         => 'Failed',
                                        default          => ucfirst((string) $state),
                                    }),
                                TextEntry::make('ocr_confidence')
                                    ->label('Confidence')
                                    ->formatStateUsing(fn (float $state): string => round($state * 100, 1) . '%'),
                                TextEntry::make('text_box_count')
                                    ->label('Boxes')
                                    ->numeric(),
                                TextEntry::make('extracted_chars')
                                    ->label('Chars')
                                    ->numeric(),
                                TextEntry::make('page_coverage_pct')
                                    ->label('Coverage')
                                    ->formatStateUsing(fn (float $state): string => round($state * 100, 1) . '%'),
                                TextEntry::make('placement_mode')
                                    ->label('Mode')
                                    ->badge()
                                    ->color('gray'),
                                TextEntry::make('is_billable')
                                    ->label('Billable')
                                    ->badge()
                                    ->formatStateUsing(fn (bool $state): string => $state ? 'Yes' : 'No')
                                    ->color(fn (bool $state): string => $state ? 'success' : 'gray'),
                            ]),
                            TextEntry::make('notes')
                                ->label('Notes')
                                ->placeholder('—')
                                ->columnSpanFull()
                                ->hidden(fn ($record) => blank($record->notes)),
                            ViewEntry::make('raw_diagnostics')
                                ->label('OCR Diagnostics')
                                ->view('filament.infolists.ocr-diagnostics')
                                ->columnSpanFull()
                                ->hidden(fn ($record) => blank($record->raw_diagnostics)),
                        ]),
                ]),

        ]);
    }
}
