<?php

declare(strict_types=1);

namespace App\Filament\Resources\ActionImprovementResource\Pages;

use App\Filament\Resources\ActionImprovementResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditActionImprovement extends EditRecord
{
    protected static string $resource = ActionImprovementResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
        ];
    }
}
