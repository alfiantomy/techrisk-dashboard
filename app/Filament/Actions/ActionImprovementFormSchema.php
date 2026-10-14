<?php

declare(strict_types=1);

namespace App\Filament\Actions;

use App\Models\ActionImprovement;
use App\Models\User;
use Filament\Forms;

/**
 * Single source for the action-improvement form (PROJ-014) — consumed by the
 * incident relation manager and the global ActionImprovementResource.
 * ExportActionSchema precedent: one class, shared everywhere.
 */
class ActionImprovementFormSchema
{
    /**
     * @return array<Forms\Components\Component>
     */
    public static function schema(): array
    {
        return [
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
            Forms\Components\Select::make('priority')
                ->options(array_combine(ActionImprovement::PRIORITIES, array_map('ucfirst', ActionImprovement::PRIORITIES)))
                // Optional everywhere, default low (owner rule 2026-10-14).
                ->default('low'),
            Forms\Components\Toggle::make('reminder'),
            Forms\Components\Select::make('reminder_frequency')
                ->options([
                    'weekly' => 'Once a week',
                    'biweekly' => 'Once every 2 weeks',
                ]),
            Forms\Components\Select::make('status')
                ->options([
                    ActionImprovement::STATUS_DRAFT => 'Draft (AI-suggested)',
                    ActionImprovement::STATUS_PENDING => 'Pending',
                    ActionImprovement::STATUS_DONE => 'Done',
                    ActionImprovement::STATUS_CANCELLED => 'Cancelled (not applicable)',
                ])
                ->default(ActionImprovement::STATUS_PENDING)
                ->required()
                ->live(),
            Forms\Components\Textarea::make('result_note')
                ->label('Result note')
                ->helperText('What was done / why it is safe to close.')
                ->visible(fn (Forms\Get $get): bool => $get('status') === ActionImprovement::STATUS_DONE)
                ->columnSpanFull(),
        ];
    }

    /** Shared badge colors — the relation manager and global resource must not drift. */
    public static function statusColor(string $state): string
    {
        return match ($state) {
            ActionImprovement::STATUS_DONE => 'success',
            ActionImprovement::STATUS_PENDING => 'warning',
            ActionImprovement::STATUS_CANCELLED => 'danger',
            default => 'gray', // draft
        };
    }

    public static function priorityColor(string $state): string
    {
        return match ($state) {
            'critical' => 'danger',
            'high' => 'warning',
            'medium' => 'info',
            default => 'gray', // low
        };
    }
}
