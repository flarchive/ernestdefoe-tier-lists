<?php

namespace ErnestDefoe\TierLists;

use Carbon\Carbon;
use Flarum\Extension\ExtensionManager;
use Flarum\Filesystem\FilesystemManager;
use Flarum\Foundation\Paths;
use Flarum\Http\UrlGenerator;
use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Filesystem\Cloud;
use Illuminate\Contracts\Filesystem\Factory;
use Illuminate\Database\ConnectionInterface;
use Intervention\Image\ImageManager;
use RuntimeException;

/**
 * Item pictures. Where FoF Upload is enabled the browser uploads there and the
 * item shows that file's URL; otherwise pictures come here, to this
 * extension's own disk (public/assets/tier-lists unless a storage driver
 * says otherwise).
 *
 * Every picture uploaded here is decoded and RE-ENCODED as WebP, which drops
 * whatever the original carried besides pixels (a phone photo's location, a
 * payload behind a valid header). The name is random and ours.
 *
 * 🚨 An item may only show a picture that already lives on this forum: one of
 * ours, or a FoF Upload file. Never an address on another site, which would
 * hand every reader's IP address to whoever runs it.
 */
class Images
{
    public const DISK = 'ernestdefoe-tier-lists';

    public const TABLE = 'tier_list_images';

    /** Items are shown small: a 2x tile on a phone never needs more. */
    private const MAX_SIDE = 480;

    private const NAME = '/^[0-9a-f]{32}\.webp$/D';

    /** An upload gets this long to end up on a saved list. */
    public const GRACE_HOURS = 24;

    private Cloud $disk;

    public function __construct(
        Factory $filesystems,
        private readonly ImageManager $images,
        private readonly ConnectionInterface $db,
        private readonly ExtensionManager $extensions,
    ) {
        $this->disk = $filesystems->disk(self::DISK);
    }

    /**
     * The disk's local settings, declared in extend.php.
     *
     * @return array<string, string>
     */
    public static function config(Paths $paths, UrlGenerator $url): array
    {
        return [
            'root' => "$paths->public/assets/tier-lists",
            'url' => $url->to('forum')->path('assets/tier-lists'),
            'visibility' => 'public',
        ];
    }

    /**
     * Purging the extension deletes every picture it stored. The disk is built
     * here: a purge runs with the extension already disabled. Only names of
     * our shape are deleted, so a shared bucket loses nothing else.
     */
    public static function purge(Container $container): void
    {
        $disk = (new FilesystemManager($container, [self::DISK => self::config(...)], $container->make('flarum.filesystem.resolved_drivers')))->disk(self::DISK);

        $disk->delete(preg_grep(self::NAME, $disk->files()));
    }

    /** Re-encode an uploaded file and record it; returns its public URL. */
    public function store(string $path, int $userId): string
    {
        $image = $this->images->read($path);
        $image->scaleDown(width: self::MAX_SIDE, height: self::MAX_SIDE);
        $encoded = (string) $image->toWebp(quality: 82, strip: true);

        if ($encoded === '') {
            throw new RuntimeException('The image could not be encoded.');
        }

        $name = bin2hex(random_bytes(16)).'.webp';
        $this->disk->put($name, $encoded);

        try {
            $this->db->table(self::TABLE)->insert(['name' => $name, 'user_id' => $userId, 'created_at' => Carbon::now()]);
        } catch (\Throwable $e) {
            $this->disk->delete($name);

            throw $e;
        }

        return $this->disk->url($name);
    }

    /** The file name when a URL points at a picture on our disk; null for any other address. */
    public function nameOf(string $url): ?string
    {
        $prefix = substr($this->disk->url('x.webp'), 0, -strlen('x.webp'));

        if (! str_starts_with($url, $prefix)) {
            return null;
        }

        $name = substr($url, strlen($prefix));

        return preg_match(self::NAME, $name) ? $name : null;
    }

    /**
     * Of these URLs, the ones an item may show: pictures on our disk that we
     * recorded, and image files FoF Upload holds. Two queries at most.
     *
     * @param list<string> $urls
     * @return array<string, true>
     */
    public function allowed(array $urls): array
    {
        $urls = array_values(array_unique(array_filter($urls)));
        if (! $urls) {
            return [];
        }

        $allowed = [];
        $names = [];
        foreach ($urls as $url) {
            if (($name = $this->nameOf($url)) !== null) {
                $names[$name] = $url;
            }
        }

        if ($names) {
            foreach ($this->db->table(self::TABLE)->whereIn('name', array_keys($names))->pluck('name') as $name) {
                $allowed[$names[$name]] = true;
            }
        }

        $others = array_values(array_diff($urls, array_values($names)));
        if ($others && $this->extensions->isEnabled('fof-upload')) {
            $files = $this->db->table('fof_upload_files')->whereIn('url', $others)->where('type', 'like', 'image/%')->pluck('url');
            foreach ($files as $url) {
                $allowed[$url] = true;
            }
        }

        return $allowed;
    }

    /**
     * A saved list now shows these pictures (any URL; only ours are recorded).
     *
     * @param list<string> $urls
     */
    public function claim(array $urls): void
    {
        $names = array_values(array_filter(array_map(fn (string $url) => $this->nameOf($url), $urls)));

        if ($names) {
            $this->db->table(self::TABLE)->whereIn('name', $names)->where('claimed', false)->update(['claimed' => true]);
        }
    }

    /**
     * Delete each of these pictures that no item shows any more. By file name,
     * never full URL: a forum that moved keeps the old address in its rows.
     *
     * @param list<string> $urls image URLs that just stopped being shown somewhere
     */
    public function release(array $urls): int
    {
        $names = [];
        foreach ($urls as $url) {
            if (($name = $this->nameOf($url)) !== null) {
                $names[] = $name;
            }
        }

        return $names ? $this->prune($names) : 0;
    }

    /** @param list<string> $names */
    public function prune(array $names): int
    {
        $deleted = 0;

        foreach (array_chunk(array_values(array_unique($names)), 100) as $batch) {
            $used = [];
            $rows = $this->db->table('tier_list_items')
                ->where(function ($query) use ($batch) {
                    foreach ($batch as $name) {
                        $query->orWhere('image', 'like', '%/'.$name);
                    }
                })
                ->pluck('image');

            foreach ($rows as $image) {
                $used[substr((string) strrchr((string) $image, '/'), 1)] = true;
            }

            if ($gone = array_values(array_diff($batch, array_keys($used)))) {
                $this->disk->delete($gone);
                $this->db->table(self::TABLE)->whereIn('name', $gone)->delete();
                $deleted += count($gone);
            }
        }

        return $deleted;
    }

    /**
     * A safety net for pictures marked used that no item shows any more (rows
     * removed outside the extension). A slice per run, from a cursor kept in
     * the cache; losing the cursor only starts the sweep over.
     */
    public function pruneOrphans(\Illuminate\Contracts\Cache\Repository $cache, int $limit = 500): int
    {
        $after = (int) $cache->get('tier-lists.prune-cursor', 0);
        $rows = $this->db->table(self::TABLE)->where('claimed', true)->where('id', '>', $after)->orderBy('id')->limit($limit)->get(['id', 'name']);
        $cache->forever('tier-lists.prune-cursor', $rows->count() < $limit ? 0 : (int) $rows->last()->id);

        return $rows->isEmpty() ? 0 : $this->prune($rows->pluck('name')->all());
    }

    /**
     * Uploads past their grace period that no list shows (an editor closed
     * without saving). Bounded per run.
     */
    public function pruneAbandoned(?int $userId = null, int $limit = 200): int
    {
        $names = $this->db->table(self::TABLE)
            ->where('claimed', false)
            ->where('created_at', '<', Carbon::now()->subHours(self::GRACE_HOURS))
            ->when($userId !== null, fn ($q) => $q->where('user_id', $userId))
            ->orderBy('id')
            ->limit($limit)
            ->pluck('name')
            ->all();

        return $names ? $this->prune($names) : 0;
    }
}
