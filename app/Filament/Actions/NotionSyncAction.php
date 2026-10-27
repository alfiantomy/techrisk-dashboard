<?php

declare(strict_types=1);

namespace App\Filament\Actions;

use App\Services\Notion\NotionIssueSyncService;
use Filament\Notifications\Notification;
use Illuminate\Support\Str;

/**
 * Runs the Notion sub-page sync and tells the human what happened. Shared by
 * the Notion Settings page ("Sync now") and the Issue list header button
 * ("Sync with Notion") so the two surfaces cannot drift — the failure preview
 * was once added to one surface only.
 */
class NotionSyncAction
{
    public static function run(): void
    {
        $result = app(NotionIssueSyncService::class)->sync();

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
}
