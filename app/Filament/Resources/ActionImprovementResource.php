<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Filament\Actions\ActionImprovementFormSchema;
use App\Filament\Resources\ActionImprovementResource\Pages;
use App\Models\ActionImprovement;
use App\Models\Incident;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Global Action Improvements page (PROJ-014, owner 2026-10-14): every action
 * across all incidents, filtered/sortable — answers "what's overdue across
 * the org" without opening incidents one by one. Rows are still edited here
 * with the same shared form as the incident relation manager; view/edit/write
 * gates come from ActionImprovementPolicy (viewAny: view incidents OR access
 * api). Year access mirrors IssueResource: the incident's year decides.
 */
class ActionImprovementResource extends Resource
{
    protected static ?string $model = ActionImprovement::class;

    protected static ?string $navigationIcon = 'heroicon-o-clipboard-document-check';

    protected static ?string $navigationLabel = 'Action Improvements';

    protected static ?int $navigationSort = 3; // After Incident (1) and Issue (2)

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->with(['incident', 'completedBy'])
            // Year access on the incident half — same scope as IssueResource.
            ->whereHas('incident', fn (Builder $query) => $query->applyUserYearAccess(auth()->user()));
    }

    public static function form(Form $form): Form
    {
        return $form->schema(ActionImprovementFormSchema::schema());
    }

    public static function table(Table $table): Table
    {
        $overdue = fn ($query) => $query
            ->where('status', ActionImprovement::STATUS_PENDING)
            ->whereDate('due_date', '<', today());

        return $table
            ->defaultSort('due_date')
            ->columns([
                TextColumn::make('title')
                    ->searchable()
                    ->description(fn ($record): ?string => \Illuminate\Support\Str::limit($record->detail, 60)),
                TextColumn::make('incident.title')
                    ->label('Incident')
                    ->url(fn ($record): ?string => $record->incident
                        ? IncidentResource\Pages\EditIncident::getUrl(['record' => $record->incident])
                        : null)
                    ->color('primary')
                    ->limit(30),
                TextColumn::make('priority')
                    ->badge()
                    ->color(fn (string $state): string => ActionImprovementFormSchema::priorityColor($state))
                    ->sortable(),
                TextColumn::make('due_date')
                    ->date()
                    ->sortable()
                    ->color(fn ($record): string => $record->status === ActionImprovement::STATUS_PENDING
                        && $record->due_date?->isBefore(today()) ? 'danger' : 'default')
                    ->weight(fn ($record): string => $record->status === ActionImprovement::STATUS_PENDING
                        && $record->due_date?->isBefore(today()) ? 'bold' : 'normal')
                    ->description(fn ($record): ?string => $record->status === ActionImprovement::STATUS_PENDING
                        && $record->due_date?->isBefore(today()) ? 'overdue' : null),
                TextColumn::make('pic_email')
                    ->badge()
                    ->limitList(2),
                TextColumn::make('status')
                    ->badge()
                    ->color(fn (string $state): string => ActionImprovementFormSchema::statusColor($state))
                    ->sortable(),
                TextColumn::make('escalation_level')
                    ->label('Escalation')
                    ->badge()
                    ->formatStateUsing(fn ($state): string => 'L'.$state)
                    ->color('danger')
                    ->description(fn ($record): ?string => $record->last_escalated_at?->format('M d, Y'))
                    ->visible(fn ($record): bool => ($record->escalation_level ?? 0) > 0)
                    ->toggleable(),
                TextColumn::make('completed_at')
                    ->label('Completed')
                    ->dateTime('M d, Y')
                    ->sortable()
                    ->placeholder('—')
                    ->description(fn ($record): ?string => $record->completedBy?->name)
                    ->toggleable(),
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
                        $overdue,
                        fn ($query) => $query
                            ->where(fn ($q) => $q
                                ->where('status', '!=', ActionImprovement::STATUS_PENDING)
                                ->orWhereDate('due_date', '>=', today())),
                    ),
            ])
            ->actions([
                Tables\Actions\Action::make('open_incident')
                    ->label('Open Incident')
                    ->icon('heroicon-o-arrow-top-right-on-square')
                    ->color('gray')
                    ->url(fn ($record): ?string => $record->incident
                        ? IncidentResource\Pages\EditIncident::getUrl(['record' => $record->incident])
                        : null),
                Tables\Actions\EditAction::make(),
            ])
            ->bulkActions([]);
    }

    public static function getRelations(): array
    {
        return [];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListActionImprovements::route('/'),
            'edit' => Pages\EditActionImprovement::route('/{record}/edit'),
        ];
    }
}
