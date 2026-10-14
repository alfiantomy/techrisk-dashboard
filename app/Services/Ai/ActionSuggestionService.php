<?php

declare(strict_types=1);

namespace App\Services\Ai;

use App\Models\ActionImprovement;
use App\Models\AiSetting;
use App\Models\Incident;
use App\Services\Markdown\IncidentMarkdownExporter;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * AI "Suggest actions" (PROJ-014): one callAiForJson pass over an incident's
 * compact context (+ retro when present) → draft action improvements.
 * Drafts (WarRoomService::draftActionImprovements precedent) stay out of
 * reminders/overdue until promoted. Titles are deduped case-insensitively
 * against the incident's existing actions so re-running is idempotent-ish.
 */
class ActionSuggestionService
{
    public function __construct(
        private readonly AiTextService $aiService,
        private readonly IncidentMarkdownExporter $markdownExporter,
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

        $existing = $incident->actionImprovements()
            ->pluck('title')
            ->map(fn ($title) => mb_strtolower(trim((string) $title)))
            ->flip();

        $created = 0;
        foreach (array_slice($result['actions'] ?? [], 0, 8) as $action) {
            $title = Str::limit(trim((string) ($action['title'] ?? '')), 250);
            if ($title === '' || $existing->has(mb_strtolower($title))) {
                continue;
            }

            $priority = in_array($action['priority'] ?? null, ActionImprovement::PRIORITIES, true)
                ? $action['priority']
                : 'low';

            ActionImprovement::create([
                'incident_id' => $incident->id,
                'title' => $title,
                'detail' => trim((string) ($action['detail'] ?? $title))."\n\n_Drafted by AI — review and promote._",
                'due_date' => now()->addDays(30)->toDateString(),
                'pic_email' => [],
                'reminder' => false,
                'priority' => $priority,
                'status' => ActionImprovement::STATUS_DRAFT,
            ]);
            $existing->offsetSet(mb_strtolower($title), true);
            $created++;
        }

        Log::info('[ActionSuggestions] Drafted action improvements', [
            'incident_id' => $incident->id,
            'created' => $created,
            'proposed' => count($result['actions'] ?? []),
        ]);

        return $created;
    }
}
