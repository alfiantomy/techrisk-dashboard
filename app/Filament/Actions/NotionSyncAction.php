<?php

declare(strict_types=1);

namespace App\Filament\Actions;

use App\Filament\Pages\NotionSettings;
use App\Services\Notion\NotionIssueSyncService;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Get;
use Filament\Notifications\Notification;
use Illuminate\Support\HtmlString;
use Illuminate\Support\Str;

/**
 * Runs the Notion sub-page sync and tells the human what happened. Shared by
 * the Notion Settings page ("Sync now" + "Preview") and the Issue list header
 * button ("Sync with Notion") so the two surfaces cannot drift — the failure
 * preview was once added to one surface only.
 *
 * Two modes (owner 2026-10-29): a normal sync imports only new Notion rows —
 * deleting an Issue from the dashboard is permanent. The "Full sync" checkbox
 * (form()) also restores tombstoned issues — only the ones the operator ticks
 * in the picker (owner 2026-10-30); unticked issues stay deleted.
 */
class NotionSyncAction
{
    public static function run(bool $restoreDeleted = false, ?array $restorePageIds = null): void
    {
        $result = app(NotionIssueSyncService::class)->sync(
            restoreDeleted: $restoreDeleted,
            restorePageIds: $restorePageIds,
        );

        if ($result['status'] === 'ok') {
            Notification::make()->success()->title($result['message'])->send();

            return;
        }

        // The preview (raw Notion response) is the diagnostic the message
        // refers to — show it.
        $body = $result['message'];

        if (filled($result['preview'])) {
            $body .= "\n\n".Str::limit($result['preview'], 1500);
        }

        Notification::make()->danger()->title('Sync failed')->body($body)->persistent()->send();
    }

    /**
     * The Preview button's modal body (Notion Settings page): a read-only
     * dry run of the FULL sync — the per-reason counts, then every finding
     * on its own line (would-import rows, duplicate titles, what a full
     * sync would restore, failures). Nothing is written; the footer's
     * "Continue full sync" opens the Sync-now picker with full sync
     * pre-ticked (2026-10-30) — the pick, not the preview, triggers the run.
     */
    public static function previewModalContent(): \Illuminate\Contracts\View\View
    {
        $result = app(NotionIssueSyncService::class)->sync(dryRun: true, restoreDeleted: true);

        return view('filament.actions.notion-dry-run', [
            'message' => $result['message'],
            'lines' => filled($result['preview']) ? explode("\n", $result['preview']) : [],
        ]);
    }

    /**
     * The Sync-now modal (Notion Settings page only — the Issue-list button
     * is normal-sync-only per owner 2026-10-29): the full-sync checkbox, and
     * once it is ticked the restore picker (owner 2026-10-30) — the deleted
     * issues a full sync WOULD restore (restorableIssues(), the same filter
     * the run applies), of which only the ticked ones come back (Select All
     * toggles the whole list). The options come through the page component
     * so the Notion lookups are made once per page load, not once per
     * Livewire render.
     */
    public static function form(): array
    {
        return [
            Checkbox::make('restoreDeleted')
                ->label('Full sync — restore deleted issues')
                ->helperText('Also re-import issues that were previously deleted from this dashboard — you pick which ones. A normal sync imports only new Notion entries.')
                ->live(),
            Placeholder::make('full_sync_warning')
                ->content(fn (Get $get): ?HtmlString => filled($get('restoreDeleted')) ? new HtmlString(
                    '⚠ Tick below the deleted issues you want back — everything you leave unticked stays deleted, including duplicates deleted on purpose. New Notion rows import either way.'
                ) : null)
                ->columnSpanFull(),
            CheckboxList::make('restorePageIds')
                ->label('Deleted issues to restore')
                ->helperText('Only the ticked issues are restored. Leave everything unticked to restore nothing.')
                ->options(fn (NotionSettings $livewire): array => $livewire->restorableIssueOptions())
                ->visible(fn (NotionSettings $livewire, Get $get): bool => filled($get('restoreDeleted')) && $livewire->restorableIssueOptions() !== [])
                ->bulkToggleable()
                ->columnSpanFull(),
            Placeholder::make('restore_picker_empty')
                ->content(new HtmlString(
                    'No restorable deleted issues right now — either nothing was deleted, or Notion could not be reached to list them.'
                ))
                ->visible(fn (NotionSettings $livewire, Get $get): bool => filled($get('restoreDeleted')) && $livewire->restorableIssueOptions() === [])
                ->columnSpanFull(),
        ];
    }
}
