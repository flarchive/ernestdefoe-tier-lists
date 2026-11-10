<?php

namespace ErnestDefoe\TierLists\Api;

use ErnestDefoe\TierLists\TierList;
use Flarum\Api\Resource\AbstractDatabaseResource;
use Flarum\Api\Schema;

/**
 * Exists for the notifications, not for reading (the lists have their own
 * endpoints). 🚨 A notification subject without a registered API resource
 * 500s /api/notifications for EVERY member of the forum.
 *
 * @extends AbstractDatabaseResource<TierList>
 */
class TierListResource extends AbstractDatabaseResource
{
    public function type(): string
    {
        return 'tier-lists';
    }

    public function model(): string
    {
        return TierList::class;
    }

    public function endpoints(): array
    {
        return [];
    }

    public function fields(): array
    {
        return [
            Schema\Str::make('title'),
        ];
    }
}
