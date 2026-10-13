<?php

declare(strict_types=1);

namespace App\Filament\Resources\IncidentResource\RelationManagers;

use App\Models\User;
use App\Notifications\ActionImprovementReminder;
use App\Support\ReminderMail;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;

class ActionImprovementsRelationManager extends RelationManager
{
    protected static string $relationship = 'actionImprovements';

    private function isOverdue($record): bool
    {
        return $record->status === 'pending'
            && $record->due_date !== null
            && $record->due_date->isBefore(today());
    }

    public function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\TextInput::make('title')
                    ->required()
                    ->maxLength(255),
                Forms\Components\Textarea::make('detail')
                    ->required()
                    ->columnSpanFull(),
                Forms\Components\DatePicker::make('due_date')
                    ->required(),
                Forms\Components\Select::make('pic_email')
                    ->label('Person In Charge')
                    ->required()
                    ->multiple()
                    ->searchable()
                    ->preload()
                    // Keyed by email — pic_email keeps storing bare emails.
                    ->options(fn () => User::humanUsers()->orderBy('name')->get()
                        ->mapWithKeys(fn ($u) => [$u->email => $u->name]))
                    ->createOptionModalHeading('Add new PIC contact')
                    ->createOptionForm([
                        Forms\Components\TextInput::make('name'),
                        Forms\Components\TextInput::make('email')->email()->required(),
                    ])
                    // Directory contact, login-disabled (single home:
                    // User::upsertFromPicEmails — flip can_login to grant access).
                    ->createOptionUsing(function (array $data): string {
                        $tag = filled($data['name'] ?? null) ? "{$data['name']} <{$data['email']}>" : $data['email'];

                        return User::upsertFromPicEmails([$tag])[0];
                    })
                    ->helperText('Search by name — or use + to add a new contact (no login access until enabled in User Management).'),
                Forms\Components\Toggle::make('reminder'),
                Forms\Components\Select::make('reminder_frequency')
                    ->options([
                        'weekly' => 'Once a week',
                        'biweekly' => 'Once every 2 weeks',
                    ]),
                Forms\Components\Select::make('status')
                    ->options([
                        'draft' => 'Draft (AI-suggested)',
                        'pending' => 'Pending',
                        'done' => 'Done',
                    ])
                    ->default('pending')
                    ->required(),
            ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('title')
            ->columns([
                Tables\Columns\TextColumn::make('title'),
                Tables\Columns\TextColumn::make('due_date')
                    ->date()
                    // RD-3: overdue pending rows are visible in-UI, not just
                    // in the opt-in reminder emails.
                    ->color(fn ($record): string => $this->isOverdue($record) ? 'danger' : 'default')
                    ->weight(fn ($record): string => $this->isOverdue($record) ? 'bold' : 'normal')
                    ->description(fn ($record): ?string => $this->isOverdue($record) ? 'overdue' : null),
                Tables\Columns\TextColumn::make('pic_email'),
                Tables\Columns\IconColumn::make('reminder')
                    ->boolean(),
                Tables\Columns\TextColumn::make('status')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'draft' => 'gray',
                        'pending' => 'warning',
                        'done' => 'success',
                        default => 'secondary',
                    }),
                Tables\Columns\TextColumn::make('last_reminded_at')
                    ->label('Last email')
                    ->dateTime()
                    ->placeholder('—')
                    ->description(fn ($record): ?string => $record->reminders_sent > 0 ? $record->reminders_sent.' reminder(s) emailed' : null),
            ])
            ->filters([
                //
            ])
            ->headerActions([
                Tables\Actions\CreateAction::make(),
            ])
            ->actions([
                Tables\Actions\Action::make('send_reminder')
                    ->label('Email')
                    ->icon('heroicon-o-envelope')
                    ->color('info')
                    ->requiresConfirmation()
                    ->modalDescription('Send a reminder email to the assigned PIC email(s) via Netcore.')
                    ->visible(fn ($record) => $record->status === 'pending')
                    ->action(function ($record) {
                        $recipients = collect($record->pic_email ?? [])
                            ->map(fn ($email) => User::where('email', $email)->first())
                            ->filter()
                            ->unique('id')
                            ->values();

                        // One combined email for all recipients (owner 2026-10-09)
                        // plus the per-user in-app notification; the send is
                        // recorded on the row (owner 2026-10-13).
                        $recipients->each(fn ($user) => $user->notify(new ActionImprovementReminder($record)));
                        $mailed = ReminderMail::send($recipients, new ActionImprovementReminder($record));
                        $record->recordReminderSent($mailed);

                        Notification::make()
                            ->when($recipients->isNotEmpty() && $mailed > 0, fn ($n) => $n->success()->title('Reminder sent')->body('Email sent to '.$mailed.' recipient(s).'))
                            ->when($recipients->isNotEmpty() && $mailed === 0, fn ($n) => $n->warning()->title('No email sent')->body('Recipients exist but none have email reminders enabled in their preferences.'))
                            ->when($recipients->isEmpty(), fn ($n) => $n->warning()->title('No matching users')->body('No registered users matched the PIC email(s).'))
                            ->send();
                    }),
                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make(),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                ]),
            ]);
    }
}
