<?php

declare(strict_types=1);

namespace App\Filament\Resources\IssueResource\Pages;

use App\Filament\Actions\NotionSyncAction;
use App\Filament\Importers\IssuesImporter;
use App\Filament\Resources\IssueResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListIssues extends ListRecords
{
    protected static string $resource = IssueResource::class;

    protected function getHeaderActions(): array
    {
        // Rendered left to right: Sync with Notion | Import | New Issue.
        return [
            Actions\Action::make('syncWithNotion')
                ->label('Sync with Notion')
                ->icon('heroicon-m-arrow-path')
                ->visible(fn (): bool => auth()->user()->can('manage api tokens'))
                ->form(NotionSyncAction::form())
                ->action(fn (array $data) => NotionSyncAction::run(restoreDeleted: (bool) ($data['restoreDeleted'] ?? false))),
            Actions\ImportAction::make()
                ->importer(IssuesImporter::class)
                ->visible(fn (): bool => auth()->user()->can('manage issues')),
            Actions\CreateAction::make()
                ->visible(fn (): bool => auth()->user()->can('manage issues')),
        ];
    }
}
