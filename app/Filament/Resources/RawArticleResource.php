<?php

namespace App\Filament\Resources;

use App\Filament\Resources\RawArticleResource\Pages;
use App\Models\RawArticle;
use App\Jobs\ProcessArticleWithAIJob;
use Filament\Forms;
use Filament\Schemas\Schema;
use Filament\Schemas\Components\Section;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Filament\Tables\Enums\FiltersLayout;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Illuminate\Database\Eloquent\Builder;

class RawArticleResource extends Resource
{
    protected static ?string $model = RawArticle::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-document-magnifying-glass';
    
    protected static ?string $navigationLabel = 'Noticias Crudas';
    
    protected static ?string $pluralLabel = 'Noticias Crudas';

    protected static string|\UnitEnum|null $navigationGroup = 'Gestión de Contenido';

    public static function form(Schema $form): Schema
    {
        return $form
            ->components([
                Section::make('Laboratorio de Ideas y Entradas')
                    ->description('Aquí llegan las noticias automáticas del RSS o puedes "Sembrar una Idea" para que la IA la procese.')
                    ->columnSpanFull()
                    ->schema([
                        Forms\Components\Select::make('source_id')
                            ->relationship('source', 'name')
                            ->label('Fuente / Origen')
                            ->helperText('Si es una idea manual, puedes dejarlo en blanco o elegir una fuente general.')
                            ->nullable(),
                        Forms\Components\TextInput::make('url')
                            ->label('URL de Referencia (Opcional)')
                            ->placeholder('https://ejemplo.com/noticia-interesante')
                            ->columnSpanFull(),
                    ])->columns(1),
                
                Section::make('Contenido para Procesar')
                    ->description('Escribe un título o un pequeño resumen. Si solo pones un título y le das a "Procesar con IA", el sistema investigará el tema.')
                    ->columnSpanFull()
                    ->schema([
                        Forms\Components\TextInput::make('title')
                            ->label('Título o Semilla de la Noticia')
                            ->placeholder('Ej: OpenAI lanza Sora para el público general este lunes')
                            ->required()
                            ->columnSpanFull(),
                        Forms\Components\Textarea::make('summary')
                            ->label('Resumen o Notas Rápidas')
                            ->placeholder('Escribe aquí puntos clave que quieras que la IA incluya...')
                            ->rows(3)
                            ->columnSpanFull(),
                        Forms\Components\RichEditor::make('content')
                            ->label('Contenido Crudo (Opcional)')
                            ->helperText('Contenido original que la IA usará como base para redactar.')
                            ->columnSpanFull(),
                    ])->columns(1),
                
                Section::make('Estado y Metadatos')
                    ->columnSpanFull()
                    ->schema([
                        Forms\Components\Select::make('status')
                            ->label('Estado de Procesamiento')
                            ->options([
                                'pending' => 'Pendiente',
                                'processing' => 'Procesando',
                                'processed' => 'Procesada',
                                'ignored' => 'Ignorada',
                                'failed' => 'Fallida',
                            ])
                            ->default('pending')
                            ->required(),
                        Forms\Components\TextInput::make('ai_model')
                            ->label('Modelo IA Utilizado')
                            ->disabled()
                            ->columnSpanFull(),
                        Forms\Components\DateTimePicker::make('published_at')
                            ->label('Fecha de Publicación')
                            ->columnSpanFull(),
                    ])->columns(1),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->columns([
                Tables\Columns\TextColumn::make('source.name')
                    ->label('Fuente')
                    ->sortable(),
                Tables\Columns\TextColumn::make('title')
                    ->label('Título')
                    ->searchable()
                    ->limit(50)
                    ->tooltip(fn (RawArticle $record): string => $record->title ?? '')
                    ->url(fn (RawArticle $record): ?string => $record->url, shouldOpenInNewTab: true)
                    ->color(fn (RawArticle $record): ?string => $record->url ? 'primary' : null),
                Tables\Columns\TextColumn::make('metadata.curation.score')
                    ->label('Score IA')
                    ->badge()
                    ->placeholder('—')
                    ->sortable()
                    ->formatStateUsing(fn ($state) => $state !== null ? number_format((float)$state, 1) . ' / 10' : '—')
                    ->color(fn ($state) => match (true) {
                        $state === null => 'gray',
                        (float)$state >= 8.0 => 'success',
                        (float)$state >= 7.0 => 'info',
                        default => 'warning',
                    })
                    ->tooltip(fn (RawArticle $record) => $record->metadata['curation']['reason'] ?? 'Sin evaluación editorial previa.'),
                Tables\Columns\TextColumn::make('status')
                    ->label('Estado')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'pending' => 'gray',
                        'processing' => 'info',
                        'processed' => 'success',
                        'ignored' => 'warning',
                        'failed' => 'danger',
                        default => 'gray',
                    })
                    ->formatStateUsing(fn (string $state): string => match ($state) {
                        'pending' => 'Pendiente',
                        'processing' => 'Procesando',
                        'processed' => 'Procesada',
                        'ignored' => 'Ignorada',
                        'failed' => 'Fallida',
                        default => ucfirst($state),
                    })
                    ->tooltip(fn (RawArticle $record): ?string => match ($record->status) {
                        'failed' => '❌ Fallo: ' . ($record->metadata['last_error']['message'] ?? 'Error durante el procesamiento con IA.'),
                        'ignored' => '⚠️ Descarte: ' . ($record->metadata['curation']['reason'] ?? 'Descartada por los filtros editoriales.'),
                        'processed' => '✅ Procesada y generada correctamente.',
                        'processing' => '⏳ En cola de redacción con IA...',
                        default => null,
                    }),
                Tables\Columns\TextColumn::make('ai_model')
                    ->label('Modelo IA')
                    ->badge()
                    ->placeholder('—')
                    ->sortable()
                    ->searchable()
                    ->color(fn (?string $state): string => config("ai_models.models.{$state}.color") ?? ($state ? 'primary' : 'gray'))
                    ->formatStateUsing(fn (?string $state): string => config("ai_models.models.{$state}.name") ?? ($state ?? '—')),
                Tables\Columns\TextColumn::make('published_at')
                    ->label('Fecha')
                    ->dateTime('d/m/Y H:i')
                    ->sortable(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('periodo')
                    ->label('Período')
                    ->options([
                        'today'      => '📅 Hoy',
                        'yesterday'  => '📅 Ayer',
                        'this_week'  => '🗓️ Última semana (7 días)',
                        'this_month' => '🗓️ Último mes (30 días)',
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        return match ($data['value'] ?? null) {
                            'today'      => $query->where(fn ($q) => $q->whereDate('published_at', now()->today())->orWhere(fn($sq) => $sq->whereNull('published_at')->whereDate('created_at', now()->today()))),
                            'yesterday'  => $query->where(fn ($q) => $q->whereDate('published_at', now()->yesterday())->orWhere(fn($sq) => $sq->whereNull('published_at')->whereDate('created_at', now()->yesterday()))),
                            'this_week'  => $query->where(fn ($q) => $q->where('published_at', '>=', now()->subDays(7))->orWhere(fn($sq) => $sq->whereNull('published_at')->where('created_at', '>=', now()->subDays(7)))),
                            'this_month' => $query->where(fn ($q) => $q->where('published_at', '>=', now()->subDays(30))->orWhere(fn($sq) => $sq->whereNull('published_at')->where('created_at', '>=', now()->subDays(30)))),
                            default      => $query,
                        };
                    }),

                Tables\Filters\Filter::make('rango_personalizado')
                    ->label('Rango Personalizado')
                    ->form([
                        Forms\Components\DatePicker::make('desde')->label('Fecha Desde'),
                        Forms\Components\DatePicker::make('hasta')->label('Fecha Hasta'),
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        return $query
                            ->when($data['desde'] ?? null, fn (Builder $q, $date) => $q->where(fn ($sq) => $sq->whereDate('published_at', '>=', $date)->orWhere(fn($ssq) => $ssq->whereNull('published_at')->whereDate('created_at', '>=', $date))))
                            ->when($data['hasta'] ?? null, fn (Builder $q, $date) => $q->where(fn ($sq) => $sq->whereDate('published_at', '<=', $date)->orWhere(fn($ssq) => $ssq->whereNull('published_at')->whereDate('created_at', '<=', $date))));
                    }),

                Tables\Filters\SelectFilter::make('status')
                    ->label('Estado')
                    ->options([
                        'pending'    => 'Pendiente',
                        'processing' => 'Procesando',
                        'processed'  => 'Procesada',
                        'ignored'    => 'Ignorada',
                        'failed'     => 'Fallida',
                    ]),

                Tables\Filters\SelectFilter::make('ai_model')
                    ->label('Modelo IA')
                    ->options(collect(config('ai_models.models', []))->mapWithKeys(fn ($item, $key) => [$key => $item['name']])->toArray()),
            ])
            ->actions([
                Action::make('procesar_ia')
                    ->label(fn (RawArticle $record) => in_array($record->status, ['ignored', 'failed']) ? 'Forzar Re-procesamiento IA' : 'Procesar con IA')
                    ->icon('heroicon-o-sparkles')
                    ->color(fn (RawArticle $record) => in_array($record->status, ['ignored', 'failed']) ? 'warning' : 'info')
                    ->requiresConfirmation()
                    ->visible(fn (RawArticle $record) => in_array($record->status, ['pending', 'ignored', 'failed']))
                    ->action(function (RawArticle $record) {
                        $record->update(['status' => 'pending']);
                        ProcessArticleWithAIJob::dispatch($record);
                        
                        Notification::make()
                            ->title('Procesamiento iniciado')
                            ->body('La noticia se ha puesto en cola para redacción profesional.')
                            ->success()
                            ->send();
                    }),
                Action::make('procesar_ia_inmediato')
                    ->label('Procesar y Publicar ⚡')
                    ->icon('heroicon-o-bolt')
                    ->color('success')
                    ->requiresConfirmation()
                    ->visible(fn (RawArticle $record) => in_array($record->status, ['pending', 'ignored', 'failed']))
                    ->action(function (RawArticle $record) {
                        $record->update(['status' => 'pending']);
                        ProcessArticleWithAIJob::dispatch($record, true); // Envía true como parámetro de forzar inmediato
                        
                        Notification::make()
                            ->title('Procesamiento prioritario iniciado')
                            ->body('La noticia se está procesando y se publicará inmediatamente al terminar.')
                            ->success()
                            ->send();
                    }),
                \Filament\Actions\EditAction::make(),
            ])
            ->bulkActions([
                \Filament\Actions\BulkActionGroup::make([
                    \Filament\Actions\BulkAction::make('reprocesar_lote_ia')
                        ->label('⚡ Re-procesar con IA (Lote)')
                        ->icon('heroicon-o-sparkles')
                        ->color('warning')
                        ->requiresConfirmation()
                        ->modalHeading('Re-procesar noticias seleccionadas con IA')
                        ->modalDescription('Las noticias seleccionadas se enviarán a la cola de redacción con retardo escalonado de 3 segundos entre cada una para proteger los límites de API.')
                        ->action(function (\Illuminate\Database\Eloquent\Collection $records) {
                            $count = $records->count();
                            foreach ($records->values() as $index => $record) {
                                $record->update(['status' => 'pending']);
                                ProcessArticleWithAIJob::dispatch($record, true)
                                    ->delay(now()->addSeconds($index * 3));
                            }

                            Notification::make()
                                ->title("⚡ {$count} noticias enviadas a la cola de IA")
                                ->body('Se están procesando de forma escalonada con redacción profesional.')
                                ->success()
                                ->send();
                        }),
                    \Filament\Actions\DeleteBulkAction::make(),
                ]),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListRawArticles::route('/'),
            'create' => Pages\CreateRawArticle::route('/create'),
            'edit' => Pages\EditRawArticle::route('/{record}/edit'),
        ];
    }
}
