import app from 'flarum/forum/app';
import extractText from 'flarum/common/utils/extractText';

export interface Tier {
  label: string;
  color: string;
}

export interface Person {
  id: number;
  displayName: string;
  slug: string;
  avatarUrl: string | null;
}

export interface Entry {
  key?: string;
  label: string;
  image: string | null;
  tier: number | null;
}

export interface Card {
  id: number;
  title: string;
  user: Person | null;
  tiers: Tier[];
  /** Up to six items of each of the top three rows, by row index. */
  preview: Record<string, { label: string; image: string | null }[]>;
  isTemplate: boolean;
  isRemix: boolean;
  parent: { id: number; title: string; user: Person | null } | null;
  items: number;
  likes: number;
  liked: boolean;
  remixes: number;
  discussion: { id: number; slug: string; comments: number } | null;
  createdAt: string | null;
  allItems?: Entry[];
}

export interface Full extends Card {
  description: string | null;
  allItems: Entry[];
  /** item key → [score 0 (top) … 1 (bottom), rankings] */
  crowd: Record<string, [number, number]>;
  familySize: number;
  root: { id: number | null; title?: string; user?: Person | null; isTemplate?: boolean } | null;
  remixList: { id: number; user: Person | null; createdAt: string | null }[];
  mine: { id: number; tiers: number; items: Record<string, number> } | null;
  updatedAt: string | null;
  can: { edit: boolean; remix: boolean; like: boolean; discuss: boolean };
}

export const t = (key: string, params: Record<string, any> = {}) => app.translator.trans(`ernestdefoe-tier-lists.forum.${key}`, params);
export const tt = (key: string, params: Record<string, any> = {}) => extractText(t(key, params));

export const api = (path = '') => `${app.forum.attribute('apiUrl')}/tier-lists${path}`;

/** The list's own page. */
export const listUrl = (id: number | string, action = '') => app.route('tier-lists.show', { id }) + (action ? `/${action}` : '');

/** A row's place on its list, 0 (top) … 1 (bottom); comparable across lists with different row counts. */
export const score = (tier: number, count: number) => (count > 1 ? tier / (count - 1) : 0);

export const rowFor = (value: number, count: number) => Math.max(0, Math.min(count - 1, Math.round(value * (count - 1))));

/** Black or white text, whichever reads on this row colour. */
export function ink(hex: string): string {
  const n = parseInt(hex.slice(1), 16);
  const [r, g, b] = [(n >> 16) & 255, (n >> 8) & 255, n & 255].map((c) => {
    const s = c / 255;
    return s <= 0.03928 ? s / 12.92 : ((s + 0.055) / 1.055) ** 2.4;
  });
  return 0.2126 * r + 0.7152 * g + 0.0722 * b > 0.179 ? '#111' : '#fff';
}

/** Request options that skip core's alert: the caller shows its own sentence (errorText). */
export const quiet = { errorHandler: () => {} } as const;

/** The sentence for an error answer, from its code. */
export function errorText(error: any): string {
  const code = error?.response?.errors?.[0]?.code;
  const known = [
    'tier_lists_bad_title',
    'tier_lists_bad_description',
    'tier_lists_bad_tiers',
    'tier_lists_bad_items',
    'tier_lists_no_items',
    'tier_lists_too_many_items',
    'tier_lists_bad_image',
    'tier_lists_slow_down',
    'tier_lists_discussion_denied',
    'tier_lists_discussion_needs_tag',
    'tier_lists_discussion_failed',
    'tier_lists_no_discussion',
    'tier_lists_has_discussion',
  ];
  return code && known.includes(code) ? tt(`errors.${code.replace('tier_lists_', '')}`, { max: maxItems() }) : tt('errors.generic');
}

/** The most items a list may hold (an admin setting). */
export const maxItems = () => app.forum.attribute<number>('tierListsMaxItems') || 200;

/** The default rows, for a new list. */
export const DEFAULT_TIERS: Tier[] = [
  { label: 'S', color: '#ff7f7f' },
  { label: 'A', color: '#ffbf7f' },
  { label: 'B', color: '#ffdf7f' },
  { label: 'C', color: '#ffff7f' },
  { label: 'D', color: '#bfff7f' },
  { label: 'F', color: '#7fbfff' },
];

declare const flarum: any;

/** Core components the pages build on, loaded through the registry: some live in lazy chunks. */
export const core: { PageStructure?: any; IndexSidebar?: any } = {};

export async function loadCore(): Promise<void> {
  if (core.PageStructure) return;
  const [structure, sidebar] = await Promise.all([
    flarum.reg.asyncModuleImport('flarum/forum/components/PageStructure'),
    flarum.reg.asyncModuleImport('flarum/forum/components/IndexSidebar'),
  ]);
  core.PageStructure = structure.default || structure;
  core.IndexSidebar = sidebar.default || sidebar;
}
