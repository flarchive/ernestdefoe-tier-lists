<?php

namespace ErnestDefoe\TierLists;

use Flarum\Foundation\AbstractServiceProvider;
use Flarum\User\User;

class TierListsServiceProvider extends AbstractServiceProvider
{
    public function boot(): void
    {
        // Before the user row goes, however it goes (the API, GDPR erasure, a
        // console command): the cascade would delete the lists but leave their
        // pictures marked used and other lists' counters too high.
        User::deleting(function (User $user): void {
            resolve(Cleanup::class)->deleteMember((int) $user->id);
        });
    }
}
