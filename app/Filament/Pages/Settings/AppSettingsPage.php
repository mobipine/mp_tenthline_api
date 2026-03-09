<?php

namespace App\Filament\Pages\Settings;

use App\Settings\AppSettings;
use Filament\Forms;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Pages\Page;

class AppSettingsPage extends Page implements HasForms
{
    use InteractsWithForms;
    protected static ?string $navigationIcon = 'heroicon-o-cog-6-tooth';
    protected static ?string $navigationGroup = 'LegalLine';
    protected static ?string $navigationLabel = 'App Settings';
    protected static ?string $title = 'App Settings';
    protected static string $view = 'filament.pages.settings.app-settings-page';

    public ?array $data = [];

    public function mount(AppSettings $settings): void
    {
        $this->form->fill([
            'enable_payment' => $settings->enable_payment,
            'price_per_page' => $settings->price_per_page,
            'currency' => $settings->currency,
            'max_file_size_mb' => $settings->max_file_size_mb,
            'max_pages' => $settings->max_pages,
        ]);
    }

    public function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make('Payment')
                    ->schema([
                        Forms\Components\Toggle::make('enable_payment')
                            ->label('Require payment before upload')
                            ->helperText('When on, users must pay via M-Pesa before they can upload a PDF.'),
                        Forms\Components\TextInput::make('price_per_page')
                            ->label('Price per page')
                            ->numeric()
                            ->minValue(0)
                            ->step(0.01)
                            ->required(),
                        Forms\Components\TextInput::make('currency')
                            ->label('Currency')
                            ->default('KES')
                            ->required(),
                    ]),
                Forms\Components\Section::make('Limits')
                    ->schema([
                        Forms\Components\TextInput::make('max_file_size_mb')
                            ->label('Max file size (MB)')
                            ->numeric()
                            ->minValue(1)
                            ->required(),
                        Forms\Components\TextInput::make('max_pages')
                            ->label('Max pages per document')
                            ->numeric()
                            ->minValue(1)
                            ->required(),
                    ]),
            ])
            ->statePath('data');
    }

    public function save(AppSettings $settings): void
    {
        $data = $this->form->getState();
        $settings->enable_payment = (bool) $data['enable_payment'];
        $settings->price_per_page = (float) $data['price_per_page'];
        $settings->currency = (string) $data['currency'];
        $settings->max_file_size_mb = (int) $data['max_file_size_mb'];
        $settings->max_pages = (int) $data['max_pages'];
        $settings->save();
        $this->dispatch('saved');
    }
}
