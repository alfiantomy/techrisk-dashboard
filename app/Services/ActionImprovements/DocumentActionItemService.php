<?php

declare(strict_types=1);

namespace App\Services\ActionImprovements;

use App\Models\ActionImprovement;
use App\Models\Incident;
use Illuminate\Support\Str;

/**
 * PROJ-016: deterministic quick-add of action improvements from an RCA
 * document's converted markdown (owner 2026-10-14 — "do we need AI? if
 * already have MD?"). `extractFromMarkdown()` finds the ACTION IMPROVEMENT
 * heading and pulls cleaned item titles out of the (often mangled PDF→MD)
 * section; `createDrafts()` is the single draft-creation loop shared with
 * the AI suggestion path (Ai\ActionSuggestionService).
 *
 * Deliberate simplification: owner names and due dates inside the section
 * are NOT parsed — the PDF output is too mangled to trust. Drafts default
 * due +30d, PIC empty, priority low; everything is editable per row.
 */
class DocumentActionItemService
{
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
     * @param  array<int, array{title: string, detail?: string, priority?: string|null}>  $items
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
                'due_date' => now()->addDays(30)->toDateString(),
                'pic_email' => [],
                'reminder' => false,
                'priority' => $priority,
                'status' => ActionImprovement::STATUS_DRAFT,
            ]);
            $existing->offsetSet(mb_strtolower($title), true);
            $created++;
        }

        return $created;
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
