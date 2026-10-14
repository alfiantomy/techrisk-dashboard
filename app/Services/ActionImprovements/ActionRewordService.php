<?php

declare(strict_types=1);

namespace App\Services\ActionImprovements;

use App\Models\ActionImprovement;
use App\Services\Ai\AiTextService;
use Illuminate\Support\Str;

/**
 * PROJ-016 iteration 3: AI rewording of DRAFT action improvements (owner
 * 2026-10-14 — "make it more professional and detail"). Rewrites title +
 * detail ONLY; status, PIC, due date and priority are never touched and the
 * row stays a draft. The prompt preserves the "Owner:" suffix and the
 * trailing provenance note.
 */
class ActionRewordService
{
    public function __construct(
        private readonly AiTextService $aiService,
    ) {}

    public function reword(ActionImprovement $row, string $model): bool
    {
        $prompt = config('ai.prompts.action_reword');

        if (! $prompt) {
            return false;
        }

        $result = $this->aiService->callAiForJson(
            'action_reword',
            $model,
            $prompt['system'],
            "Title: {$row->title}\n\nDetail:\n{$row->detail}",
            ['title' => null, 'detail' => null],
        );

        $title = is_string($result['title'] ?? null) ? trim($result['title']) : '';
        $detail = is_string($result['detail'] ?? null) ? trim($result['detail']) : '';

        if ($title === '' || $detail === '') {
            return false;
        }

        $row->update([
            'title' => Str::limit($title, 250),
            'detail' => $detail,
        ]);

        return true;
    }
}
