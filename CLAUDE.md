# Tier Lists: working rules

- **Commit straight to `main` and push. Never open a pull request on this repo.** Commit subjects are conventional (`feat:`, `fix:`); the release drafter bumps from the subject.
- `js/dist` is committed and is what forums load. After any change under `js/src`, run `npm run build` in `js/` (from a fresh `npm ci`) and commit the bundle; CI fails if it is stale.
- Publishing a release is Ernest's click. Nothing is announced until he says so. Every change goes to the Command Center session for review first.

## Design rules that exist for a reason

- **Item keys belong to the server.** `tier_list_items.key` is an item's identity across a remix family; the crowd average groups by it (`Crowd::averages`, ONE query over the root and every list with that `root_id`). A key from the browser is kept only if it already belongs to the list or its parent (`SaveController::items`), and each only once; anything else gets a new key. Never trust a client key.
- **Ranking a template** takes items (labels, pictures, keys) from the template, never from the browser: the member only places them (`placeTemplate`).
- Scores are normalized per list (`tier / (tier_count - 1)`), so lists with different row counts average fairly. Items in the pool (`tier` null) don't count.
- **Pictures** live only on this forum: our own disk (`Images`, WebP re-encode with `strip`, random names, `tier_list_images` with `claimed`) or a FoF Upload image file (checked against `fof_upload_files`). External URLs are refused (they'd leak readers' IPs). Pruning matches `tier_list_items.image` by FILE NAME. Only unclaimed uploads expire (24 h). FoF Upload pictures the editor uploads are tracked too (`name` `fof:<uuid>` + `url`, via `/images/track`), so they go with the item or list that showed them; they're deleted through FoF's own `DeleteFile` command, and never when FoF records the file in a post (`fof_upload_file_posts`) or it's in the shared library. A FoF file the editor did not track is NEVER deleted: it may be used where FoF records nothing (bios, Page Builder, KB, theme settings). A picture removed in the editor before saving is deleted at once (`DELETE /images`, own + unclaimed only).
- The item cap is the setting `ernestdefoe-tier-lists.max_items` (default 200, ceiling 500, `Settings::maxItems`); the editor reads `tierListsMaxItems`. Checked at 160 pictures: no long tasks on desktop, ~120 ms worst on a 4×-throttled phone.
- extend.php never reads our own class constants or first-class callables at load time (it would autoload them mid-deploy): literals and closures.
- **Comments are a discussion**, started as the member through Flarum's own JSON:API (`Discussions`), so approval, flood control, tags and mentions apply. `process()` skips the endpoint's own checks: `startDiscussion`, the tag's permission and `reply` are asked by hand. The first post is the list's link alone on a line.
- **Embeds are links.** `embed.tsx` upgrades a link to `/tierlists/{id}` that's alone in its paragraph. Every embed on a page is fetched in ONE request (`?ids=`). The board is mounted INSIDE the paragraph: the post body is Mithril's trusted HTML, and moving its top-level nodes breaks the next redraw.
- **The list endpoint is a constant number of queries** (lists + authors, then one each for items, likes, visible discussions, parents). `tests/.../TierListTest::the_list_page_is_a_constant_number_of_queries` guards it.
- `tierListsCanDiscuss` is NOT in the forum payload (it can cost a tag query on every page); the editor asks `/api/tier-lists/abilities` when it opens.
- The notification subject (`TierList`) has an API resource (`TierListResource`): without one, `/api/notifications` 500s for everybody.
- **Drag and drop** (`EditorPage.tsx`): the dragged tile's element stays put (dimmed) and the listeners are on `window`; a touch whose element Mithril rebuilt stops sending events. Touch starts after a 220 ms hold (moving first = scrolling); once a drag is on, a non-passive `touchmove` listener stops the page scrolling. Keyboard moves call `m.redraw.sync()` so focus follows the rebuilt tile before the next key. Never `onkeydown={() => cond && …}` (a `false` return is preventDefault).
- forum.js loads on every page: only routes, nav, notifications, the board renderer and embeds. The three pages are lazy chunks that import only from forum.js modules or their own file (two chunks sharing a module make an unpublished numbered chunk).

## Testing

- `DB_DRIVER=sqlite composer test:setup && DB_DRIVER=sqlite composer test`, `composer analyse:phpstan`.
- `cd js && npm ci && npm run build && npm run format-check`.
