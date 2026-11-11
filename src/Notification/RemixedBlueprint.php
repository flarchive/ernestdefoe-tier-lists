<?php

namespace ErnestDefoe\TierLists\Notification;

use ErnestDefoe\TierLists\TierList;
use Flarum\Database\AbstractModel;
use Flarum\Locale\TranslatorInterface;
use Flarum\Notification\AlertableInterface;
use Flarum\Notification\Blueprint\BlueprintInterface;
use Flarum\Notification\MailableInterface;
use Flarum\User\User;

/**
 * "Somebody remixed your tier list." The subject is the REMIX, so the alert
 * opens it. 🚨 AlertableInterface is what makes the alert exist at all.
 */
class RemixedBlueprint implements AlertableInterface, BlueprintInterface, MailableInterface
{
    public function __construct(public TierList $remix, public User $remixer)
    {
    }

    public function getSubject(): ?AbstractModel
    {
        return $this->remix;
    }

    public function getFromUser(): ?User
    {
        return $this->remixer;
    }

    public function getData(): mixed
    {
        return null;
    }

    public static function getType(): string
    {
        return 'tierListRemixed';
    }

    public static function getSubjectModel(): string
    {
        return TierList::class;
    }

    /** @return array<string, string> */
    public function getEmailViews(): array
    {
        return ['text' => 'ernestdefoe-tier-lists::emails.plain.remixed', 'html' => 'ernestdefoe-tier-lists::emails.html.remixed'];
    }

    public function getEmailSubject(TranslatorInterface $translator): string
    {
        return $translator->trans('ernestdefoe-tier-lists.email.remixed.subject', [
            '{username}' => $this->remixer->display_name,
            '{title}' => $this->remix->title,
        ]);
    }
}
