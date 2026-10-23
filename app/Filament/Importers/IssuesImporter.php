<?php

declare(strict_types=1);

namespace App\Filament\Importers;

use App\Models\Incident;
use App\Services\Notion\NotionIssueMapper;
use Filament\Actions\Imports\Exceptions\RowImportFailedException;
use Filament\Actions\Imports\ImportColumn;
use Filament\Actions\Imports\Importer;
use Filament\Actions\Imports\Models\Import;

class IssuesImporter extends Importer
{
    protected static ?string $model = Incident::class;

    public static function getColumns(): array
    {
        return [
            ImportColumn::make('Name')
                ->label('Name')
                ->requiredMapping()
                ->rules(['required', 'string', 'max:255']),
            ImportColumn::make('Date of Incident')
                ->label('Date of Incident')
                ->requiredMapping()
                ->rules(['required', 'string']),
            ImportColumn::make('Root Cause Classification')
                ->label('Root Cause Classification')
                ->requiredMapping()
                ->rules(['nullable', 'string']),
        ];
    }

    public function resolveRecord(): ?Incident
    {
        $name = $this->data['Name'] ?? null;

        if (! $name) {
            return new Incident;
        }

        $existing = NotionIssueMapper::findDuplicateByTitle($name);

        if ($existing) {
            // Skip this row with a warning message instead of updating
            throw new RowImportFailedException(
                "Skipped: Issue '{$name}' already exists as '{$existing->title}' (ID: {$existing->no})"
            );
        }

        // Create new record if no duplicate found
        return new Incident;
    }

    public function fillRecord(): void
    {
        // Parse date from "Month DD, YYYY" format (e.g., "January 12, 2026")
        $incidentDate = NotionIssueMapper::parseNotionDate($this->data['Date of Incident'] ?? '');

        // Clean the title (removes Notion prefix, etc.)
        $cleanTitle = NotionIssueMapper::cleanTitle($this->data['Name'] ?? '');

        $data = [
            'title' => $cleanTitle,
            'incident_date' => $incidentDate,
            'entry_date_tech_risk' => $incidentDate->format('Y-m-d'),
            'severity' => NotionIssueMapper::mapSeverityToCode($this->data['Root Cause Classification'] ?? 'G'),
            'classification' => 'Issue',
            'incident_type_id' => \App\Models\IncidentType::first()?->id,
        ];

        // Only generate new ID for new records
        if (! $this->record->exists) {
            $data['no'] = NotionIssueMapper::generateIssueId();
        }

        $this->record->fill($data);

        // MTTR/MTBF will be auto-calculated by the IncidentObserver
    }

    public static function getLabel(): string
    {
        return 'Issues';
    }

    public static function getLabelLabel(): string
    {
        return 'Issue Name';
    }

    public static function getCompletedNotificationBody(Import $import): string
    {
        $successful = $import->rows_successful ?? 0;
        $failed = $import->getFailedRowsCount() ?? 0;

        $message = "Successfully imported {$successful} issue(s).";

        if ($failed > 0) {
            $message .= " {$failed} row(s) skipped due to duplicates.";
        }

        return $message;
    }
}
