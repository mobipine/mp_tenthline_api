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
        $settings = app(LegalContentSettings::class);

        $lastUpdated = fn (?string $iso): string => $iso
            ? 'Last published: ' . \Carbon\Carbon::parse($iso)->format('j M Y, H:i')
            : 'Not published yet.';

        return $form
            ->schema([
                Forms\Components\Section::make('Terms & Conditions')
                    ->description($lastUpdated($settings->terms_updated_at) . ' Use H2 for main sections — they become the table of contents on the public page.')
                    ->collapsible()
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
                    ->description($lastUpdated($settings->privacy_updated_at) . ' Use H2 for main sections — they become the table of contents on the public page.')
                    ->collapsible()
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

        $termsChanged = ($data['terms_and_conditions'] ?: null) !== $settings->terms_and_conditions;
        $privacyChanged = ($data['privacy_policy'] ?: null) !== $settings->privacy_policy;

        $settings->terms_and_conditions = $data['terms_and_conditions'] ?: null;
        $settings->privacy_policy = $data['privacy_policy'] ?: null;

        if ($termsChanged) {
            $settings->terms_updated_at = now()->toIso8601String();
        }
        if ($privacyChanged) {
            $settings->privacy_updated_at = now()->toIso8601String();
        }

        $settings->save();

        \Filament\Notifications\Notification::make()
            ->title('Legal content saved')
            ->body(($termsChanged || $privacyChanged)
                ? 'The public pages have been updated and the "last updated" dates refreshed.'
                : 'No changes detected — timestamps left unchanged.')
            ->success()
            ->send();
    }
}
