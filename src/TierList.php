<?php

namespace ErnestDefoe\TierLists;

use Carbon\Carbon;
use Flarum\Database\AbstractModel;
use Flarum\Discussion\Discussion;
use Flarum\User\User;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property int|null $user_id
 * @property int|null $root_id
 * @property int|null $parent_id
 * @property string $title
 * @property string|null $description
 * @property string $tiers JSON: [{label, color}]
 * @property int $tier_count
 * @property bool $is_template
 * @property int|null $discussion_id
 * @property int $items_count
 * @property int $likes_count
 * @property int $remixes_count
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property User|null $user
 * @property \Illuminate\Database\Eloquent\Collection<int, Item> $items
 */
class TierList extends AbstractModel
{
    protected $table = 'tier_lists';

    public $timestamps = true;

    protected $attributes = ['items_count' => 0, 'likes_count' => 0, 'remixes_count' => 0, 'is_template' => false];

    protected $casts = [
        'items_count' => 'int',
        'likes_count' => 'int',
        'remixes_count' => 'int',
        'tier_count' => 'int',
        'is_template' => 'bool',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    /** The first list of this one's remix family (itself, for an original). */
    public function familyId(): int
    {
        return $this->root_id ?: $this->id;
    }

    /** @return list<array{label: string, color: string}> */
    public function tierRows(): array
    {
        $rows = json_decode($this->tiers, true);

        return is_array($rows) ? array_values($rows) : Settings::DEFAULT_TIERS;
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<Discussion, $this> */
    public function discussion(): BelongsTo
    {
        return $this->belongsTo(Discussion::class);
    }

    /** @return HasMany<Item, $this> */
    public function items(): HasMany
    {
        return $this->hasMany(Item::class, 'tier_list_id')->orderBy('position');
    }
}
