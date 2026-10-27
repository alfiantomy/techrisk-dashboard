<?php

declare(strict_types=1);

namespace App\Filament\Resources\ActionImprovementResource\RelationManagers;

use App\Models\Incident;
use App\Services\EncryptionService;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Evidence files on an action improvement — encrypt-on-upload clone of the
 * supporting-documents flow (InvestigationDocumentsRelationManager), minus
 * markdown conversion, AI actions and edit/replace: evidence is immutable,
 * delete + re-upload instead. File events audit on the parent Incident so
 * they surface in the incident's audit trail.
 */
class EvidenceRelationManager extends RelationManager
{
    protected static string $relationship = 'evidence';

    protected static ?string $title = 'Evidence';

    private array $encryptionData = [];

    public function form(Form $form): Form
    {
        return $form->schema([
            FileUpload::make('file_path')
                ->label('Evidence')
                ->storeFiles(false)
                ->downloadable()
                ->maxSize(15360) // 15MB
                ->acceptedFileTypes(['image/jpeg', 'image/png', 'image/webp', 'text/plain', 'application/pdf'])
                ->required(fn (string $context): bool => $context === 'create'),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('original_filename')
            ->columns([
                Tables\Columns\TextColumn::make('original_filename')
                    ->label('File'),
                Tables\Columns\TextColumn::make('created_at')
                    ->label('Uploaded')
                    ->dateTime(),
            ])
            ->headerActions([
                Tables\Actions\CreateAction::make()
                    ->mutateFormDataUsing(function (array $data, EncryptionService $encryptionService): array {
                        try {
                            $fileInput = $data['file_path'] ?? null;

                            if (is_array($fileInput)) {
                                $fileInput = reset($fileInput);
                            }

                            if ($fileInput instanceof UploadedFile) {
                                $key = $encryptionService->generateKey();
                                $salt = $encryptionService->generateSalt();
                                $method = 'method'.rand(1, 3);
                                $finalKey = $encryptionService->getFinalKey($key, $salt, $method);

                                $this->encryptionData = [
                                    'key' => $key,
                                    'salt' => $salt,
                                    'method' => $method,
                                    'original_filename' => $fileInput->getClientOriginalName(),
                                ];

                                $encryptedContent = $encryptionService->encrypt($fileInput->get(), $finalKey);
                                $directory = 'action-evidence';
                                $newFileName = $directory.'/'.Str::uuid().'.encrypted';

                                Storage::disk('public')->makeDirectory($directory);
                                Storage::disk('public')->put($newFileName, $encryptedContent);

                                $data['file_path'] = $newFileName;
                                $data['original_filename'] = $fileInput->getClientOriginalName();
                            }
                        } catch (\Exception $e) {
                            Notification::make()
                                ->title('File upload failed')
                                ->body($e->getMessage())
                                ->danger()
                                ->send();
                            throw \Illuminate\Validation\ValidationException::withMessages([
                                'file_path' => 'File upload failed: '.$e->getMessage(),
                            ]);
                        }

                        return $data;
                    })
                    ->after(function (Model $record) {
                        if (! empty($this->encryptionData)) {
                            $record->evidenceKey()->create($this->encryptionData);

                            try {
                                $this->ownerRecord->incident->audits()->create([
                                    'user_id' => Auth::id(),
                                    'event' => 'file_uploaded',
                                    'auditable_type' => Incident::class,
                                    'auditable_id' => $this->ownerRecord->incident->id,
                                    'new_values' => [
                                        'filename' => (string) Str::of($this->encryptionData['original_filename'])->ascii(),
                                    ],
                                ]);
                            } catch (\Exception $e) {
                                Log::error('Error creating audit log: '.$e->getMessage());
                            }

                            $this->encryptionData = [];
                            Notification::make()
                                ->title('Evidence uploaded successfully')
                                ->success()
                                ->send();
                        }
                    }),
            ])
            ->actions([
                Tables\Actions\Action::make('download')
                    ->icon('heroicon-o-arrow-down-tray')
                    ->label('Download')
                    ->action(fn ($record) => redirect()->route('action-evidence.download', $record)),
                Tables\Actions\DeleteAction::make()
                    ->after(function ($record) {
                        if ($record->file_path && Storage::disk('public')->exists($record->file_path)) {
                            Storage::disk('public')->delete($record->file_path);
                        }
                        $record->evidenceKey()->delete();

                        try {
                            $this->ownerRecord->incident->audits()->create([
                                'user_id' => Auth::id(),
                                'event' => 'file_deleted',
                                'auditable_type' => Incident::class,
                                'auditable_id' => $this->ownerRecord->incident->id,
                                'old_values' => [
                                    'filename' => (string) Str::of($record->original_filename)->ascii(),
                                ],
                            ]);
                        } catch (\Exception $e) {
                            Log::error('Error creating audit log: '.$e->getMessage());
                        }
                    }),
            ]);
    }
}
