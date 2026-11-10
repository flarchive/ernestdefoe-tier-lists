<?php

namespace ErnestDefoe\TierLists\Notification;

use ErnestDefoe\TierLists\TierList;
use Flarum\Database\AbstractModel;
use Flarum\Notification\AlertableInterface;
use Flarum\Notification\Blueprint\BlueprintInterface;
use Flarum\User\User;

/** "Somebody liked your tier list." Alert only: a like is not worth an email. */
class LikedBlueprint implements AlertableInterface, BlueprintInterface
{
    public function __construct(public TierList $list, public User $liker)
    {
    }

    public function getSubject(): ?AbstractModel
    {
        return $this->list;
    }

    public function getFromUser(): ?User
    {
        return $this->liker;
    }

    public function getData(): mixed
    {
        return null;
    }

    public static function getType(): string
    {
        return 'tierListLiked';
    }

    public static function getSubjectModel(): string
    {
        return TierList::class;
    }
}
