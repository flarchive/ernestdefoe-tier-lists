<?php

namespace ErnestDefoe\TierLists;

use Flarum\Discussion\Discussion;
use Flarum\Http\SlugManager;
use Flarum\User\User;

/** Lists and members as the browser receives them. Never HTML: names stay text. */
class Present
{
    public function __construct(private readonly SlugManager $slugs)
    {
    }

    /** @return array<string, mixed>|null */
    public function user(?User $user): ?array
    {
        if (! $user) {
            return null;
        }

        return [
            'id' => $user->id,
            'displayName' => $user->display_name,
            'slug' => $this->slugs->forResource(User::class)->toSlug($user),
            'avatarUrl' => $user->avatar_url,
        ];
    }

    /** @return array<string, mixed>|null */
    public function discussion(?Discussion $discussion): ?array
    {
        return $discussion ? [
            'id' => $discussion->id,
            'slug' => $this->slugs->forResource(Discussion::class)->toSlug($discussion),
            'comments' => max(0, (int) $discussion->comment_count - 1),
        ] : null;
    }

    /**
     * What a card needs: the rows and up to $per pictures of each of the top three.
     *
     * @param iterable<Item> $items
     * @return array<string, mixed>
     */
    public function card(TierList $list, iterable $items, bool $liked, ?Discussion $discussion, ?TierList $parent = null, int $per = 6): array
    {
        $preview = [];
        foreach ($items as $item) {
            if ($item->tier !== null && $item->tier < 3 && count($preview[$item->tier] ?? []) < $per) {
                $preview[$item->tier][] = ['label' => $item->label, 'image' => $item->image];
            }
        }

        return [
            'id' => $list->id,
            'title' => $list->title,
            'user' => $this->user($list->user),
            'tiers' => $list->tierRows(),
            'preview' => (object) $preview,
            'isTemplate' => $list->is_template,
            'isRemix' => $list->root_id !== null,
            'parent' => $parent ? ['id' => $parent->id, 'title' => $parent->title, 'user' => $this->user($parent->user)] : null,
            'items' => $list->items_count,
            'likes' => $list->likes_count,
            'liked' => $liked,
            'remixes' => $list->remixes_count,
            'discussion' => $this->discussion($discussion),
            'createdAt' => $list->created_at?->toIso8601String(),
        ];
    }

    /**
     * @param iterable<Item> $items
     * @return list<array<string, mixed>>
     */
    public function items(iterable $items): array
    {
        $out = [];
        foreach ($items as $item) {
            $out[] = ['key' => $item->key, 'label' => $item->label, 'image' => $item->image, 'tier' => $item->tier];
        }

        // Rows top to bottom, the pool last; within each, the member's order.
        usort($out, fn ($a, $b) => [$a['tier'] ?? 99] <=> [$b['tier'] ?? 99]);

        return $out;
    }
}
