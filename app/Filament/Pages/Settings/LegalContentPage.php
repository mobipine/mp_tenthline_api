<?php

namespace App\Filament\Pages\Settings;

use App\Settings\LegalContentSettings;
use Filament\Forms;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Pages\Page;

class LegalContentPage extends Page implements HasForms
{
    use InteractsWithForms;

    protected static ?string $navigationIcon = 'heroicon-o-document-text';
    protected static ?string $navigationGroup = 'TenthLine';
    protected static ?string $navigationLabel = 'Legal Content';
    protected static ?string $title = 'Legal Content';
    protected static string $view = 'filament.pages.settings.app-settings-page';

    public ?array $data = [];

    public function mount(LegalContentSettings $settings): void
    {
        $this->form->fill([
            'terms_and_conditions' => $settings->terms_and_conditions,
            'privacy_policy' => $settings->privacy_policy,
        ]);
    }

    public function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make('Terms & Conditions')
                    ->schema([
                        Forms\Components\RichEditor::make('terms_and_conditions')
                            ->label('')
                            ->columnSpanFull()
                            ->toolbarButtons([
                                'bold', 'italic', 'underline', 'strike',
                                'h2', 'h3', 'bulletList', 'orderedList',
                                'link', 'blockquote', 'undo', 'redo',
                            ]),
                    ]),
                Forms\Components\Section::make('Privacy Policy')
                    ->schema([
                        Forms\Components\RichEditor::make('privacy_policy')
                            ->label('')
                            ->columnSpanFull()
                            ->toolbarButtons([
                                'bold', 'italic', 'underline', 'strike',
                                'h2', 'h3', 'bulletList', 'orderedList',
                                'link', 'blockquote', 'undo', 'redo',
                            ]),
                    ]),
            ])
            ->statePath('data');
    }

    public function save(LegalContentSettings $settings): void
    {
        $data = $this->form->getState();
        $settings->terms_and_conditions = $data['terms_and_conditions'] ?: null;
        $settings->privacy_policy = $data['privacy_policy'] ?: null;
        $settings->terms_updated_at = $data['terms_and_conditions']
            ? now()->toIso8601String()
            : $settings->terms_updated_at;
        $settings->privacy_updated_at = $data['privacy_policy']
            ? now()->toIso8601String()
            : $settings->privacy_updated_at;
        $settings->save();
        $this->dispatch('saved');
    }
}
