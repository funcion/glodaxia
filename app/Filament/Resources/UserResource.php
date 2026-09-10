<?php

namespace App\Filament\Resources;

use App\Filament\Resources\UserResource\Pages;
use App\Models\User;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Resources\Resource;
use BezhanSalleh\FilamentShield\Traits\HasShieldFormComponents;
use Filament\Forms\Components\CheckboxList;
use Filament\Tables;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ToggleColumn;
use Filament\Tables\Table;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Hash;
use Filament\Schemas\Components\Utilities\Get;
use Illuminate\Validation\Rules\Password;

class UserResource extends Resource
{
    use HasShieldFormComponents;

    protected static ?string $model = User::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-users';
    
    protected static string|\UnitEnum|null $navigationGroup = 'Seguridad y Roles';

    protected static ?string $modelLabel = 'Usuario';
    protected static ?string $pluralModelLabel = 'Usuarios';

    public static function form(Schema $form): Schema
    {
        return $form
            ->components([
                Section::make('Credenciales y Cuenta')
                    ->columnSpanFull()
                    ->schema([
                        TextInput::make('email')
                            ->label('Correo Electrónico')
                            ->email()
                            ->required()
                            ->unique(User::class, 'email', ignoreRecord: true)
                            ->columnSpanFull(),
                        TextInput::make('current_password')
                            ->label('Contraseña Actual')
                            ->password()
                            ->revealable()
                            ->currentPassword(condition: fn (Get $get): bool => filled($get('password')))
                            ->visible(fn (string $context, ?User $record): bool => $context === 'edit' && auth()->id() === $record?->id)
                            ->required(fn (Get $get): bool => filled($get('password')))
                            ->dehydrated(false)
                            ->columnSpanFull()
                            ->helperText('Ingresa tu contraseña actual para autorizar el cambio.'),
                        TextInput::make('password')
                            ->label(fn (string $context): string => $context === 'create' ? 'Contraseña' : 'Nueva Contraseña')
                            ->password()
                            ->revealable()
                            ->same('password_confirmation')
                            ->rule(Password::default())
                            ->dehydrated(fn ($state) => filled($state))
                            ->required(fn (string $context): bool => $context === 'create')
                            ->columnSpan([
                                'default' => 2,
                                'md' => 1,
                            ])
                            ->helperText(fn (string $context): ?string => $context === 'edit' ? 'Déjalo vacío para mantener la contraseña actual.' : null),
                        TextInput::make('password_confirmation')
                            ->label('Confirmar Nueva Contraseña')
                            ->password()
                            ->revealable()
                            ->required(fn (string $context, Get $get): bool => $context === 'create' || filled($get('password')))
                            ->columnSpan([
                                'default' => 2,
                                'md' => 1,
                            ])
                            ->dehydrated(false),
                        TextInput::make('slug')
                            ->label('Slug / Identificador')
                            ->required()
                            ->unique(User::class, 'slug', ignoreRecord: true)
                            ->columnSpan([
                                'default' => 2,
                                'md' => 1,
                            ]),
                        Toggle::make('is_active')
                            ->label('Cuenta Activa')
                            ->default(true)
                            ->columnSpan([
                                'default' => 2,
                                'md' => 1,
                            ]),
                        FileUpload::make('avatar_url')
                            ->label('Avatar / Foto de Perfil')
                            ->image()
                            ->disk('r2')
                            ->directory('avatars')
                            ->imageCropAspectRatio('1:1')
                            ->maxSize(5120)
                            ->columnSpanFull(),
                    ])->columns(2),

                                Section::make('Roles Asignados')
                    ->description('Selecciona los roles generales asignados a este usuario.')
                    ->columnSpanFull()
                    ->schema([
                        CheckboxList::make('roles')
                            ->label('Roles de Usuario')
                            ->relationship('roles', 'name')
                            ->columns([
                                'sm' => 2,
                                'lg' => 4,
                            ])
                            ->helperText('Marca uno o varios roles (super_admin, admin, redactor, panel_user).')
                            ->columnSpanFull(),
                    ]),

                Section::make('Permisos Específicos Directos (Matriz Visual)')
                    ->description('Otorga o revoca permisos individuales específicos directamente a este usuario mediante la matriz de checkboxes.')
                    ->columnSpanFull()
                    ->collapsed()
                    ->schema([
                        static::getShieldFormComponents(),
                    ]),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                ImageColumn::make('avatar_url')
                    ->label('Avatar')
                    ->disk('public')
                    ->circular()
                    ->defaultImageUrl(fn (User $record): string => 'https://ui-avatars.com/api/?name=' . urlencode($record->name) . '&background=06b6d4&color=ffffff&bold=true'),
                TextColumn::make('name')
                    ->label('Nombre')
                    ->formatStateUsing(fn ($record) => $record->getTranslation('name', 'es') ?: $record->getTranslation('name', 'en'))
                    ->searchable(query: function ($query, $search) {
                        $query->whereRaw("name->>'en' ILIKE ?", ["%{$search}%"])
                              ->orWhereRaw("name->>'es' ILIKE ?", ["%{$search}%"]);
                    })
                    ->sortable(),
                TextColumn::make('email')
                    ->label('Correo Electrónico')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('roles.name')
                    ->label('Roles')
                    ->badge()
                    ->separator(', ')
                    ->color(fn (string $state): string => match ($state) {
                        'super_admin' => 'danger',
                        'admin'       => 'primary',
                        'redactor'    => 'info',
                        'panel_user'  => 'gray',
                        default       => 'gray',
                    }),
                TextColumn::make('permissions.name')
                    ->label('Permisos Directos')
                    ->badge()
                    ->separator(', ')
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),
                ToggleColumn::make('is_active')
                    ->label('Activo'),
                TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                //
            ])
            ->actions([
                \Filament\Actions\EditAction::make(),
                \Filament\Actions\DeleteAction::make()
                    ->hidden(fn (User $record) => $record->hasRole('super_admin') || in_array($record->email, config('global.security.super_admins', []))),
            ])
            ->bulkActions([
                \Filament\Actions\BulkActionGroup::make([
                    \Filament\Actions\DeleteBulkAction::make(),
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
            'index' => Pages\ListUsers::route('/'),
            'create' => Pages\CreateUser::route('/create'),
            'edit' => Pages\EditUser::route('/{record}/edit'),
        ];
    }
}
