import app from 'flarum/forum/app';
import Link from 'flarum/common/components/Link';
import Icon from 'flarum/common/components/Icon';
import type Mithril from 'mithril';
import Board, { Byline } from './Board';
import { api, listUrl, t, tt, type Card } from './api';

/**
 * A link to a list alone on its line in a post becomes the board.
 *
 * 🚨 Every embed on the page is fetched in ONE request (ids are queued for a
 * tick, then sent together), never one request per post.
 *
 * 🚨 The post body is Mithril's trusted HTML: Mithril removes and replaces
 * the nodes it inserted, so nothing of ours may stand in for one of them. The
 * board is mounted in a new element right after the link (inside its
 * paragraph, or at the top level when the post is the link alone), the link is
 * hidden, and a board whose link Mithril has since replaced (an edited post) is
 * unmounted on the next pass.
 */

declare const m: Mithril.Static;

const cache = new Map<number, Promise<Card | null>>();
let queue: { id: number; resolve: (card: Card | null) => void }[] = [];
let timer = 0;

function fetchCard(id: number): Promise<Card | null> {
  let promise = cache.get(id);
  if (!promise) {
    promise = new Promise((resolve) => {
      queue.push({ id, resolve });
      if (!timer) timer = window.setTimeout(flush, 0);
    });
    cache.set(id, promise);
  }
  return promise;
}

function flush() {
  timer = 0;
  const batch = queue.splice(0, 20);
  if (queue.length) timer = window.setTimeout(flush, 0);

  app
    .request<{ data: Card[] }>({ method: 'GET', url: api(), params: { ids: batch.map((entry) => entry.id).join(',') } })
    .then((res) => {
      const found = new Map(res.data.map((card) => [card.id, card]));
      batch.forEach((entry) => entry.resolve(found.get(entry.id) || null));
    })
    .catch(() => batch.forEach((entry) => entry.resolve(null)));
}

const PATH = /\/tierlists\/(\d+)\/?$/;

/** The list id, when this link points at a list on this forum. */
function linkedId(anchor: HTMLAnchorElement): number | null {
  const base = new URL(app.forum.attribute<string>('baseUrl') + '/');
  let url: URL;
  try {
    url = new URL(anchor.getAttribute('href') || '', base);
  } catch {
    return null;
  }
  if (url.origin !== base.origin || !url.pathname.startsWith(base.pathname.replace(/\/$/, '') + '/tierlists/')) return null;
  const match = url.pathname.match(PATH);
  return match ? Number(match[1]) : null;
}

/** Upgrade the lone list links in a rendered post. Safe to call on every redraw. */
export function upgradeLinks(root: HTMLElement | null) {
  if (!root || !app.forum.attribute('tierListsCanView')) return;

  // Boards whose link is gone (the post was edited and re-rendered).
  root.querySelectorAll<HTMLElement>('.TierEmbed-mount').forEach((mount) => {
    const anchor = mount.previousElementSibling as HTMLElement | null;
    if (!anchor || !anchor.dataset.tierEmbed) {
      m.mount(mount, null);
      mount.remove();
    }
  });

  root.querySelectorAll<HTMLAnchorElement>('.Post-body a[href*="/tierlists/"]').forEach((anchor) => {
    if (anchor.dataset.tierEmbed) return;
    const host = anchor.parentElement;
    if (!host || !['P', 'DIV'].includes(host.tagName)) return;
    // Alone on its line: its paragraph (or the whole post) holds the link and nothing else.
    if ((host.textContent || '').trim() !== (anchor.textContent || '').trim() || host.querySelectorAll('a').length !== 1) return;

    const id = linkedId(anchor);
    if (!id) return;

    anchor.dataset.tierEmbed = String(id);
    const mount = document.createElement('div');
    mount.className = 'TierEmbed-mount';
    anchor.after(mount);

    fetchCard(id).then((card) => {
      if (!card || !mount.isConnected) return;
      anchor.hidden = true;
      m.mount(mount, { view: () => <Embed card={card} /> });
    });
  });
}

/** Unmount the boards of a post that's going away. */
export function releaseLinks(root: HTMLElement | null) {
  root?.querySelectorAll<HTMLElement>('.TierEmbed-mount').forEach((mount) => m.mount(mount, null));
}

function Embed() {
  return {
    view: ({ attrs }: { attrs: { card: Card } }) => {
      const card = attrs.card;
      return (
        <figure className="TierEmbed">
          <figcaption className="TierEmbed-head">
            <Link className="TierEmbed-title" href={listUrl(card.id)}>
              <Icon name="fas fa-layer-group" /> {card.title}
            </Link>
            <Byline user={card.user} fallback={tt('deleted_member')} />
          </figcaption>
          <Board tiers={card.tiers} items={card.allItems || []} compact />
          <div className="TierEmbed-foot">
            <span>
              <Icon name="far fa-heart" /> {card.likes}
            </span>
            <span>
              <Icon name="fas fa-shuffle" /> {card.remixes}
            </span>
            <Link className="Button Button--primary Button--small" href={listUrl(card.id, card.isTemplate ? 'remix' : '')}>
              {card.isTemplate ? t('embed.rank_it') : t('embed.open')}
            </Link>
          </div>
        </figure>
      );
    },
  };
}
