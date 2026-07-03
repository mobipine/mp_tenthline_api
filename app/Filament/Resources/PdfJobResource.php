<?php

namespace App\Filament\Resources;

use App\Filament\Resources\PdfJobResource\Pages;
use App\Filament\Resources\PdfJobResource\RelationManagers;
use App\Models\PdfJob;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;

class PdfJobResource extends Resource
{
    protected static ?string $model = PdfJob::class;

    protected static ?string $navigationIcon = 'heroicon-o-document-text';
    protected static ?string $navigationGroup = 'TenthLine';
    protected static ?string $modelLabel = 'PDF Job';
    protected static ?string $pluralModelLabel = 'PDF Jobs';

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\TextInput::make('filename')
                    ->required(),
                Forms\Components\TextInput::make('status')
                    ->required(),
                Forms\Components\Select::make('user_id')
                    ->label('User')
                    ->relationship('user', 'email')
                    ->searchable()
                    ->preload(),
                Forms\Components\TextInput::make('total_pages')
                    ->required()
                    ->numeric()
                    ->default(0),
                Forms\Components\TextInput::make('processed_pages')
                    ->required()
                    ->numeric()
                    ->default(0),
                Forms\Components\TextInput::make('progress')
                    ->required()
                    ->numeric()
                    ->default(0),
                Forms\Components\TextInput::make('eta_seconds')
                    ->numeric(),
                Forms\Components\Textarea::make('error_message')
                    ->columnSpanFull(),
                Forms\Components\TextInput::make('output_path'),
                Forms\Components\Select::make('payment_id')
                    ->relationship('payment', 'id'),
                Forms\Components\TextInput::make('line_interval')
                    ->required()
                    ->numeric()
                    ->default(10),
                Forms\Components\TextInput::make('page_count')
                    ->required()
                    ->numeric()
                    ->default(0),
                Forms\Components\TextInput::make('margin')
                    ->required(),
                Forms\Components\TextInput::make('font_size_pt')
                    ->required()
                    ->numeric()
                    ->default(8),
                Forms\Components\DateTimePicker::make('storage_deleted_at'),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('id')
                    ->label('ID')
                    ->searchable(),
                Tables\Columns\TextColumn::make('filename')
                    ->searchable(),
                Tables\Columns\TextColumn::make('status')
                    ->searchable(),
                Tables\Columns\TextColumn::make('user.email')
                    ->label('User')
                    ->searchable(),
                Tables\Columns\TextColumn::make('total_pages')
                    ->numeric()
                    ->sortable(),
                Tables\Columns\TextColumn::make('processed_pages')
                    ->numeric()
                    ->sortable(),
                Tables\Columns\TextColumn::make('progress')
                    ->numeric()
                    ->sortable(),
                Tables\Columns\TextColumn::make('eta_seconds')
                    ->numeric()
                    ->sortable(),
                Tables\Columns\TextColumn::make('output_path')
                    ->searchable(),
                Tables\Columns\TextColumn::make('payment.id')
                    ->searchable(),
                Tables\Columns\TextColumn::make('line_interval')
                    ->numeric()
                    ->sortable(),
                Tables\Columns\TextColumn::make('page_count')
                    ->label('Pages')
                    ->numeric()
                    ->sortable(),
                Tables\Columns\TextColumn::make('margin')
                    ->searchable(),
                Tables\Columns\TextColumn::make('font_size_pt')
                    ->numeric()
                    ->sortable(),
                Tables\Columns\TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('storage_deleted_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('updated_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('status')
                    ->options([
                        'pending' => 'Pending',
                        'processing' => 'Processing',
                        'completed' => 'Completed',
                        'failed' => 'Failed',
                        'deleted' => 'Deleted',
                    ]),
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
        return [
            //
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListPdfJobs::route('/'),
            'view' => Pages\ViewPdfJob::route('/{record}'),
        ];
    }
}
