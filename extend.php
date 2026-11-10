<?php

/*
 * Tier Lists: members rank anything by dragging it into rows (S to F by
 * default), remix each other's lists, and see where the crowd puts each item.
 *
 * Lists live in their own tables and endpoints (src/Api). A list's comments
 * are a forum discussion whose first post is the list's link; a link to a
 * list alone on a line in ANY post is drawn as the board, so it works with
 * every editor and degrades to a plain link if the extension is switched off.
 *
 * forum.js only wires things up and draws embedded boards; the pages and the
 * drag-and-drop editor are lazy chunks.
 */

use ErnestDefoe\TierLists\Access;
use ErnestDefoe\TierLists\Api;
use ErnestDefoe\TierLists\Console\PruneImagesCommand;
use ErnestDefoe\TierLists\Gdpr\TierListData;
use ErnestDefoe\TierLists\Http;
use ErnestDefoe\TierLists\Images;
use ErnestDefoe\TierLists\Notification\LikedBlueprint;
use ErnestDefoe\TierLists\Notification\RemixedBlueprint;
use ErnestDefoe\TierLists\Settings;
use ErnestDefoe\TierLists\TierListsServiceProvider;
use Flarum\Api\Context;
use Flarum\Api\Resource\ForumResource;
use Flarum\Api\Schema;
use Flarum\Extend;
use Flarum\Gdpr\Extend\UserData;
use Illuminate\Console\Scheduling\Event as ScheduledEvent;

return [
    (new Extend\Frontend('forum'))
        ->js(__DIR__.'/js/dist/forum.js')
        // 🚨 Not optional: the pages and the editor are chunks in here, and undeclared chunks 404.
        ->jsDirectory(__DIR__.'/js/dist/forum')
        ->css(__DIR__.'/less/forum.less')
        ->route('/tierlists', 'tier-lists', Http\IndexContent::class)
        ->route('/tierlists/new', 'tier-lists.new')
        ->route('/tierlists/{id:\d+}', 'tier-lists.show', Http\ShowContent::class)
        ->route('/tierlists/{id:\d+}/edit', 'tier-lists.edit')
        ->route('/tierlists/{id:\d+}/remix', 'tier-lists.remix'),

    (new Extend\Frontend('admin'))
        ->js(__DIR__.'/js/dist/admin.js'),

    new Extend\Locales(__DIR__.'/locale'),

    (new Extend\View())
        ->namespace('ernestdefoe-tier-lists', __DIR__.'/views'),

    (new Extend\Filesystem())
        ->disk(Images::DISK, Images::config(...)),

    (new Extend\Routes('api'))
        ->get('/tier-lists', 'tier-lists.index', Api\ListController::class)
        ->post('/tier-lists', 'tier-lists.create', Api\SaveController::class)
        ->get('/tier-lists/abilities', 'tier-lists.abilities', Api\AbilitiesController::class)
        ->post('/tier-lists/images', 'tier-lists.images', Api\UploadController::class)
        ->get('/tier-lists/{id:\d+}', 'tier-lists.read', Api\ShowController::class)
        ->patch('/tier-lists/{id:\d+}', 'tier-lists.update', Api\SaveController::class)
        ->delete('/tier-lists/{id:\d+}', 'tier-lists.delete', Api\DeleteController::class)
        ->post('/tier-lists/{id:\d+}/like', 'tier-lists.like', Api\LikeController::class)
        ->delete('/tier-lists/{id:\d+}/like', 'tier-lists.unlike', Api\LikeController::class)
        ->post('/tier-lists/{id:\d+}/discussion', 'tier-lists.discussion', Api\DiscussionController::class),

    // 🚨 The notification subject needs a resource, or /api/notifications 500s for everyone.
    new Extend\ApiResource(Api\TierListResource::class),

    (new Extend\Notification())
        ->type(RemixedBlueprint::class, ['alert', 'email'])
        ->type(LikedBlueprint::class, ['alert']),

    // The viewer's own permissions, already loaded: no query.
    (new Extend\ApiResource(ForumResource::class))
        ->fields(fn () => [
            Schema\Boolean::make('tierListsCanView')
                ->get(fn ($forum, Context $context) => Access::view($context->getActor())),
            Schema\Boolean::make('tierListsCanCreate')
                ->get(fn ($forum, Context $context) => Access::create($context->getActor())),
        ]),

    (new Extend\Settings())
        ->default(Settings::NAV, true)
        ->serializeToForum('tierListsNav', Settings::NAV, 'boolval'),

    (new Extend\Console())
        ->command(PruneImagesCommand::class)
        ->schedule(PruneImagesCommand::class, function (ScheduledEvent $event): void {
            $event->daily();
        }),

    (new Extend\ServiceProvider())
        ->register(TierListsServiceProvider::class),

    (new Extend\Conditional())
        ->whenExtensionEnabled('flarum-gdpr', fn () => [
            (new UserData())->addType(TierListData::class),
        ]),
];
