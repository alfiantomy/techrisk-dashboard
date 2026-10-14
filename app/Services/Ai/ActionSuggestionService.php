<?php

declare(strict_types=1);

namespace App\Services\Ai;

use App\Models\AiSetting;
use App\Models\Incident;
use App\Services\ActionImprovements\DocumentActionItemService;
use App\Services\Markdown\IncidentMarkdownExporter;
use Illuminate\Support\Facades\Log;

/**
 * AI "Suggest actions" (PROJ-014): one callAiForJson pass over an incident's
 * compact context (+ retro when present) → draft action improvements.
 * Drafts (WarRoomService::draftActionImprovements precedent) stay out of
 * reminders/overdue until promoted. Titles are deduped case-insensitively
 * against the incident's existing actions so re-running is idempotent-ish.
 * Draft creation itself lives in DocumentActionItemService::createDrafts()
 * — the single loop shared with the RCA-document quick-add path (PROJ-016).
 */
class ActionSuggestionService
{
    public function __construct(
        private readonly AiTextService $aiService,
        private readonly IncidentMarkdownExporter $markdownExporter,
        private readonly DocumentActionItemService $draftService,
    ) {}

    /**
     * @return int number of draft rows created
     */
    public function generate(Incident $incident): int
    {
        $prompt = config('ai.prompts.action_suggestions');

        if (! $prompt) {
            return 0;
        }

        $incident->load(Incident::FULL_RELATIONS)->load('incidentType');

        $userMessage = "Propose action improvements for the following incident:\n\n".$this->markdownExporter->generateCompact($incident);
        if ($incident->retro_markdown) {
            $userMessage .= "\n\n## Retrospective (AI-generated)\n".$incident->retro_markdown;
        }

        $result = $this->aiService->callAiForJson(
            'action_suggestions',
            AiSetting::get('default_model', config('ai.default_model')),
            $prompt['system'],
            $userMessage,
            ['actions' => []],
        );

        $created = $this->draftService->createDrafts(
            $incident,
            array_slice($result['actions'] ?? [], 0, 8),
            '_Drafted by AI — review and promote._',
        );

        Log::info('[ActionSuggestions] Drafted action improvements', [
            'incident_id' => $incident->id,
            'created' => $created,
            'proposed' => count($result['actions'] ?? []),
        ]);

        return $created;
    }
}
