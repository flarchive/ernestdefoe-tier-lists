<?php

namespace ErnestDefoe\TierLists;

use Carbon\Carbon;
use Flarum\User\User;

/** Who may do what. Every list is public to whoever may view tier lists. */
final class Access
{
    public static function view(User $actor): bool
    {
        return $actor->hasPermission(Settings::VIEW);
    }

    public static function create(User $actor): bool
    {
        return ! $actor->isGuest() && $actor->hasPermission(Settings::CREATE) && ! self::suspended($actor);
    }

    public static function moderate(User $actor): bool
    {
        return $actor->hasPermission(Settings::MODERATE);
    }

    public static function edit(User $actor, TierList $list): bool
    {
        return self::moderate($actor) || ($list->user_id === $actor->id && self::create($actor));
    }

    /** flarum/suspend keeps its own abilities; ours are checked here so a suspended member can't make lists either. */
    private static function suspended(User $actor): bool
    {
        $until = $actor->getAttribute('suspended_until');

        return $until !== null && Carbon::parse($until)->isFuture();
    }
}
