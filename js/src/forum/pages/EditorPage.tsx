import app from 'flarum/forum/app';
import Page from 'flarum/common/components/Page';
import Button from 'flarum/common/components/Button';
import Link from 'flarum/common/components/Link';
import LoadingIndicator from 'flarum/common/components/LoadingIndicator';
import Icon from 'flarum/common/components/Icon';
import Switch from 'flarum/common/components/Switch';
import type Mithril from 'mithril';
import { api, core, DEFAULT_TIERS, errorText, ink, listUrl, quiet, t, tt, type Full } from '../api';

/**
 * Make, edit or remix a list.
 *
 * Moving an item, four ways that all end in move():
 *  - mouse or pen: drag it (starts after 6px of movement);
 *  - touch: hold it for a moment, then drag; a quick swipe still scrolls the
 *    page, so a long board stays usable on a phone;
 *  - keyboard: Space picks it up, arrows move it, Space drops it, Escape puts
 *    it back; every step is announced to screen readers;
 *  - a tap (or M) opens a menu with a button for each row.
 *
 * 🚨 The dragged item's element stays where it was (dimmed) for the whole
 * drag and the listeners are on window: moving it to another row would make
 * Mithril rebuild it, and a touch whose element is gone stops sending events.
 * A placeholder shows where it will land.
 */

declare const m: Mithril.Static;

interface Row {
  uid: string;
  label: string;
  color: string;
}

interface Item {
  uid: string;
  key?: string;
  label: string;
  image: string | null;
  tier: number | null;
  uploading?: boolean;
  /** When this editor uploaded the picture: an upload no saved list claims is deleted after a day. */
  uploadedAt?: number;
}

interface Target {
  tier: number | null;
  before: string | null;
}

interface Drag {
  uid: string;
  pointerId: number;
  touch: boolean;
  x: number;
  y: number;
  sx: number;
  sy: number;
  active: boolean;
  timer: number;
  ghost: HTMLElement | null;
  dx: number;
  dy: number;
  target: Target | null;
  armTop?: boolean;
  armBottom?: boolean;
}

const PALETTE = ['#ff7f7f', '#ffbf7f', '#ffdf7f', '#ffff7f', '#bfff7f', '#7fffbf', '#7fbfff', '#bf7fff', '#ff7fdf', '#c0c0c0'];

let counter = 0;
const uid = () => 'u' + ++counter;

export default class EditorPage extends Page {
  mode: 'new' | 'edit' | 'remix' = 'new';
  id: string | null = null;
  source: Full | null = null;
  loading = true;
  missing = false;

  title = '';
  description = '';
  rows: Row[] = [];
  items: Item[] = [];
  isTemplate = false;
  discussion = false;
  canDiscuss = false;
  /** Ranking a template: its items are fixed. */
  locked = false;
  editingRows = false;

  newLabel = '';
  saving = false;
  restored = false;
  /** Pictures dropped from a restored draft because the server has deleted them. */
  expired = 0;
  error = '';

  drag: Drag | null = null;
  grabbed: { uid: string; tier: number | null; index: number } | null = null;
  focusUid: string | null = null;
  sheet: string | null = null;
  live = '';
  private suppressClick = false;
  private frame = 0;
  private draftTimer = 0;

  oninit(vnode: Mithril.Vnode) {
    super.oninit(vnode);
    this.bodyClass = 'App--tierLists';
    const path = m.route.get().split('?')[0];
    this.id = m.route.param('id') || null;
    this.mode = !this.id ? 'new' : /\/remix\/?$/.test(path) ? 'remix' : 'edit';
    app.history.push('tier-list-editor', tt('editor.title_' + this.mode));
    // The phone header's section menu names the page it belongs to.
    app.current.set('titleControlLabel', tt('index.title'));

    if (!app.session.user) {
      this.loading = false;
      return;
    }

    const abilities = app
      .request<{ create: boolean; discuss: boolean }>({ method: 'GET', url: api('/abilities'), ...quiet })
      .catch(() => ({ create: false, discuss: false }));
    const source = this.id ? app.request<{ data: Full }>({ method: 'GET', url: api('/' + this.id), ...quiet }).then((res) => res.data) : null;

    Promise.all([abilities, source])
      .then(([abilities, list]) => {
        this.canDiscuss = abilities.discuss;
        if (list) this.from(list);
        else {
          this.rows = DEFAULT_TIERS.map((tier) => ({ uid: uid(), ...tier }));
          this.discussion = this.canDiscuss;
        }
        if (this.mode !== 'edit') this.restore();
      })
      .catch(() => (this.missing = true))
      .finally(() => {
        this.loading = false;
        app.setTitle(tt('editor.title_' + this.mode));
        m.redraw();
      });
  }

  /** Start from an existing list: to edit it, or to remix it. */
  from(list: Full) {
    this.source = list;
    this.rows = list.tiers.map((tier) => ({ uid: uid(), ...tier }));
    const template = list.isTemplate || !!list.root?.isTemplate;
    // Ranking a template (or editing such a ranking): the items are the template's.
    this.locked = this.mode === 'remix' ? template : !!list.root?.isTemplate;
    // Ranking a template starts from an empty board; a remix starts from the list.
    this.items = list.allItems.map((item) => ({
      uid: uid(),
      key: item.key,
      label: item.label,
      image: item.image,
      tier: this.mode === 'remix' && template ? null : item.tier,
    }));

    if (this.mode === 'edit') {
      this.title = list.title;
      this.description = list.description || '';
      this.isTemplate = list.isTemplate;
      this.discussion = false;
    } else {
      const original = list.root?.title || list.title;
      this.title = template ? original : tt('editor.remix_title', { title: original });
      this.discussion = !!list.discussion;
    }
  }

  // Drafts: a new list or a remix survives a closed tab. Storage may be off; never let that break the editor.

  draftKey() {
    return `tier-lists-draft:${this.mode}:${this.id || 'new'}`;
  }

  restore() {
    try {
      const raw = window.localStorage.getItem(this.draftKey());
      const draft = raw && JSON.parse(raw);
      if (!draft || !Array.isArray(draft.rows) || !Array.isArray(draft.items)) return;
      this.title = draft.title || '';
      this.description = draft.description || '';
      this.rows = draft.rows.map((row: Row) => ({ ...row, uid: uid() }));
      // Pictures uploaded for a draft are deleted by the server after a day if no
      // list claims them: drop the ones that are that old, and say so.
      const stale = Date.now() - 22 * 3600 * 1000;
      let dropped = 0;
      const items: Item[] = draft.items.map((item: Item) => {
        if (item.image && item.uploadedAt && item.uploadedAt < stale) {
          dropped++;
          return { ...item, image: null, uploadedAt: undefined };
        }
        return item;
      });
      this.items = items.filter((item) => this.locked || item.label || item.image).map((item) => ({ ...item, uid: uid() }));
      this.expired = dropped;
      this.isTemplate = !!draft.isTemplate;
      this.restored = true;
    } catch {
      // Nothing to restore.
    }
  }

  saveDraft() {
    window.clearTimeout(this.draftTimer);
    if (this.mode === 'edit') return;
    this.draftTimer = window.setTimeout(() => {
      try {
        const items = this.items
          .filter((item) => !item.uploading)
          .map(({ key, label, image, tier, uploadedAt }) => ({ key, label, image, tier, uploadedAt }));
        window.localStorage.setItem(
          this.draftKey(),
          JSON.stringify({ title: this.title, description: this.description, rows: this.rows, items, isTemplate: this.isTemplate })
        );
      } catch {
        // Storage full or off.
      }
    }, 400);
  }

  discardDraft() {
    try {
      window.localStorage.removeItem(this.draftKey());
    } catch {
      // Storage off.
    }
  }

  oncreate(vnode: Mithril.VnodeDOM) {
    super.oncreate(vnode);
    this.measureDock();
    window.addEventListener('resize', this.measureDock);
    window.addEventListener('scroll', this.onScroll, { passive: true });
    // 🚨 Registered up front, not when a drag starts: WebKit decides whether a
    // touch may scroll from the listeners present when it begins.
    vnode.dom.addEventListener('touchmove', this.onTouchMove, { passive: false });
  }

  onupdate(vnode: Mithril.VnodeDOM) {
    super.onupdate(vnode);
    this.onScroll();
  }

  onremove(vnode: Mithril.VnodeDOM) {
    super.onremove(vnode);
    this.endDrag(false);
    window.removeEventListener('resize', this.measureDock);
    window.removeEventListener('scroll', this.onScroll);
    cancelAnimationFrame(this.dockFrame);
    vnode.dom.removeEventListener('touchmove', this.onTouchMove);
  }

  /**
   * How much of the bottom of the screen a fixed bar covers (Mobile Tab, a
   * theme's tab bar): the docked pool sits above it, and dragging scrolls
   * before reaching it. Measured, because every bar is different.
   */
  dock = 0;

  ceiling = 0;

  /** A fixed element (not ours) covering the middle of the screen at this height. */
  fixedAt(y: number): Element | null {
    for (const el of document.elementsFromPoint(window.innerWidth / 2, y)) {
      if (el.closest('.TierEditor')) continue;
      for (let node: Element | null = el; node && node !== document.body; node = node.parentElement) {
        if (getComputedStyle(node).position === 'fixed') return node;
      }
    }
    return null;
  }

  measureDock = () => {
    let top = window.innerHeight;
    for (const el of document.elementsFromPoint(window.innerWidth / 2, window.innerHeight - 2)) {
      if (el.closest('.TierEditor')) continue;
      for (let node: Element | null = el; node && node !== document.body; node = node.parentElement) {
        if (getComputedStyle(node).position === 'fixed') {
          top = Math.min(top, node.getBoundingClientRect().top);
          break;
        }
      }
    }
    // And the fixed bars across the top (the header, a score ticker): stacked, so walk down them.
    let ceiling = 0;
    for (let i = 0; i < 4; i++) {
      const fixed = this.fixedAt(ceiling + 2);
      if (!fixed) break;
      ceiling = Math.max(ceiling + 3, fixed.getBoundingClientRect().bottom);
    }
    this.ceiling = Math.round(ceiling);

    const dock = Math.max(0, Math.round(window.innerHeight - top));
    if (dock !== this.dock) {
      this.dock = dock;
      document.documentElement.style.setProperty('--tier-dock-offset', dock + 'px');
    }
    this.checkDock();
  };

  /**
   * On a phone, while the pool's own place is below the screen, the pool is
   * docked to the bottom (fixed) so items can be dragged up into the rows.
   * 🚨 Not `position: sticky`: on iOS the forum's body is its own scroll box
   * (overflow-x: hidden), and sticky never docks there.
   */
  docked = false;
  slotHeight = 0;
  private dockFrame = 0;

  onScroll = () => {
    cancelAnimationFrame(this.dockFrame);
    this.dockFrame = requestAnimationFrame(() => this.checkDock());
  };

  checkDock() {
    const slot = document.querySelector<HTMLElement>('.TierEditor-poolSlot');
    const pool = slot?.firstElementChild as HTMLElement | null;
    if (!slot || !pool) return;

    const floor = window.innerHeight - this.dock;
    const natural = this.docked ? this.slotHeight : pool.offsetHeight;
    const want =
      window.matchMedia('(max-width: 767px)').matches &&
      this.items.some((item) => item.tier === null) &&
      slot.getBoundingClientRect().top > floor - Math.min(natural, window.innerHeight * 0.4);

    if (want !== this.docked) {
      if (want) this.slotHeight = pool.offsetHeight;
      this.docked = want;
      m.redraw();
    }
    const rect = slot.getBoundingClientRect();
    pool.style.left = want ? rect.left + 'px' : '';
    pool.style.width = want ? rect.width + 'px' : '';
  }

  view() {
    const { PageStructure, IndexSidebar } = core;
    return (
      <PageStructure className="TierEditorPage" sidebar={() => <IndexSidebar />}>
        {this.body()}
      </PageStructure>
    );
  }

  body() {
    if (this.loading) return <LoadingIndicator />;
    if (!app.session.user) {
      return (
        <div className="TierListsPage-empty">
          <p>{t('editor.sign_in')}</p>
          <Button className="Button Button--primary" onclick={() => app.modal.show(() => import('flarum/forum/components/LogInModal'))}>
            {t('editor.sign_in_button')}
          </Button>
        </div>
      );
    }
    if (this.missing || (this.mode === 'edit' && this.source && !this.source.can.edit)) {
      return <p className="TierListsPage-empty">{t('show.missing')}</p>;
    }

    return (
      <div className="TierEditor">
        <div className="visually-hidden" aria-live="assertive" aria-atomic="true">
          {this.live}
        </div>
        <p className="visually-hidden" id="TierEditor-help">
          {t('editor.keyboard_help')}
        </p>

        <h2 className="TierEditor-heading">{t('editor.title_' + this.mode)}</h2>

        {this.restored ? (
          <div className="TierEditor-restored">
            <Icon name="fas fa-clock-rotate-left" /> {t('editor.restored')}{' '}
            {this.expired ? t('editor.restored_expired', { count: this.expired }) : null}{' '}
            <Button className="Button Button--link" onclick={() => this.startOver()}>
              {t('editor.start_over')}
            </Button>
          </div>
        ) : null}

        {this.locked ? (
          <p className="TierEditor-note">
            <Icon name="fas fa-clipboard-list" /> {t('editor.template_note')}
          </p>
        ) : null}

        <div className="Form TierEditor-fields">
          <div className="Form-group">
            <label for="TierEditor-title">{t('editor.list_title')}</label>
            <input
              id="TierEditor-title"
              className="FormControl"
              maxlength={100}
              placeholder={tt('editor.title_placeholder')}
              value={this.title}
              oninput={(e: InputEvent) => {
                this.title = (e.target as HTMLInputElement).value;
                this.saveDraft();
              }}
            />
          </div>
          <div className="Form-group">
            <label for="TierEditor-description">{t('editor.description')}</label>
            <textarea
              id="TierEditor-description"
              className="FormControl"
              rows={2}
              maxlength={1000}
              placeholder={tt('editor.description_placeholder')}
              value={this.description}
              oninput={(e: InputEvent) => {
                this.description = (e.target as HTMLTextAreaElement).value;
                this.saveDraft();
              }}
            />
          </div>
        </div>

        <div className="TierEditor-boardHead">
          <Button
            className="Button Button--link TierEditor-rowsToggle"
            icon={this.editingRows ? 'fas fa-check' : 'fas fa-sliders'}
            aria-pressed={this.editingRows ? 'true' : 'false'}
            onclick={() => (this.editingRows = !this.editingRows)}
          >
            {this.editingRows ? t('editor.rows_done') : t('editor.edit_rows')}
          </Button>
        </div>

        <div className={'TierBoard TierEditor-board' + (this.drag?.active ? ' is-dragging' : '')}>
          {this.rows.map((row, index) => this.row(row, index))}
          {this.editingRows && this.rows.length < 10 ? (
            <Button className="Button TierEditor-addRow" icon="fas fa-plus" onclick={() => this.addRow()}>
              {t('editor.add_row')}
            </Button>
          ) : null}
        </div>

        <div className="TierEditor-poolSlot" style={this.docked ? { minHeight: this.slotHeight + 'px' } : undefined}>
          {this.pool()}
        </div>

        {this.options()}

        {this.error ? (
          <p className="TierEditor-error" role="alert">
            {this.error}
          </p>
        ) : null}

        <div className="TierEditor-submit">
          <Button className="Button Button--primary" icon="fas fa-check" loading={this.saving} disabled={this.saving} onclick={() => this.save()}>
            {this.mode === 'edit' ? t('editor.save') : this.locked ? t('editor.publish_ranking') : t('editor.publish')}
          </Button>
          <Link className="Button Button--link" href={this.source ? listUrl(this.source.id) : app.route('tier-lists')}>
            {t('editor.cancel')}
          </Link>
        </div>

        {this.sheet ? this.moveSheet() : null}
      </div>
    );
  }

  row(row: Row, index: number) {
    const items = this.items.filter((item) => item.tier === index);
    return (
      <div className="TierBoard-row TierEditor-row" data-zone={index} key={row.uid}>
        {this.editingRows ? (
          <div className="TierEditor-rowEdit" style={{ background: row.color }}>
            <input
              className="FormControl TierEditor-rowLabel"
              value={row.label}
              maxlength={24}
              aria-label={tt('editor.row_label', { number: index + 1 })}
              style={{ color: ink(row.color) }}
              oninput={(e: InputEvent) => {
                row.label = (e.target as HTMLInputElement).value;
                this.saveDraft();
              }}
            />
            <div className="TierEditor-rowTools">
              <input
                type="color"
                className="TierEditor-color"
                value={row.color}
                aria-label={tt('editor.row_color', { label: row.label })}
                oninput={(e: InputEvent) => {
                  row.color = (e.target as HTMLInputElement).value.toLowerCase();
                  this.saveDraft();
                }}
              />
              <Button
                className="Button Button--icon Button--flat"
                icon="fas fa-arrow-up"
                aria-label={tt('editor.row_up')}
                disabled={index === 0}
                onclick={() => this.moveRow(index, -1)}
              />
              <Button
                className="Button Button--icon Button--flat"
                icon="fas fa-arrow-down"
                aria-label={tt('editor.row_down')}
                disabled={index === this.rows.length - 1}
                onclick={() => this.moveRow(index, 1)}
              />
              <Button
                className="Button Button--icon Button--flat"
                icon="fas fa-trash"
                aria-label={tt('editor.row_delete', { label: row.label })}
                disabled={this.rows.length <= 2}
                onclick={() => this.deleteRow(index)}
              />
            </div>
          </div>
        ) : (
          <div className="TierBoard-label" style={{ background: row.color, color: ink(row.color) }}>
            <span>{row.label}</span>
          </div>
        )}
        <div className="TierBoard-items TierEditor-drop" role="list" aria-label={tt('editor.row_name', { label: row.label })}>
          {this.tiles(items, index)}
        </div>
      </div>
    );
  }

  pool() {
    const items = this.items.filter((item) => item.tier === null);
    return (
      <section
        className={'TierEditor-pool' + (items.length ? '' : ' is-empty') + (this.docked ? ' is-docked' : '')}
        data-zone="pool"
        ondragover={(e: DragEvent) => {
          if (!this.locked && e.dataTransfer?.types.includes('Files')) e.preventDefault();
        }}
        ondrop={(e: DragEvent) => {
          if (this.locked || !e.dataTransfer?.files.length) return;
          e.preventDefault();
          this.upload(Array.from(e.dataTransfer.files));
        }}
      >
        <h3 className="TierEditor-poolTitle">
          {t('editor.pool')} <span className="TierEditor-count">{items.length}</span>
        </h3>
        <div className="TierEditor-drop TierEditor-poolItems" role="list" aria-label={tt('editor.pool')}>
          {this.tiles(items, null)}
          {!items.length ? <p className="TierEditor-poolEmpty">{this.items.length ? t('editor.pool_done') : t('editor.pool_empty')}</p> : null}
        </div>
        {/* Docked, the pool is just the items: adding happens where it lives. */}
        {this.locked || this.docked ? null : this.adder()}
      </section>
    );
  }

  adder() {
    const full = this.items.length >= 120;
    return (
      <form
        className="TierEditor-add"
        onsubmit={(e: SubmitEvent) => {
          e.preventDefault();
          this.addText(this.newLabel);
          // Clear the field now, not at the next redraw: fast typing would land in the old text.
          const field = (e.target as HTMLFormElement).querySelector('input');
          if (field) field.value = '';
        }}
      >
        <input
          className="FormControl"
          value={this.newLabel}
          maxlength={80}
          disabled={full}
          placeholder={tt('editor.add_placeholder')}
          aria-label={tt('editor.add_placeholder')}
          oninput={(e: InputEvent) => (this.newLabel = (e.target as HTMLInputElement).value)}
          onpaste={(e: ClipboardEvent) => this.paste(e)}
        />
        <Button type="submit" className="Button" icon="fas fa-plus" disabled={full}>
          {t('editor.add')}
        </Button>
        <label className={'Button TierEditor-upload' + (full ? ' disabled' : '')}>
          <Icon name="fas fa-image" className="Button-icon" />
          <span className="Button-label">{t('editor.add_pictures')}</span>
          <input
            type="file"
            accept="image/jpeg,image/png,image/webp"
            multiple
            disabled={full}
            className="visually-hidden"
            onchange={(e: Event) => {
              const input = e.target as HTMLInputElement;
              this.upload(Array.from(input.files || []));
              input.value = '';
            }}
          />
        </label>
        <p className="TierEditor-addHelp">{full ? t('editor.full') : t('editor.add_help')}</p>
      </form>
    );
  }

  tiles(items: Item[], tier: number | null) {
    const target = this.drag?.active ? this.drag.target : null;
    const placeholder = <div className="TierTile TierTile--placeholder" key="placeholder" aria-hidden="true" />;
    const out: Mithril.Children[] = [];

    for (const item of items) {
      if (target && target.tier === tier && target.before === item.uid && !this.isOrigin(target)) out.push(placeholder);
      out.push(this.tile(item, tier));
    }
    if (target && target.tier === tier && target.before === null && !this.isOrigin(target)) out.push(placeholder);

    return out;
  }

  tile(item: Item, tier: number | null) {
    const dragging = this.drag?.active && this.drag.uid === item.uid;
    const grabbed = this.grabbed?.uid === item.uid;
    const where = tier === null ? tt('editor.pool') : this.rows[tier].label;
    const siblings = this.items.filter((other) => other.tier === tier);

    return (
      <button
        type="button"
        role="listitem"
        key={item.uid}
        data-uid={item.uid}
        className={
          'TierTile TierEditor-tile' +
          (item.image ? ' TierTile--image' : ' TierTile--text') +
          (dragging ? ' is-dragging' : '') +
          (grabbed ? ' is-grabbed' : '') +
          (item.uploading ? ' is-uploading' : '')
        }
        aria-label={tt('editor.item_label', {
          label: item.label || tt('editor.picture'),
          where,
          position: siblings.indexOf(item) + 1,
          total: siblings.length,
        })}
        aria-describedby="TierEditor-help"
        aria-pressed={grabbed ? 'true' : 'false'}
        title={item.label || undefined}
        disabled={item.uploading}
        onpointerdown={(e: PointerEvent) => this.pointerDown(e, item)}
        onclick={() => this.tap(item)}
        onkeydown={(e: KeyboardEvent) => this.key(e, item)}
        onkeyup={(e: KeyboardEvent) => {
          // A button clicks on Space's keyup: that must not open the menu after a drop.
          if (e.key === ' ') e.preventDefault();
        }}
        onblur={() => this.blurred(item)}
        oncontextmenu={(e: Event) => e.preventDefault()}
        oncreate={(vnode: Mithril.VnodeDOM) => this.refocus(vnode, item)}
        onupdate={(vnode: Mithril.VnodeDOM) => this.refocus(vnode, item)}
      >
        {item.image ? (
          <img src={item.image} alt="" draggable={false} decoding="async" />
        ) : item.uploading ? null : (
          <span className="TierTile-text">{item.label}</span>
        )}
        {item.uploading ? <LoadingIndicator size="small" display="unset" /> : null}
      </button>
    );
  }

  /**
   * Focus left a tile. A keyboard move rebuilds the element and focuses the new
   * one, so wait a tick: only when focus really went elsewhere does a held item
   * drop and the tile stop claiming the focus.
   */
  blurred(item: Item) {
    window.setTimeout(() => {
      if ((document.activeElement as HTMLElement | null)?.dataset?.uid === item.uid) return;
      if (this.grabbed?.uid === item.uid) this.drop();
      if (this.focusUid === item.uid) this.focusUid = null;
      m.redraw();
    }, 0);
  }

  /** After a keyboard move Mithril may rebuild the element: put the focus back on it. */
  refocus(vnode: Mithril.VnodeDOM, item: Item) {
    if (this.focusUid === item.uid && document.activeElement !== vnode.dom) {
      (vnode.dom as HTMLElement).focus({ preventScroll: false });
    }
  }

  options() {
    const original = this.mode === 'new' || (this.mode === 'edit' && !this.source?.root);
    const startDiscussion = original && this.canDiscuss && !(this.mode === 'edit' && this.source?.discussion);
    const shareRemix = this.mode === 'remix' && !!this.source?.discussion;
    if (!original && !shareRemix) return null;

    return (
      <div className="TierEditor-options">
        {original ? (
          <Switch
            state={this.isTemplate}
            onchange={(value: boolean) => {
              this.isTemplate = value;
              this.saveDraft();
            }}
          >
            {t('editor.template')}
            <span className="helpText">{t('editor.template_help')}</span>
          </Switch>
        ) : null}
        {startDiscussion ? (
          <Switch state={this.discussion} onchange={(value: boolean) => (this.discussion = value)}>
            {t('editor.discussion')}
            <span className="helpText">{t('editor.discussion_help')}</span>
          </Switch>
        ) : null}
        {shareRemix ? (
          <Switch state={this.discussion} onchange={(value: boolean) => (this.discussion = value)}>
            {t('editor.share_remix')}
            <span className="helpText">{t('editor.share_remix_help')}</span>
          </Switch>
        ) : null}
      </div>
    );
  }

  moveSheet() {
    const item = this.items.find((other) => other.uid === this.sheet);
    if (!item) return null;
    const close = () => {
      this.focusUid = item.uid;
      this.sheet = null;
    };
    const place = (tier: number | null) => {
      this.move(item.uid, { tier, before: null });
      this.announce(
        tt('editor.moved', { label: item.label || tt('editor.picture'), where: tier === null ? tt('editor.pool') : this.rows[tier].label })
      );
      close();
    };

    return (
      <div
        className="TierSheet"
        role="dialog"
        aria-modal="true"
        aria-label={tt('editor.move_title', { label: item.label || tt('editor.picture') })}
        onkeydown={(e: KeyboardEvent) => {
          if (e.key === 'Escape') {
            e.preventDefault();
            close();
          }
        }}
      >
        <div className="TierSheet-backdrop" onclick={close} />
        <div
          className="TierSheet-panel"
          oncreate={(vnode: Mithril.VnodeDOM) => (vnode.dom.querySelector('button.TierSheet-tier') as HTMLElement)?.focus()}
        >
          <div className="TierSheet-head">
            {item.image ? <img src={item.image} alt="" /> : null}
            {this.locked ? (
              <strong>{item.label}</strong>
            ) : (
              <input
                className="FormControl"
                value={item.label}
                maxlength={80}
                placeholder={tt(item.image ? 'editor.caption_placeholder' : 'editor.add_placeholder')}
                aria-label={tt('editor.rename')}
                oninput={(e: InputEvent) => {
                  item.label = (e.target as HTMLInputElement).value;
                  this.saveDraft();
                }}
              />
            )}
            <Button className="Button Button--icon Button--flat" icon="fas fa-xmark" aria-label={tt('editor.close')} onclick={close} />
          </div>
          <p className="TierSheet-hint">{t('editor.move_to')}</p>
          <div className="TierSheet-tiers">
            {this.rows.map((row, index) => (
              <button
                type="button"
                className={'TierSheet-tier' + (item.tier === index ? ' is-current' : '')}
                style={{ background: row.color, color: ink(row.color) }}
                aria-current={item.tier === index ? 'true' : undefined}
                onclick={() => place(index)}
              >
                {row.label}
              </button>
            ))}
            <button
              type="button"
              className={'TierSheet-tier TierSheet-tier--pool' + (item.tier === null ? ' is-current' : '')}
              onclick={() => place(null)}
            >
              {t('editor.unranked')}
            </button>
          </div>
          {this.locked ? null : (
            <Button
              className="Button Button--danger TierSheet-remove"
              icon="fas fa-trash"
              onclick={() => {
                this.remove(item);
                this.sheet = null;
              }}
            >
              {t('editor.remove')}
            </Button>
          )}
        </div>
      </div>
    );
  }

  // ─── Moving ────────────────────────────────────────────────────────────

  /** Put an item in a row (null = the pool), before another item or at the end. */
  move(itemUid: string, target: Target) {
    const index = this.items.findIndex((item) => item.uid === itemUid);
    if (index < 0) return;
    const [item] = this.items.splice(index, 1);
    item.tier = target.tier;

    let at = target.before ? this.items.findIndex((other) => other.uid === target.before) : -1;
    if (at < 0) {
      // After the last item already in that row.
      at = this.items.length;
      for (let i = this.items.length - 1; i >= 0; i--) {
        if (this.items[i].tier === target.tier) {
          at = i + 1;
          break;
        }
      }
    }
    this.items.splice(at, 0, item);
    this.saveDraft();
  }

  /** Would dropping here leave the item where it is? */
  isOrigin(target: Target): boolean {
    const drag = this.drag;
    if (!drag) return false;
    const item = this.items.find((other) => other.uid === drag.uid);
    if (!item || item.tier !== target.tier) return false;
    const row = this.items.filter((other) => other.tier === target.tier);
    const next = row[row.indexOf(item) + 1];
    return target.before === item.uid || target.before === (next ? next.uid : null);
  }

  pointerDown(e: PointerEvent, item: Item) {
    if (item.uploading || (e.pointerType === 'mouse' && e.button !== 0) || this.drag) return;
    const el = e.currentTarget as HTMLElement;
    const rect = el.getBoundingClientRect();
    const touch = e.pointerType === 'touch';

    this.drag = {
      uid: item.uid,
      pointerId: e.pointerId,
      touch,
      x: e.clientX,
      y: e.clientY,
      sx: e.clientX,
      sy: e.clientY,
      active: false,
      timer: 0,
      ghost: null,
      dx: e.clientX - rect.left,
      dy: e.clientY - rect.top,
      target: null,
    };

    // A touch becomes a drag after a short hold; moving first means scrolling.
    if (touch) this.drag.timer = window.setTimeout(() => this.activate(el), 220);

    window.addEventListener('pointermove', this.onMove, { passive: false });
    window.addEventListener('pointerup', this.onUp);
    window.addEventListener('pointercancel', this.onCancel);
  }

  activate(el: HTMLElement) {
    const drag = this.drag;
    if (!drag || drag.active) return;
    drag.active = true;
    this.measureDock();
    this.grabbed = null;
    this.sheet = null;

    const ghost = el.cloneNode(true) as HTMLElement;
    const rect = el.getBoundingClientRect();
    ghost.className += ' TierGhost';
    ghost.removeAttribute('data-uid');
    ghost.setAttribute('aria-hidden', 'true');
    Object.assign(ghost.style, { width: rect.width + 'px', height: rect.height + 'px' });
    document.body.appendChild(ghost);
    drag.ghost = ghost;
    this.place();

    if (drag.touch) navigator.vibrate?.(12);
    const item = this.items.find((other) => other.uid === drag.uid);
    this.announce(tt('editor.picked_up', { label: item?.label || tt('editor.picture') }));
    this.loop();
    m.redraw();
  }

  onMove = (e: PointerEvent) => {
    const drag = this.drag;
    if (!drag || e.pointerId !== drag.pointerId) return;
    drag.x = e.clientX;
    drag.y = e.clientY;
    const moved = Math.hypot(drag.x - drag.sx, drag.y - drag.sy);

    if (!drag.active) {
      if (drag.touch) {
        // Moved before the hold finished: the page is scrolling, not a drag.
        if (moved > 8) this.endDrag(false);
        return;
      }
      if (moved < 6) return;
      this.activate(document.querySelector(`[data-uid="${drag.uid}"]`) as HTMLElement);
    }

    e.preventDefault();
    this.place();
  };

  /** Once a touch drag is on, the page must not scroll under it. */
  onTouchMove = (e: TouchEvent) => {
    if (this.drag?.active && e.cancelable) e.preventDefault();
  };

  onUp = (e: PointerEvent) => {
    if (this.drag && e.pointerId === this.drag.pointerId) this.endDrag(true);
  };

  onCancel = (e: PointerEvent) => {
    if (this.drag && e.pointerId === this.drag.pointerId) this.endDrag(false);
  };

  /** Move the ghost to the pointer and work out where it would land. */
  place() {
    const drag = this.drag;
    if (!drag?.active || !drag.ghost) return;
    drag.ghost.style.transform = `translate(${drag.x - drag.dx}px, ${drag.y - drag.dy}px) rotate(-3deg) scale(1.08)`;

    const target = this.hit(drag.x, drag.y);
    const before = drag.target;
    if (target?.tier !== before?.tier || target?.before !== before?.before || !target !== !before) {
      drag.target = target;
      m.redraw();
    }
  }

  hit(x: number, y: number): Target | null {
    const el = document.elementFromPoint(x, y);
    const zone = el?.closest<HTMLElement>('[data-zone]');
    if (!zone) return null;
    const tier = zone.dataset.zone === 'pool' ? null : Number(zone.dataset.zone);
    const uidDragged = this.drag!.uid;

    for (const tile of Array.from(zone.querySelectorAll<HTMLElement>('[data-uid]'))) {
      if (tile.dataset.uid === uidDragged) continue;
      const rect = tile.getBoundingClientRect();
      if (y < rect.top || (y <= rect.bottom && x < rect.left + rect.width / 2)) return { tier, before: tile.dataset.uid! };
    }
    return { tier, before: null };
  }

  /**
   * Scroll the page when the pointer is near the top or the bottom of the
   * board while dragging. On a phone the bottom is the top of the docked
   * pool, not the screen, and the top is below the fixed header bars.
   */
  loop() {
    cancelAnimationFrame(this.frame);
    const step = () => {
      const drag = this.drag;
      if (!drag?.active) return;
      const edge = 72;
      const pool = document.querySelector('.TierEditor-pool');
      const floor = this.docked && pool ? pool.getBoundingClientRect().top : window.innerHeight - this.dock;
      const roof = this.ceiling;
      // An edge scrolls only once the pointer has been outside it: picking an
      // item up near the bottom and moving it up must not scroll down.
      if (drag.y > roof + edge) drag.armTop = true;
      if (drag.y < floor - edge) drag.armBottom = true;
      const top = drag.armTop ? Math.max(roof + edge - drag.y, 0) : 0;
      const bottom = drag.armBottom && drag.y < floor ? Math.max(drag.y - (floor - edge), 0) : 0;
      if (top || bottom) {
        window.scrollBy(0, (bottom - top) * 0.35);
        this.place();
      }
      this.frame = requestAnimationFrame(step);
    };
    this.frame = requestAnimationFrame(step);
  }

  endDrag(commit: boolean) {
    const drag = this.drag;
    if (!drag) return;
    window.clearTimeout(drag.timer);
    cancelAnimationFrame(this.frame);
    window.removeEventListener('pointermove', this.onMove);
    window.removeEventListener('pointerup', this.onUp);
    window.removeEventListener('pointercancel', this.onCancel);
    drag.ghost?.remove();
    this.drag = null;

    if (!drag.active) return;
    // The click that follows a drag is not a tap.
    this.suppressClick = true;
    window.setTimeout(() => (this.suppressClick = false), 0);

    const item = this.items.find((other) => other.uid === drag.uid);
    if (commit && drag.target && item) {
      this.move(drag.uid, drag.target);
      this.announce(
        tt('editor.dropped', {
          label: item.label || tt('editor.picture'),
          where: drag.target.tier === null ? tt('editor.pool') : this.rows[drag.target.tier].label,
        })
      );
    }
    m.redraw();
  }

  tap(item: Item) {
    if (this.suppressClick || this.grabbed) return;
    this.openSheet(item);
  }

  openSheet(item: Item) {
    // The menu takes the focus; the tile gets it back when the menu closes.
    this.focusUid = null;
    this.sheet = item.uid;
  }

  // ─── Keyboard ──────────────────────────────────────────────────────────

  key(e: KeyboardEvent, item: Item) {
    const grabbed = this.grabbed?.uid === item.uid;

    if (!grabbed) {
      if (e.key === ' ' || e.key === 'Enter') {
        e.preventDefault();
        const row = this.items.filter((other) => other.tier === item.tier);
        this.grabbed = { uid: item.uid, tier: item.tier, index: row.indexOf(item) };
        this.focusUid = item.uid;
        this.announce(tt('editor.picked_up', { label: item.label || tt('editor.picture') }));
      } else if (e.key === 'm' || e.key === 'M') {
        e.preventDefault();
        this.openSheet(item);
      } else if ((e.key === 'Delete' || e.key === 'Backspace') && !this.locked) {
        e.preventDefault();
        this.remove(item);
      }
      return;
    }

    const rows = this.rows.length;
    // The pool counts as the row after the last one.
    const position = item.tier === null ? rows : item.tier;
    const row = this.items.filter((other) => other.tier === item.tier);
    const index = row.indexOf(item);

    if (e.key === 'ArrowUp' || e.key === 'ArrowDown') {
      e.preventDefault();
      const next = Math.max(0, Math.min(rows, position + (e.key === 'ArrowUp' ? -1 : 1)));
      if (next === position) return;
      const tier = next === rows ? null : next;
      const target = this.items.filter((other) => other.tier === tier);
      this.move(item.uid, { tier, before: target[Math.min(index, target.length)]?.uid || null });
      this.announceWhere(item);
    } else if (e.key === 'ArrowLeft' || e.key === 'ArrowRight') {
      e.preventDefault();
      const delta = e.key === 'ArrowLeft' ? -1 : 1;
      const to = index + delta;
      if (to < 0 || to >= row.length) return;
      const others = row.filter((other) => other !== item);
      this.move(item.uid, { tier: item.tier, before: others[to]?.uid || null });
      this.announceWhere(item);
    } else if (e.key === ' ' || e.key === 'Enter') {
      e.preventDefault();
      this.drop();
    } else if (e.key === 'Escape') {
      e.preventDefault();
      const origin = this.grabbed!;
      const target = this.items.filter((other) => other.tier === origin.tier && other !== item);
      this.move(item.uid, { tier: origin.tier, before: target[origin.index]?.uid || null });
      this.grabbed = null;
      this.announce(tt('editor.cancelled', { label: item.label || tt('editor.picture') }));
    }
  }

  drop() {
    const item = this.items.find((other) => other.uid === this.grabbed?.uid);
    this.grabbed = null;
    if (item) this.announce(tt('editor.dropped', { label: item.label || tt('editor.picture'), where: this.where(item) }));
  }

  where(item: Item) {
    return item.tier === null ? tt('editor.pool') : this.rows[item.tier].label;
  }

  announceWhere(item: Item) {
    // Redraw now, so the rebuilt tile has the focus before the next key arrives.
    m.redraw.sync();
    const row = this.items.filter((other) => other.tier === item.tier);
    this.announce(tt('editor.now_in', { where: this.where(item), position: row.indexOf(item) + 1, total: row.length }));
  }

  announce(text: string) {
    // A new string each time, so the same words are read again.
    this.live = this.live === text ? text + ' ' : text;
  }

  // ─── Rows and items ────────────────────────────────────────────────────

  addRow() {
    const used = new Set(this.rows.map((row) => row.color));
    this.rows.push({ uid: uid(), label: String(this.rows.length + 1), color: PALETTE.find((color) => !used.has(color)) || '#c0c0c0' });
    this.saveDraft();
  }

  moveRow(index: number, delta: number) {
    const to = index + delta;
    const [row] = this.rows.splice(index, 1);
    this.rows.splice(to, 0, row);
    // Items follow their row.
    for (const item of this.items) {
      if (item.tier === index) item.tier = to;
      else if (item.tier === to) item.tier = index;
    }
    this.saveDraft();
  }

  deleteRow(index: number) {
    this.rows.splice(index, 1);
    for (const item of this.items) {
      if (item.tier === index) item.tier = null;
      else if (item.tier !== null && item.tier > index) item.tier--;
    }
    this.saveDraft();
  }

  addText(text: string) {
    const labels = text
      .split(/\r?\n/)
      .map((line) => line.trim().slice(0, 80))
      .filter(Boolean);
    for (const label of labels) {
      if (this.items.length >= 120) break;
      this.items.push({ uid: uid(), label, image: null, tier: null });
    }
    this.newLabel = '';
    this.saveDraft();
  }

  /** Pasting several lines adds one item per line; pasting a picture uploads it. */
  paste(e: ClipboardEvent) {
    const files = Array.from(e.clipboardData?.files || []).filter((file) => file.type.startsWith('image/'));
    if (files.length) {
      e.preventDefault();
      this.upload(files);
      return;
    }
    const text = e.clipboardData?.getData('text') || '';
    if (text.includes('\n')) {
      e.preventDefault();
      this.addText(text);
    }
  }

  remove(item: Item) {
    this.items = this.items.filter((other) => other !== item);
    this.announce(tt('editor.removed', { label: item.label || tt('editor.picture') }));
    this.saveDraft();
  }

  upload(files: File[]) {
    const room = 120 - this.items.length;
    const queue = files.filter((file) => /^image\/(jpeg|png|webp)$/.test(file.type)).slice(0, Math.max(0, room));
    if (queue.length < files.length) app.alerts.show({ type: 'error' }, t('editor.some_skipped'));

    const pending = queue.map((file) => {
      const item: Item = {
        uid: uid(),
        label: file.name
          .replace(/\.[a-z0-9]+$/i, '')
          .replace(/[-_]+/g, ' ')
          .trim()
          .slice(0, 80),
        image: null,
        tier: null,
        uploading: true,
      };
      this.items.push(item);
      return { file, item };
    });

    // Three at a time: each one is a decode and an encode on the server.
    const next = (): Promise<void> => {
      const job = pending.shift();
      if (!job) return Promise.resolve();
      return send(job.file)
        .then((url) => {
          job.item.image = url;
          job.item.uploading = false;
          job.item.uploadedAt = Date.now();
        })
        .catch(() => {
          this.items = this.items.filter((other) => other !== job.item);
          app.alerts.show({ type: 'error' }, t('editor.upload_failed', { name: job.file.name }));
        })
        .finally(() => {
          this.saveDraft();
          m.redraw();
        })
        .then(next);
    };
    Promise.all([next(), next(), next()]);
  }

  // ─── Saving ────────────────────────────────────────────────────────────

  startOver() {
    this.discardDraft();
    this.restored = false;
    if (this.source) this.from(this.source);
    else {
      this.title = '';
      this.description = '';
      this.rows = DEFAULT_TIERS.map((tier) => ({ uid: uid(), ...tier }));
      this.items = [];
      this.isTemplate = false;
    }
  }

  save() {
    this.error = '';
    if (!this.title.trim()) this.error = tt('errors.bad_title');
    else if (!this.items.length) this.error = tt('errors.no_items');
    else if (this.items.some((item) => item.uploading)) this.error = tt('editor.still_uploading');
    else if (this.rows.some((row) => !row.label.trim())) this.error = tt('errors.bad_tiers');
    if (this.error) return;

    this.saving = true;
    const body: Record<string, any> = {
      title: this.title,
      description: this.description,
      tiers: this.rows.map(({ label, color }) => ({ label: label.trim(), color })),
      items: this.items.map(({ key, label, image, tier }) => ({ key, label, image, tier })),
      isTemplate: this.isTemplate,
      discussion: this.discussion,
    };
    if (this.mode === 'remix') body.parentId = Number(this.id);

    app
      .request<{ data: Full; discussionError: string | null }>({
        method: this.mode === 'edit' ? 'PATCH' : 'POST',
        url: this.mode === 'edit' ? api('/' + this.id) : api(),
        body,
        ...quiet,
      })
      .then((res) => {
        this.discardDraft();
        if (res.discussionError) {
          app.alerts.show({ type: 'warning' }, errorText({ response: { errors: [{ code: res.discussionError }] } }));
        }
        m.route.set(listUrl(res.data.id));
      })
      .catch((error) => {
        this.saving = false;
        this.error = errorText(error);
        m.redraw();
      });
  }
}

/** Upload one picture: to FoF Upload when the member may use it there, otherwise to this extension. */
function send(file: File): Promise<string> {
  const body = new FormData();
  if (app.forum.attribute('fof-upload.canUpload')) {
    body.append('files[]', file);
    return app
      .request<any>({ method: 'POST', url: app.forum.attribute('apiUrl') + '/fof/upload', serialize: (raw: any) => raw, body, ...quiet })
      .then((res) => {
        const url = res?.data?.[0]?.attributes?.url;
        if (!url) throw new Error('No URL');
        return url;
      });
  }
  body.append('image', file);
  return app.request<{ url: string }>({ method: 'POST', url: api('/images'), serialize: (raw: any) => raw, body, ...quiet }).then((res) => res.url);
}
