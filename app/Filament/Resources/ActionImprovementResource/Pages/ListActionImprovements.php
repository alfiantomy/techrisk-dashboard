<?php

declare(strict_types=1);

namespace App\Filament\Resources\ActionImprovementResource\Pages;

use App\Filament\Resources\ActionImprovementResource;
use Filament\Resources\Pages\ListRecords;

class ListActionImprovements extends ListRecords
{
    protected static string $resource = ActionImprovementResource::class;

    protected function getHeaderWidgets(): array
    {
        return [];
    }
}
