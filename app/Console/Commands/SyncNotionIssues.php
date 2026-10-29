<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Notion\NotionIssueSyncService;
use Illuminate\Console\Command;

class SyncNotionIssues extends Command
{
    protected $signature = 'notion:sync-issues
        {--dry-run : List what would be imported (and print the raw Notion response shape) without writing}
        {--restore : Full sync — also re-import issues deleted from the dashboard}';

    protected $description = 'Import new Notion sub-pages as Issue records (dedupe on Notion page ID)';

    public function handle(NotionIssueSyncService $sync): int
    {
        $result = $sync->sync(dryRun: (bool) $this->option('dry-run'), restoreDeleted: (bool) $this->option('restore'));

        $result['status'] === 'ok'
            ? $this->info($result['message'])
            : $this->error($result['message']);

        if (filled($result['preview'])) {
            $this->line("Entries seen:\n{$result['preview']}");
        }

        return $result['status'] === 'ok' ? self::SUCCESS : self::FAILURE;
    }
}
