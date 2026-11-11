import type Mithril from 'mithril';
import app from 'flarum/forum/app';
import stringToColor from 'flarum/common/utils/stringToColor';
import Link from 'flarum/common/components/Link';
import Avatar from 'flarum/common/components/Avatar';
import { ink, type Entry, type Person, type Tier } from './api';

/**
 * A board you look at (the editor draws its own): rows top to bottom, the
 * row's label in its colour, its items in order. Used by the list page and
 * by lists linked in posts, so it lives in forum.js and stays small.
 */
export interface BoardAttrs {
  tiers: Tier[];
  items: Entry[];
  /** Where each item sits, when not its own `tier` (the crowd view). */
  rowOf?: (item: Entry) => number | null;
  /** A small chip on an item: e.g. where the crowd puts it. */
  badge?: (item: Entry) => Mithril.Children;
  /** Show items nobody placed, in a last "Not ranked" row. */
  pool?: Mithril.Children;
  compact?: boolean;
}

export function Tile({ attrs }: { attrs: { item: Entry; badge?: Mithril.Children } }) {
  return {
    view: ({ attrs }: { attrs: { item: Entry; badge?: Mithril.Children } }) => {
      const { item } = attrs;
      return (
        <div className={'TierTile' + (item.image ? ' TierTile--image' : ' TierTile--text')} title={item.label || undefined}>
          {item.image ? (
            <img src={item.image} alt={item.label} loading="lazy" decoding="async" draggable={false} />
          ) : (
            <span className="TierTile-text">{item.label}</span>
          )}
          {attrs.badge}
        </div>
      );
    },
  };
}

export default function Board() {
  return {
    view: ({ attrs }: { attrs: BoardAttrs }) => {
      const rowOf = attrs.rowOf || ((item: Entry) => item.tier);
      const rows: Entry[][] = attrs.tiers.map(() => []);
      const pool: Entry[] = [];
      for (const item of attrs.items) {
        const row = rowOf(item);
        if (row === null || row === undefined || !rows[row]) pool.push(item);
        else rows[row].push(item);
      }

      return (
        <div className={'TierBoard' + (attrs.compact ? ' TierBoard--compact' : '')}>
          {attrs.tiers.map((tier, index) => (
            <div className="TierBoard-row" key={index}>
              <div className="TierBoard-label" style={{ background: tier.color, color: ink(tier.color) }}>
                <span>{tier.label}</span>
              </div>
              <div className="TierBoard-items">
                {rows[index].map((item) => (
                  <Tile key={item.key || item.label} item={item} badge={attrs.badge?.(item)} />
                ))}
              </div>
            </div>
          ))}
          {attrs.pool && pool.length ? (
            <div className="TierBoard-row TierBoard-row--pool">
              <div className="TierBoard-label TierBoard-label--pool">
                <span>{attrs.pool}</span>
              </div>
              <div className="TierBoard-items">
                {pool.map((item) => (
                  <Tile key={item.key || item.label} item={item} />
                ))}
              </div>
            </div>
          ) : null}
        </div>
      );
    },
  };
}

/** An avatar and a name linking to the member, or "a deleted member". */
export function Byline() {
  return {
    view: ({ attrs }: { attrs: { user: Person | null; fallback: string } }) =>
      attrs.user ? (
        <Link className="TierByline" href={app.route('user', { username: attrs.user.slug })}>
          <Avatar user={avatarUser(attrs.user)} className="TierByline-avatar" />
          <span className="TierByline-name">{attrs.user.displayName}</span>
        </Link>
      ) : (
        <span className="TierByline">{attrs.fallback}</span>
      ),
  };
}

/** Avatar wants a User model; these few methods are all it reads. */
export function avatarUser(user: Person): any {
  return {
    avatarUrl: () => user.avatarUrl,
    displayName: () => user.displayName,
    username: () => user.slug,
    color: () => '#' + stringToColor(user.displayName),
  };
}
