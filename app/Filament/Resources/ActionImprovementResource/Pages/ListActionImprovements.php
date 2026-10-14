<?php

declare(strict_types=1);

namespace App\Filament\Resources\ActionImprovementResource\Pages;

use App\Filament\Resources\ActionImprovementResource;
use Filament\Resources\Pages\ListRecords;

class ListActionImprovements extends ListRecords
{
    protected static string $resource = ActionImprovementResource::class;

    public function mount(): void
    {
        // Owner 2026-10-14: incident groups newest-first by default; the
        // direction dropdown still lets users flip to ascending.
        $this->tableGroupingDirection = 'desc';

        parent::mount();
    }

    protected function getHeaderWidgets(): array
    {
        return [];
    }
}
