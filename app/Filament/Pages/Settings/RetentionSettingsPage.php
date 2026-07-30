<?php

namespace App\Filament\Pages\Settings;

use App\Enums\RetentionUnit;
use App\Settings\RetentionSettings;
use Filament\Forms;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Pages\Page;

class RetentionSettingsPage extends Page implements HasForms
{
    use InteractsWithForms;

    protected static ?string $navigationIcon = 'heroicon-o-clock';
    protected static ?string $navigationGroup = 'TenthLine';
    protected static ?string $navigationLabel = 'Retention';
    protected static ?string $title = 'Retention Settings';
    protected static string $view = 'filament.pages.settings.app-settings-page';

    public ?array $data = [];

    public function mount(RetentionSettings $settings): void
    {
        $this->form->fill([
            'retention_value' => $settings->retention_value,
            'retention_unit' => $settings->retention_unit,
            'support_attachment_retention_hours' => $settings->support_attachment_retention_hours,
            'payment_deadline_hours' => $settings->payment_deadline_hours,
        ]);
    }

    public function form(Form $form): Form
    {
        $unitOptions = collect(RetentionUnit::cases())
            ->mapWithKeys(fn (RetentionUnit $u) => [$u->value => ucfirst($u->value)])
            ->toArray();

        return $form
            ->schema([
                Forms\Components\Section::make('PDF File Retention')
                    ->description('How long output PDFs are kept after processing before automatic deletion.')
                    ->schema([
                        Forms\Components\TextInput::make('retention_value')
                            ->label('Retention period')
                            ->numeric()
                            ->minValue(1)
                            ->required(),
                        Forms\Components\Select::make('retention_unit')
                            ->label('Unit')
                            ->options($unitOptions)
                            ->required(),
                    ])
                    ->columns(2),
                Forms\Components\Section::make('Payment Deadline')
                    ->description('How long after processing a job remains in awaiting_payment before it is purged.')
                    ->schema([
                        Forms\Components\TextInput::make('payment_deadline_hours')
                            ->label('Payment deadline (hours)')
                            ->numeric()
                            ->minValue(1)
                            ->required(),
                    ]),
                Forms\Components\Section::make('Support Attachments')
                    ->description('How long support ticket attachments are kept after the ticket is resolved.')
                    ->schema([
                        Forms\Components\TextInput::make('support_attachment_retention_hours')
                            ->label('Support attachment retention (hours)')
                            ->numeric()
                            ->minValue(1)
                            ->required(),
                    ]),
            ])
            ->statePath('data');
    }

    public function save(RetentionSettings $settings): void
    {
        $data = $this->form->getState();
        $settings->retention_value = (int) $data['retention_value'];
        $settings->retention_unit = (string) $data['retention_unit'];
        $settings->support_attachment_retention_hours = (int) $data['support_attachment_retention_hours'];
        $settings->payment_deadline_hours = (int) $data['payment_deadline_hours'];
        $settings->save();
        $this->dispatch('saved');
    }
}
