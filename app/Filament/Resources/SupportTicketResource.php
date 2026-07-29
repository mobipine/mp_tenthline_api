<?php

namespace App\Filament\Resources;

use App\Enums\SupportTicketStatus;
use App\Filament\Resources\SupportTicketResource\Pages;
use App\Models\SupportTicket;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class SupportTicketResource extends Resource
{
    protected static ?string $model = SupportTicket::class;

    protected static ?string $navigationIcon = 'heroicon-o-chat-bubble-left-right';
    protected static ?string $navigationGroup = 'TenthLine';
    protected static ?string $modelLabel = 'Support Ticket';
    protected static ?string $pluralModelLabel = 'Support Tickets';

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make('Ticket Details')
                    ->schema([
                        Forms\Components\TextInput::make('reference')
                            ->disabled(),
                        Forms\Components\TextInput::make('email')
                            ->email()
                            ->required(),
                        Forms\Components\TextInput::make('subject')
                            ->required()
                            ->columnSpanFull(),
                        Forms\Components\Textarea::make('description')
                            ->rows(5)
                            ->columnSpanFull(),
                    ])
                    ->columns(2),
                Forms\Components\Section::make('Status & Assignment')
                    ->schema([
                        Forms\Components\Select::make('status')
                            ->options(
                                collect(SupportTicketStatus::cases())
                                    ->mapWithKeys(fn (SupportTicketStatus $s) => [$s->value => $s->label()])
                                    ->toArray()
                            )
                            ->required(),
                        Forms\Components\Select::make('user_id')
                            ->label('User')
                            ->relationship('user', 'email')
                            ->searchable()
                            ->preload(),
                        Forms\Components\TextInput::make('pdf_job_id')
                            ->label('Job ID'),
                        Forms\Components\DateTimePicker::make('resolved_at')
                            ->label('Resolved at'),
                    ])
                    ->columns(2),
                Forms\Components\Section::make('Admin Notes')
                    ->schema([
                        Forms\Components\Textarea::make('admin_notes')
                            ->label('')
                            ->rows(4)
                            ->columnSpanFull(),
                    ]),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->columns([
                Tables\Columns\TextColumn::make('reference')
                    ->searchable()
                    ->copyable(),
                Tables\Columns\TextColumn::make('email')
                    ->searchable(),
                Tables\Columns\TextColumn::make('subject')
                    ->limit(50)
                    ->searchable(),
                Tables\Columns\BadgeColumn::make('status')
                    ->colors([
                        'warning' => SupportTicketStatus::Open->value,
                        'info' => SupportTicketStatus::Investigating->value,
                        'primary' => SupportTicketStatus::WaitingForCustomer->value,
                        'success' => SupportTicketStatus::Resolved->value,
                        'gray' => SupportTicketStatus::Closed->value,
                    ])
                    ->formatStateUsing(fn (string $state): string => SupportTicketStatus::from($state)->label()),
                Tables\Columns\TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable(),
                Tables\Columns\TextColumn::make('resolved_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('status')
                    ->options(
                        collect(SupportTicketStatus::cases())
                            ->mapWithKeys(fn (SupportTicketStatus $s) => [$s->value => $s->label()])
                            ->toArray()
                    ),
            ])
            ->actions([
                Tables\Actions\ViewAction::make(),
                Tables\Actions\EditAction::make(),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                ]),
            ]);
    }

    public static function getRelations(): array
    {
        return [];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListSupportTickets::route('/'),
            'view' => Pages\ViewSupportTicket::route('/{record}'),
            'edit' => Pages\EditSupportTicket::route('/{record}/edit'),
        ];
    }
}
