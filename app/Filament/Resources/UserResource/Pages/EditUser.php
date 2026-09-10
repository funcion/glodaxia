<?php

namespace App\Filament\Resources\UserResource\Pages;

use App\Filament\Resources\UserResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditUser extends EditRecord
{
    protected static string $resource = UserResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
        ];
    }

    /**
     * Map virtual bilingual fields to Spatie HasTranslations JSON structure before saving.
     */
    protected function mutateFormDataBeforeSave(array $data): array
    {
        $record = $this->getRecord();

        foreach ([
            'name' => ['en' => $data['name_en'] ?? null, 'es' => $data['name_es'] ?? null],
            'bio'  => ['en' => $data['bio_en'] ?? null, 'es' => $data['bio_es'] ?? null],
        ] as $field => $translations) {
            foreach ($translations as $locale => $value) {
                if ($value !== null) {
                    $record->setTranslation($field, $locale, $value);
                }
            }
        }

        if (filled($data['password'] ?? null)) {
            $record->password = \Illuminate\Support\Facades\Hash::make($data['password']);
        }

        $record->save();

        if (request()->hasSession() && auth()->id() === $record->id && filled($data['password'] ?? null)) {
            request()->session()->put([
                'password_hash_' . \Filament\Facades\Filament::getAuthGuard() => $record->password,
            ]);
        }

        // Remove virtual and password fields from $data to prevent Filament from trying to set them directly
        unset($data['name_en'], $data['name_es'], $data['bio_en'], $data['bio_es'], $data['password']);

        return $data;
    }
}
