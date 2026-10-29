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
        // Normal sync only (owner 2026-10-29): the full-sync checkbox lives
        // on the Notion Settings page, so restoring deleted issues stays a
        // deliberate admin action there, never an accidental one here.
        return [
            Actions\Action::make('syncWithNotion')
                ->label('Sync with Notion')
                ->icon('heroicon-m-arrow-path')
                ->visible(fn (): bool => auth()->user()->can('manage api tokens'))
                ->tooltip('Normal sync — new Notion entries only. Full sync (restore deleted issues) is on the Notion Settings page.')
                ->action(fn () => NotionSyncAction::run()),
            Actions\ImportAction::make()
                ->importer(IssuesImporter::class)
                ->visible(fn (): bool => auth()->user()->can('manage issues')),
            Actions\CreateAction::make()
                ->visible(fn (): bool => auth()->user()->can('manage issues')),
        ];
    }
}
