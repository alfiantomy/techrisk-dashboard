<?php

declare(strict_types=1);

namespace App\Filament\Actions;

use App\Services\Notion\NotionIssueSyncService;
use Filament\Forms\Components\Checkbox;
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
 * (form()) also restores tombstoned issues, guarded by a conditional warning.
 */
class NotionSyncAction
{
    public static function run(bool $restoreDeleted = false): void
    {
        $result = app(NotionIssueSyncService::class)->sync(restoreDeleted: $restoreDeleted);

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
     * sync would restore, failures). Nothing is written; the modal footer's
     * "Continue full sync" runs the real thing.
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
     * is normal-sync-only per owner 2026-10-29): the full-sync checkbox plus
     * a warning that only appears once it is ticked, so the operator
     * confirms they really want every deleted issue back (full sync is
     * all-or-nothing — it also resurrects duplicates deleted on purpose).
     */
    public static function form(): array
    {
        return [
            Checkbox::make('restoreDeleted')
                ->label('Full sync — restore deleted issues')
                ->helperText('Also re-import every issue that was previously deleted from this dashboard. A normal sync imports only new Notion entries.')
                ->live(),
            Placeholder::make('full_sync_warning')
                ->content(fn (Get $get): ?HtmlString => filled($get('restoreDeleted')) ? new HtmlString(
                    '⚠ A full sync re-imports ALL issues that were deleted from this dashboard — including ones deleted on purpose (for example, duplicates). '
                    .'They will reappear in the Issue list. Leave this unchecked unless you deliberately want everything restored.'
                ) : null)
                ->columnSpanFull(),
        ];
    }
}
