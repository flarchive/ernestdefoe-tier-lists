<?php

namespace ErnestDefoe\TierLists;

use Flarum\Database\AbstractModel;

/**
 * @property int $id
 * @property int $tier_list_id
 * @property string $key
 * @property string $label
 * @property string|null $image
 * @property int|null $tier
 * @property int $position
 */
class Item extends AbstractModel
{
    protected $table = 'tier_list_items';

    public $timestamps = false;

    protected $casts = ['tier' => 'int', 'position' => 'int'];
}
