<?php

namespace App\Filament\Pages\Settings;

use App\Settings\OcrQualitySettings;
use Filament\Forms;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Pages\Page;

class OcrQualitySettingsPage extends Page implements HasForms
{
    use InteractsWithForms;

    protected static ?string $navigationIcon = 'heroicon-o-adjustments-horizontal';
    protected static ?string $navigationGroup = 'TenthLine';
    protected static ?string $navigationLabel = 'OCR Quality';
    protected static ?string $title = 'OCR Quality Settings';
    protected static string $view = 'filament.pages.settings.app-settings-page';

    public ?array $data = [];

    public function mount(OcrQualitySettings $settings): void
    {
        $this->form->fill([
            'min_ocr_confidence' => $settings->min_ocr_confidence,
            'min_text_boxes' => $settings->min_text_boxes,
            'min_extracted_chars' => $settings->min_extracted_chars,
            'min_page_coverage_pct' => $settings->min_page_coverage_pct,
            'bill_low_confidence_pages' => $settings->bill_low_confidence_pages,
        ]);
    }

    public function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make('Quality Thresholds')
                    ->description('Pages not meeting these thresholds are marked low-confidence or failed and excluded from billing.')
                    ->schema([
                        Forms\Components\TextInput::make('min_ocr_confidence')
                            ->label('Minimum OCR confidence (0–1)')
                            ->numeric()
                            ->minValue(0)
                            ->maxValue(1)
                            ->step(0.01)
                            ->required()
                            ->helperText('e.g. 0.6 means 60% average word confidence required.'),
                        Forms\Components\TextInput::make('min_text_boxes')
                            ->label('Minimum text boxes per page')
                            ->numeric()
                            ->minValue(0)
                            ->required(),
                        Forms\Components\TextInput::make('min_extracted_chars')
                            ->label('Minimum extracted characters per page')
                            ->numeric()
                            ->minValue(0)
                            ->required(),
                        Forms\Components\TextInput::make('min_page_coverage_pct')
                            ->label('Minimum page coverage (0–1)')
                            ->numeric()
                            ->minValue(0)
                            ->maxValue(1)
                            ->step(0.01)
                            ->required()
                            ->helperText('e.g. 0.05 means text must cover at least 5% of page area.'),
                    ]),
                Forms\Components\Section::make('Billing')
                    ->schema([
                        Forms\Components\Toggle::make('bill_low_confidence_pages')
                            ->label('Bill low-confidence pages')
                            ->helperText('When enabled, pages classified as low-confidence are included in the payable page count.'),
                    ]),
            ])
            ->statePath('data');
    }

    public function save(OcrQualitySettings $settings): void
    {
        $data = $this->form->getState();
        $settings->min_ocr_confidence = (float) $data['min_ocr_confidence'];
        $settings->min_text_boxes = (int) $data['min_text_boxes'];
        $settings->min_extracted_chars = (int) $data['min_extracted_chars'];
        $settings->min_page_coverage_pct = (float) $data['min_page_coverage_pct'];
        $settings->bill_low_confidence_pages = (bool) $data['bill_low_confidence_pages'];
        $settings->save();
        \Filament\Notifications\Notification::make()
            ->title('Settings saved')
            ->success()
            ->send();
    }
}
