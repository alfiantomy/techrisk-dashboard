<?php

declare(strict_types=1);

namespace App\Services\ActionImprovements;

use App\Models\ActionImprovement;
use App\Models\Incident;
use App\Models\User;
use App\Services\Ai\AiTextService;
use Illuminate\Support\Str;

/**
 * PROJ-016: quick-add of action improvements from an RCA document's
 * converted markdown. Two extractors: `extractFromMarkdown()` is the
 * deterministic regex pass (owner 2026-10-14 — "do we need AI? if already
 * have MD?"); `extractWithAi()` (iteration 2, same day) lets a chosen model
 * read the whole document and map the register columns — the regex trim
 * mangled some items ("not auto cut"), so AI is the default when configured.
 * `createDrafts()` is the single draft-creation loop shared with the AI
 * suggestion path (Ai\ActionSuggestionService).
 *
 * Owner matching (iteration 2): AI-extracted owner names are resolved to
 * existing users by exact name — matched → their email becomes the row's
 * PIC; unmatched → the name only lives in the detail's "Owner:" suffix,
 * editable later (owner: "if user exist can auto to that user if not exist
 * can be just name").
 */
class DocumentActionItemService
{
    public function __construct(
        private readonly AiTextService $aiService,
    ) {}

    /**
     * AI extractor: one callAiForJson pass over the document markdown →
     * items mapped to the register columns (prompt
     * `ai.prompts.document_action_items`). Input capped at 30k chars; the
     * current date is prefixed so relative deadlines resolve.
     *
     * @return list<array{title: string, detail: ?string, priority: ?string, due_date: ?string, pic_email: array}>
     */
    public function extractWithAi(string $markdown, string $model): array
    {
        $prompt = config('ai.prompts.document_action_items');

        if (! $prompt) {
            return [];
        }

        $result = $this->aiService->callAiForJson(
            'document_action_items',
            $model,
            $prompt['system'],
            'Current date: '.now()->toDateString()."\n\nExtract the action improvement items from this RCA document:\n\n".Str::limit($markdown, 30000),
            ['actions' => []],
        );

        return collect($result['actions'] ?? [])
            ->filter(fn ($action) => is_array($action) && is_string($action['title'] ?? null) && trim($action['title']) !== '')
            ->map(function (array $action): array {
                $owners = collect($action['owners'] ?? [])
                    ->filter(fn ($owner) => is_string($owner) && trim($owner) !== '')
                    ->values()
                    ->all();

                return [
                    'title' => $action['title'],
                    'detail' => is_string($action['detail'] ?? null) ? $action['detail'] : null,
                    'priority' => is_string($action['priority'] ?? null) ? $action['priority'] : null,
                    'due_date' => is_string($action['due_date'] ?? null) ? $action['due_date'] : null,
                    'pic_email' => $this->resolveOwnerEmails($owners),
                ];
            })
            ->values()
            ->all();
    }

    /**
     * @return list<string> cleaned, deduped item titles
     */
    public function extractFromMarkdown(string $markdown): array
    {
        $titles = [];
        $seen = [];
        $inSection = false;
        $anchorLevel = 0;

        foreach (preg_split('/\r\n|\r|\n/', $markdown) as $raw) {
            if (preg_match('/^(#{1,6})\s+(.+)$/', trim($raw), $m)) {
                $level = strlen($m[1]);
                $heading = mb_strtolower($this->collapse($m[2]));

                if (! $inSection) {
                    // Line-start heading only — prose mentions never anchor.
                    if (str_starts_with($heading, 'action improvement')) {
                        $inSection = true;
                        $anchorLevel = $level;
                    }
                } elseif ($level <= $anchorLevel) {
                    break; // next same-or-higher heading ends the section
                }
                // Deeper headings (###) inside the section: dropped, not items.

                continue;
            }

            if (! $inSection) {
                continue;
            }

            $title = $this->cleanCandidate($raw);
            if ($title !== null && ! isset($seen[mb_strtolower($title)])) {
                $seen[mb_strtolower($title)] = true;
                $titles[] = $title;
            }
        }

        return $titles;
    }

    /**
     * The shared draft-creation loop (extracted from
     * Ai\ActionSuggestionService::generate, PROJ-014 — dedupe semantics
     * must not drift between the AI and document paths).
     *
     * @param  array<int, array{title: string, detail?: string|null, priority?: string|null, due_date?: string|null, pic_email?: array}>  $items
     * @return int rows created
     */
    public function createDrafts(Incident $incident, array $items, string $provenanceNote): int
    {
        $existing = $incident->actionImprovements()
            ->pluck('title')
            ->map(fn ($title) => mb_strtolower(trim((string) $title)))
            ->flip();

        $created = 0;
        foreach ($items as $item) {
            $title = Str::limit(trim((string) ($item['title'] ?? '')), 250);
            if ($title === '' || $existing->has(mb_strtolower($title))) {
                continue;
            }

            $priority = in_array($item['priority'] ?? null, ActionImprovement::PRIORITIES, true)
                ? $item['priority']
                : 'low';

            ActionImprovement::create([
                'incident_id' => $incident->id,
                'title' => $title,
                'detail' => trim((string) ($item['detail'] ?? $title))."\n\n".$provenanceNote,
                'due_date' => $this->resolveDueDate($item['due_date'] ?? null),
                'pic_email' => $item['pic_email'] ?? [],
                'reminder' => false,
                'priority' => $priority,
                'status' => ActionImprovement::STATUS_DRAFT,
            ]);
            $existing->offsetSet(mb_strtolower($title), true);
            $created++;
        }

        return $created;
    }

    /**
     * AI-extracted owners → PIC emails. Exact case-insensitive full-name
     * match (team tag stripped); unmatched owners stay as text in the
     * detail's "Owner:" suffix — nothing to look up.
     *
     * @param  list<string>  $owners
     * @return list<string>
     */
    private function resolveOwnerEmails(array $owners): array
    {
        $emails = [];
        foreach ($owners as $owner) {
            $name = trim((string) preg_replace('/\s*\([^)]*\)\s*$/', '', $owner)); // drop "(TEAM)" tag
            if ($name === '') {
                continue;
            }

            $user = User::query()
                ->whereRaw('LOWER(name) = ?', [mb_strtolower($name)])
                ->first();
            // ponytail: duplicate names → first match wins; scope by team if it ever bites.
            if ($user && ! in_array($user->email, $emails, true)) {
                $emails[] = $user->email;
            }
        }

        return $emails;
    }

    /**
     * Strict Y-m-d + real calendar date, else the +30d default. Carbon is
     * deliberately avoided: createFromFormat silently rolls 2026-02-31 and
     * Carbon::parse accepts loose formats like 12/31/2026.
     */
    private function resolveDueDate(mixed $value): string
    {
        $value = is_string($value) ? trim($value) : '';

        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $value, $m)
            && checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
            return $value;
        }

        return now()->addDays(30)->toDateString();
    }

    private function cleanCandidate(string $line): ?string
    {
        $line = str_replace('`', '', $line); // fence fragments — "```" lines become empty
        $line = preg_replace('/^(#{1,6}|\*{1,2}|[-*+•]>?|\d+[.)]|\[[ xX]?\])\s+/u', '', $line) ?? '';
        $line = preg_replace('/\s*#{1,6}\s+\S+\s*$/u', '', $line); // trailing "## DONE" status token
        $line = trim(preg_replace('/\s+/', ' ', $line) ?? '', " \t,;|.-");
        $lower = mb_strtolower($line);

        if ($line === '' || mb_strlen($line) < 4) {
            return null;
        }
        // Single word = owner-name line ("Steven", "Esra").
        // ponytail: two-word owner names still slip through — drafts are reviewable.
        if (! str_contains($line, ' ')) {
            return null;
        }
        if (preg_match('/^\(.*\)$/', $line)) {
            return null; // "(DEV)" fragments
        }
        if (in_array($lower, ['n/a', 'na', 'none', '—', '-', 'tbd', 'x'], true)) {
            return null;
        }
        if (mb_strlen($line) < 60 && preg_match('/action item|due date|verification standard/i', $line)) {
            return null; // table header row
        }

        return $line;
    }

    private function collapse(string $text): string
    {
        return trim(preg_replace('/\s+/', ' ', preg_replace('/[#*`\[\]]/', '', $text)) ?? '');
    }
}
