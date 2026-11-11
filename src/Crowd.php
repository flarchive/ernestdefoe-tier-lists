<?php

namespace ErnestDefoe\TierLists;

use Illuminate\Database\ConnectionInterface;

/**
 * Where a remix family puts each item, in ONE query.
 *
 * A placement is scored 0 (top row) to 1 (bottom row) on its own list's
 * scale, so a remix with five rows and the original with six still average
 * fairly. Items left in the pool don't count.
 */
class Crowd
{
    public function __construct(private readonly ConnectionInterface $db)
    {
    }

    /** @return array<string, array{0: float, 1: int}> item key => [average score, rankings] */
    public function averages(int $familyId): array
    {
        $grammar = $this->db->getQueryGrammar();
        $tier = $grammar->wrap('tier_list_items.tier');
        $count = $grammar->wrap('tier_lists.tier_count');

        $rows = $this->db->table('tier_list_items')
            ->join('tier_lists', 'tier_lists.id', '=', 'tier_list_items.tier_list_id')
            ->where(fn ($q) => $q->where('tier_lists.id', $familyId)->orWhere('tier_lists.root_id', $familyId))
            ->whereNotNull('tier_list_items.tier')
            ->where('tier_lists.tier_count', '>', 1)
            ->groupBy('tier_list_items.key')
            ->selectRaw($grammar->wrap('tier_list_items.key').' as k, AVG(('.$tier.' * 1.0) / ('.$count.' - 1)) as score, COUNT(*) as n')
            ->get();

        $out = [];
        foreach ($rows as $row) {
            $out[(string) $row->k] = [round((float) $row->score, 4), (int) $row->n];
        }

        return $out;
    }

    /** A score on a list with $tiers rows, as that list's row. */
    public static function row(float $score, int $tiers): int
    {
        return (int) max(0, min($tiers - 1, round($score * ($tiers - 1))));
    }
}
