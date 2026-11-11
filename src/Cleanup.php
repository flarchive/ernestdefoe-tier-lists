<?php

namespace ErnestDefoe\TierLists;

use Illuminate\Database\ConnectionInterface;

/**
 * A member leaves (deleted, or erased or anonymized through GDPR): their lists
 * and likes go, and so does what pointed at them. The foreign keys would
 * delete the rows on their own, but not the pictures (still marked as used,
 * never pruned) nor the counters on other members' lists.
 */
class Cleanup
{
    public function __construct(private readonly ConnectionInterface $db, private readonly Images $images)
    {
    }

    /** Delete the member's lists and likes, then release pictures and recount. */
    public function deleteMember(int $userId): void
    {
        $lists = TierList::query()->where('user_id', $userId)->get(['id', 'root_id']);
        $ids = $lists->pluck('id')->all();
        $roots = $lists->pluck('root_id')->filter()->unique()->all();

        $pictures = $ids ? $this->db->table('tier_list_items')->whereIn('tier_list_id', $ids)->whereNotNull('image')->pluck('image')->unique()->values()->all() : [];
        $liked = $this->unlike($userId, delete: false);

        if ($ids) {
            // Items first: SQLite may not enforce the cascade.
            $this->db->table('tier_list_items')->whereIn('tier_list_id', $ids)->delete();
            $this->db->table('tier_list_likes')->whereIn('tier_list_id', $ids)->delete();
            TierList::query()->whereIn('id', $ids)->delete();
        }
        $this->db->table('tier_list_likes')->where('user_id', $userId)->delete();

        $this->images->release($pictures);
        $this->recount(array_diff($roots, $ids), array_diff($liked, $ids));
    }

    /** Keep the lists without a name; take the likes back. */
    public function anonymizeMember(int $userId): void
    {
        TierList::query()->where('user_id', $userId)->update(['user_id' => null]);
        $this->recount([], $this->unlike($userId, delete: true));
    }

    /** @return list<int> the lists the member liked */
    private function unlike(int $userId, bool $delete): array
    {
        $liked = $this->db->table('tier_list_likes')->where('user_id', $userId)->pluck('tier_list_id')->map(fn ($id) => (int) $id)->all();
        if ($delete && $liked) {
            $this->db->table('tier_list_likes')->where('user_id', $userId)->delete();
        }

        return $liked;
    }

    /**
     * @param array<int> $roots lists whose remixes changed
     * @param array<int> $liked lists whose likes changed
     */
    private function recount(array $roots, array $liked): void
    {
        foreach (array_unique($roots) as $root) {
            TierList::query()->where('id', $root)->update(['remixes_count' => TierList::query()->where('root_id', $root)->count()]);
        }
        foreach (array_unique($liked) as $list) {
            TierList::query()->where('id', $list)->update(['likes_count' => $this->db->table('tier_list_likes')->where('tier_list_id', $list)->count()]);
        }
    }
}
