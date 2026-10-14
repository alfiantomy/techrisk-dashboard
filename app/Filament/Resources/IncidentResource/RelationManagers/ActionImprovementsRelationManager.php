<?php

declare(strict_types=1);

namespace App\Filament\Resources\IncidentResource\RelationManagers;

use App\Filament\Actions\ActionImprovementFormSchema;
use App\Models\ActionImprovement;
use App\Models\User;
use App\Notifications\ActionImprovementReminder;
use App\Support\ReminderMail;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;

class ActionImprovementsRelationManager extends RelationManager
{
    protected static string $relationship = 'actionImprovements';

    private function isOverdue($record): bool
    {
        return $record->status === ActionImprovement::STATUS_PENDING
            && $record->due_date !== null
            && $record->due_date->isBefore(today());
    }

    public function form(Form $form): Form
    {
        return $form->schema(ActionImprovementFormSchema::schema());
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('title')
            ->columns([
                Tables\Columns\TextColumn::make('title'),
                Tables\Columns\TextColumn::make('priority')
                    ->badge()
                    ->color(fn (string $state): string => ActionImprovementFormSchema::priorityColor($state))
                    ->sortable()
                    ->toggleable(),
                Tables\Columns\TextColumn::make('due_date')
                    ->date()
                    ->sortable()
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
                    ->color(fn (string $state): string => ActionImprovementFormSchema::statusColor($state)),
                Tables\Columns\TextColumn::make('escalation_level')
                    ->label('Escalation')
                    ->badge()
                    ->formatStateUsing(fn ($state): string => 'L'.$state)
                    ->color('danger')
                    ->description(fn ($record): ?string => $record->last_escalated_at?->format('M d, Y'))
                    ->visible(fn ($record): bool => ($record->escalation_level ?? 0) > 0)
                    ->toggleable(),
                Tables\Columns\TextColumn::make('last_reminded_at')
                    ->label('Last email')
                    ->dateTime()
                    ->placeholder('—')
                    ->description(fn ($record): ?string => $record->reminders_sent > 0 ? $record->reminders_sent.' reminder(s) emailed' : null),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->options([
                        ActionImprovement::STATUS_DRAFT => 'Draft',
                        ActionImprovement::STATUS_PENDING => 'Pending',
                        ActionImprovement::STATUS_DONE => 'Done',
                        ActionImprovement::STATUS_CANCELLED => 'Cancelled',
                    ]),
                SelectFilter::make('priority')
                    ->options(array_combine(ActionImprovement::PRIORITIES, array_map('ucfirst', ActionImprovement::PRIORITIES))),
                TernaryFilter::make('overdue')
                    ->queries(
                        fn ($query) => $query
                            ->where('status', ActionImprovement::STATUS_PENDING)
                            ->whereDate('due_date', '<', today()),
                        fn ($query) => $query
                            ->where(fn ($q) => $q
                                ->where('status', '!=', ActionImprovement::STATUS_PENDING)
                                ->orWhereDate('due_date', '>=', today())),
                    ),
            ])
            ->headerActions([
                Tables\Actions\CreateAction::make(),
                // PROJ-014: draft actions from AI over the incident context.
                // Queued — one AI call; the requester gets an in-app notice.
                Tables\Actions\Action::make('suggest_actions')
                    ->label('Suggest Actions (AI)')
                    ->icon('heroicon-o-sparkles')
                    ->color('primary')
                    ->requiresConfirmation()
                    ->modalDescription('Ask AI to draft action improvements from this incident\'s data. Drafts appear in the table with status Draft — review and promote them. Already-suggested actions are skipped.')
                    ->visible(fn (): bool => (bool) auth()->user()?->can('manage incidents'))
                    ->action(function () {
                        \App\Jobs\GenerateActionSuggestionsJob::dispatch($this->getOwnerRecord(), auth()->user());

                        Notification::make()
                            ->info()
                            ->title('Generating suggestions')
                            ->body('AI is drafting action improvements — you will get a notification when it is done.')
                            ->send();
                    }),
            ])
            ->actions([
                Tables\Actions\Action::make('send_reminder')
                    ->label('Email')
                    ->icon('heroicon-o-envelope')
                    ->color('info')
                    ->requiresConfirmation()
                    ->modalDescription('Send a reminder email to the assigned PIC email(s) via Netcore.')
                    ->visible(fn ($record) => $record->status === ActionImprovement::STATUS_PENDING)
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
