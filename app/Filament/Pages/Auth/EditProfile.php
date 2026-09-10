<?php

namespace App\Filament\Pages\Auth;

use Filament\Auth\Pages\EditProfile as BaseEditProfile;
use Filament\Schemas\Components\Component;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;
use SensitiveParameter;

class EditProfile extends BaseEditProfile
{
    protected static ?string $title = 'Editar Perfil';

    /**
     * Handle translatable attributes when filling form data.
     */
    protected function mutateFormDataBeforeFill(array $data): array
    {
        $user = $this->getUser();

        // Extract clean string for translatable name attribute
        if (is_array($data['name'] ?? null)) {
            $data['name'] = $data['name'][app()->getLocale()] ?? $data['name']['es'] ?? $data['name']['en'] ?? '';
        } elseif (method_exists($user, 'getTranslation')) {
            $data['name'] = $user->getTranslation('name', app()->getLocale()) ?: ($user->getTranslation('name', 'es') ?: $user->getTranslation('name', 'en'));
        }

        return $data;
    }

    /**
     * Handle translatable attributes when updating record.
     */
    protected function handleRecordUpdate(Model $record, #[SensitiveParameter] array $data): Model
    {
        if (isset($data['name'])) {
            $locale = app()->getLocale();
            $record->setTranslation('name', $locale, $data['name']);
            if (empty($record->getTranslation('name', 'es', false))) {
                $record->setTranslation('name', 'es', $data['name']);
            }
            if (empty($record->getTranslation('name', 'en', false))) {
                $record->setTranslation('name', 'en', $data['name']);
            }
            unset($data['name']);
        }

        if (filled($data['password'] ?? null)) {
            $record->password = Hash::make($data['password']);
            $record->save();

            if (request()->hasSession()) {
                request()->session()->put([
                    'password_hash_' . \Filament\Facades\Filament::getAuthGuard() => $record->password,
                ]);
            }

            unset($data['password']);
        }

        return parent::handleRecordUpdate($record, $data);
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Información Personal')
                    ->description('Actualiza tus datos de identificación y foto de perfil.')
                    ->schema([
                        $this->getNameFormComponent()
                            ->label('Nombre Completo')
                            ->columnSpanFull(),
                        $this->getEmailFormComponent()
                            ->label('Correo Electrónico')
                            ->columnSpanFull(),
                        FileUpload::make('avatar_url')
                            ->label('Avatar / Foto de Perfil')
                            ->image()
                            ->disk('r2')
                            ->directory('avatars')
                            ->imageCropAspectRatio('1:1')
                            ->maxSize(5120)
                            ->columnSpanFull(),
                    ])
                    ->columns(1),

                Section::make('Seguridad de la Cuenta')
                    ->description('Para cambiar tu contraseña actual, ingresa tu contraseña actual para verificar tu identidad y luego define la nueva.')
                    ->schema([
                        $this->getCurrentPasswordFormComponent(),
                        $this->getPasswordFormComponent(),
                        $this->getPasswordConfirmationFormComponent(),
                    ])
                    ->columns(2),
            ]);
    }

    protected function getCurrentPasswordFormComponent(): Component
    {
        return TextInput::make('currentPassword')
            ->label('Contraseña Actual')
            ->password()
            ->revealable()
            ->autocomplete('current-password')
            ->currentPassword(condition: fn (Get $get): bool => filled($get('password')), guard: \Filament\Facades\Filament::getAuthGuard())
            ->required(fn (Get $get): bool => filled($get('password')))
            ->dehydrated(false)
            ->columnSpanFull()
            ->helperText('Ingresa tu contraseña actual solo si deseas cambiarla.');
    }

    protected function getPasswordFormComponent(): Component
    {
        return TextInput::make('password')
            ->label('Nueva Contraseña')
            ->password()
            ->revealable()
            ->rule(Password::default())
            ->autocomplete('new-password')
            ->dehydrated(fn (#[SensitiveParameter] $state): bool => filled($state))
            ->same('passwordConfirmation')
            ->columnSpan([
                'default' => 2,
                'md' => 1,
            ])
            ->helperText('Deja este campo vacío si deseas mantener tu contraseña actual.');
    }

    protected function getPasswordConfirmationFormComponent(): Component
    {
        return TextInput::make('passwordConfirmation')
            ->label('Confirmar Nueva Contraseña')
            ->password()
            ->autocomplete('new-password')
            ->revealable()
            ->required(fn (Get $get): bool => filled($get('password')))
            ->columnSpan([
                'default' => 2,
                'md' => 1,
            ])
            ->dehydrated(false);
    }
}
