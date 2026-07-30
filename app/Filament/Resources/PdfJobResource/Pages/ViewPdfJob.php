<?php

namespace App\Filament\Resources\PdfJobResource\Pages;

use App\Filament\Resources\PdfJobResource;
use Filament\Actions;
use Filament\Infolists\Components\Grid;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\Section;
use Filament\Infolists\Components\TextEntry;
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
                            TextEntry::make('raw_diagnostics')
                                ->label('OCR Diagnostics')
                                ->html()
                                ->columnSpanFull()
                                ->hidden(fn ($record) => blank($record->raw_diagnostics))
                                ->formatStateUsing(function ($state): string {
                                    if (blank($state)) {
                                        return '';
                                    }

                                    $data = is_string($state) ? json_decode($state, true) : $state;
                                    if (! is_array($data)) {
                                        return '<em style="color:#6b7280">No diagnostic data</em>';
                                    }

                                    $scoredLines = is_array($data['scored_lines'] ?? null) ? $data['scored_lines'] : [];
                                    $engine = htmlspecialchars((string) ($data['engine'] ?? 'unknown'));
                                    $pageW = round((float) ($data['page_width'] ?? 0), 0);
                                    $pageH = round((float) ($data['page_height'] ?? 0), 0);
                                    $boxCount = count($scoredLines);

                                    if ($boxCount === 0) {
                                        return "<details style='font-size:0.8rem'>
                                            <summary style='cursor:pointer;color:#6b7280'>
                                                Engine: <strong>{$engine}</strong> · {$pageW}×{$pageH}px · No OCR boxes
                                            </summary>
                                        </details>";
                                    }

                                    $rows = '';
                                    foreach ($scoredLines as $line) {
                                        if (! is_array($line)) {
                                            continue;
                                        }
                                        $text = htmlspecialchars(mb_strimwidth((string) ($line['text'] ?? ''), 0, 80, '…'));
                                        $conf = round((float) ($line['confidence'] ?? 0) * 100, 1);
                                        $chars = (int) ($line['char_count'] ?? mb_strlen((string) ($line['text'] ?? '')));
                                        $xStart = round((float) ($line['x_start'] ?? 0));
                                        $xEnd = round((float) ($line['x_end'] ?? 0));
                                        $height = round((float) ($line['height'] ?? 0));
                                        $y = round((float) ($line['y'] ?? 0));

                                        $confColor = $conf >= 90 ? '#15803d' : ($conf >= 60 ? '#b45309' : '#b91c1c');

                                        $rows .= "<tr>
                                            <td style='padding:3px 8px;border:1px solid #e5e7eb;font-family:monospace;font-size:0.72rem;max-width:280px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap'>{$text}</td>
                                            <td style='padding:3px 8px;border:1px solid #e5e7eb;font-size:0.72rem;color:{$confColor};font-weight:700;text-align:right'>{$conf}%</td>
                                            <td style='padding:3px 8px;border:1px solid #e5e7eb;font-size:0.72rem;text-align:right'>{$chars}</td>
                                            <td style='padding:3px 8px;border:1px solid #e5e7eb;font-size:0.72rem;text-align:right'>{$xStart}–{$xEnd}</td>
                                            <td style='padding:3px 8px;border:1px solid #e5e7eb;font-size:0.72rem;text-align:right'>{$height}</td>
                                            <td style='padding:3px 8px;border:1px solid #e5e7eb;font-size:0.72rem;text-align:right'>{$y}</td>
                                        </tr>";
                                    }

                                    return "<details style='font-size:0.8rem'>
                                        <summary style='cursor:pointer;user-select:none;color:#4b5563;padding:4px 0'>
                                            Engine: <strong style='color:#111827'>{$engine}</strong>
                                            &nbsp;·&nbsp; {$pageW}×{$pageH}px
                                            &nbsp;·&nbsp; {$boxCount} OCR boxes — <span style='color:#2563eb'>click to expand</span>
                                        </summary>
                                        <div style='overflow-x:auto;margin-top:8px'>
                                            <table style='border-collapse:collapse;min-width:600px'>
                                                <thead>
                                                    <tr style='background:#f9fafb'>
                                                        <th style='padding:4px 8px;border:1px solid #e5e7eb;font-size:0.7rem;text-align:left;white-space:nowrap'>Text</th>
                                                        <th style='padding:4px 8px;border:1px solid #e5e7eb;font-size:0.7rem;text-align:right;white-space:nowrap'>Conf.</th>
                                                        <th style='padding:4px 8px;border:1px solid #e5e7eb;font-size:0.7rem;text-align:right;white-space:nowrap'>Chars</th>
                                                        <th style='padding:4px 8px;border:1px solid #e5e7eb;font-size:0.7rem;text-align:right;white-space:nowrap'>X (px)</th>
                                                        <th style='padding:4px 8px;border:1px solid #e5e7eb;font-size:0.7rem;text-align:right;white-space:nowrap'>H (px)</th>
                                                        <th style='padding:4px 8px;border:1px solid #e5e7eb;font-size:0.7rem;text-align:right;white-space:nowrap'>Y (px)</th>
                                                    </tr>
                                                </thead>
                                                <tbody>{$rows}</tbody>
                                            </table>
                                        </div>
                                    </details>";
                                }),
                        ]),
                ]),

        ]);
    }
}
