<?php

namespace ErnestDefoe\TierLists;

use Flarum\Api\JsonApi;
use Flarum\Api\Resource\DiscussionResource;
use Flarum\Api\Resource\PostResource;
use Flarum\Discussion\Discussion;
use Flarum\Extension\ExtensionManager;
use Flarum\Http\UrlGenerator;
use Flarum\Locale\TranslatorInterface;
use Flarum\Settings\SettingsRepositoryInterface;
use Flarum\User\User;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * A list's comments are a forum discussion, started as the member through
 * Flarum's own API so every rule and extension applies (approval, flood
 * control, mentions, subscriptions). Its first post is the list's link on a
 * line of its own, which the browser turns into the board itself, in any
 * editor. A remix is a reply in the original's discussion, made the same way.
 */
class Discussions
{
    public function __construct(
        private readonly JsonApi $api,
        private readonly SettingsRepositoryInterface $settings,
        private readonly ExtensionManager $extensions,
        private readonly UrlGenerator $url,
        private readonly TranslatorInterface $translator,
        private readonly LoggerInterface $log,
    ) {
    }

    /** A cheap first answer for the editor's checkbox; start() checks the tag for real. */
    public function canStart(User $actor): bool
    {
        if (! $actor->can('startDiscussion')) {
            return false;
        }

        return ! $this->extensions->isEnabled('flarum-tags') || $this->settings->get(Settings::TAG) || $actor->hasPermission('bypassTagCounts');
    }

    /** @return string|null an error code, or null once the discussion is started and linked */
    public function start(ServerRequestInterface $request, User $actor, TierList $list): ?string
    {
        if (! $actor->can('startDiscussion')) {
            return 'tier_lists_discussion_denied';
        }

        $data = ['type' => 'discussions', 'attributes' => ['title' => $list->title, 'content' => $this->content($list, $list->description)]];

        if ($this->extensions->isEnabled('flarum-tags')) {
            $tag = (int) $this->settings->get(Settings::TAG);
            $model = $tag && class_exists(\Flarum\Tags\Tag::class) ? \Flarum\Tags\Tag::whereVisibleTo($actor)->find($tag) : null;
            if ($model) {
                // process() skips the tag's own check: a restricted tag stays restricted.
                if (! $actor->can('startDiscussion', $model)) {
                    return 'tier_lists_discussion_denied';
                }
                $data['relationships']['tags']['data'] = [['type' => 'tags', 'id' => (string) $tag]];
            } elseif (! $actor->hasPermission('bypassTagCounts')) {
                return 'tier_lists_discussion_needs_tag';
            }
        }

        try {
            /** @var Discussion $discussion */
            $discussion = $this->api->forResource(DiscussionResource::class)
                ->forEndpoint('create')
                // process() merges the base request's data into ours: start from an empty one.
                ->withRequest($request->withParsedBody([])->withQueryParams([]))
                ->process(['data' => $data], [], ['actor' => $actor]);
        } catch (Throwable $e) {
            $this->log->info('[tier-lists] no discussion for list '.$list->id.': '.$e->getMessage());

            return 'tier_lists_discussion_failed';
        }

        $list->discussion_id = $discussion->id;
        $list->save();

        return null;
    }

    /** Post a remix in the original's discussion, when the member may reply there. */
    public function reply(ServerRequestInterface $request, User $actor, TierList $remix, Discussion $discussion): ?string
    {
        if (! $actor->can('reply', $discussion)) {
            return 'tier_lists_discussion_denied';
        }

        $intro = $this->translator->trans('ernestdefoe-tier-lists.lib.remix_reply');
        $data = [
            'type' => 'posts',
            'attributes' => ['content' => $this->content($remix, $intro)],
            'relationships' => ['discussion' => ['data' => ['type' => 'discussions', 'id' => (string) $discussion->id]]],
        ];

        try {
            $this->api->forResource(PostResource::class)
                ->forEndpoint('create')
                ->withRequest($request->withParsedBody([])->withQueryParams([]))
                ->process(['data' => $data], [], ['actor' => $actor]);
        } catch (Throwable $e) {
            $this->log->info('[tier-lists] remix '.$remix->id.' not posted: '.$e->getMessage());

            return 'tier_lists_discussion_failed';
        }

        return null;
    }

    public function link(TierList $list): string
    {
        return $this->url->to('forum')->route('tier-lists.show', ['id' => $list->id]);
    }

    /** Words first, then the link alone on its line: that line becomes the board. */
    private function content(TierList $list, ?string $words): string
    {
        return trim(trim((string) $words)."\n\n".$this->link($list));
    }
}
