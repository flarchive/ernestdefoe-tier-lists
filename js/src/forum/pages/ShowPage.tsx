import app from 'flarum/forum/app';
import Page from 'flarum/common/components/Page';
import Button from 'flarum/common/components/Button';
import Link from 'flarum/common/components/Link';
import LoadingIndicator from 'flarum/common/components/LoadingIndicator';
import Icon from 'flarum/common/components/Icon';
import Avatar from 'flarum/common/components/Avatar';
import humanTime from 'flarum/common/helpers/humanTime';
import type Mithril from 'mithril';
import Board, { avatarUser, Byline, Tile } from '../Board';
import { api, core, errorText, quiet, ink, listUrl, rowFor, score, t, tt, type Entry, type Full, type Tier } from '../api';

/**
 * /tierlists/:id — the list, the crowd's version of it (every item at its
 * average place over the remix family), and how you (or this list) differ
 * from the crowd. Like, remix, share, edit, and the way to its discussion.
 */

declare const m: Mithril.Static;

type View = 'list' | 'crowd' | 'compare';

export default class ShowPage extends Page {
  list: Full | null = null;
  missing = false;
  view_: View = 'list';
  busy = false;

  oninit(vnode: Mithril.Vnode) {
    super.oninit(vnode);
    this.bodyClass = 'App--tierLists';
    const id = m.route.param('id');
    app.history.push('tier-list', tt('index.title'));
    // The phone header's section menu names the page it belongs to.
    app.current.set('titleControlLabel', tt('index.title'));

    app
      .request<{ data: Full }>({ method: 'GET', url: api('/' + id) })
      .then((res) => {
        this.list = res.data;
        const wanted = m.route.param('view');
        if (wanted === 'crowd' && this.hasCrowd()) this.view_ = 'crowd';
        if (wanted === 'compare' && this.hasCompare()) this.view_ = 'compare';
        app.setTitle(res.data.title);
      })
      .catch(() => (this.missing = true))
      .finally(() => m.redraw());
  }

  oncreate(vnode: Mithril.VnodeDOM) {
    super.oncreate(vnode);
    app.setTitleCount(0);
  }

  hasCrowd() {
    return !!this.list && this.list.familySize > 1 && Object.keys(this.list.crowd).length > 0;
  }

  hasCompare() {
    return this.hasCrowd();
  }

  crowdRow(item: Entry): number | null {
    const entry = item.key ? this.list!.crowd[item.key] : undefined;
    return entry ? rowFor(entry[0], this.list!.tiers.length) : null;
  }

  view() {
    const { PageStructure, IndexSidebar } = core;
    const list = this.list;

    return (
      <PageStructure className="TierListPage" sidebar={() => <IndexSidebar />}>
        <p className="TierListPage-back">
          <Link href={app.route('tier-lists')}>
            <Icon name="fas fa-arrow-left" /> {t('index.title')}
          </Link>
        </p>
        {this.missing ? <p className="TierListsPage-empty">{t('show.missing')}</p> : list ? this.content(list) : <LoadingIndicator />}
      </PageStructure>
    );
  }

  content(list: Full) {
    return [
      <header className="TierListPage-head">
        <div className="TierListPage-headText">
          {list.isTemplate ? (
            <span className="TierCard-badge">
              <Icon name="fas fa-clipboard-list" /> {t('card.template')}
            </span>
          ) : null}
          <h2 className="TierListPage-title">{list.title}</h2>
          <div className="TierListPage-by">
            <Byline user={list.user} fallback={tt('deleted_member')} />
            {list.createdAt ? <span className="TierListPage-when">{humanTime(list.createdAt)}</span> : null}
          </div>
          {list.root ? (
            <p className="TierListPage-origin">
              <Icon name="fas fa-shuffle" />{' '}
              {list.root.id
                ? t('show.remix_of', {
                    link: <Link href={listUrl(list.root.id)}>{list.root.title}</Link>,
                    name: list.root.user?.displayName || tt('deleted_member'),
                  })
                : t('show.original_gone')}
            </p>
          ) : null}
          {list.description ? <p className="TierListPage-description">{list.description}</p> : null}
        </div>
        <div className="TierListPage-actions">{this.actions(list)}</div>
      </header>,

      this.hasCrowd() ? this.tabs(list) : null,

      <div className="TierListPage-board" role={this.hasCrowd() ? 'tabpanel' : undefined} id="TierListPage-panel">
        {this.view_ === 'compare' ? this.compare(list) : this.board(list)}
      </div>,

      this.footer(list),
    ];
  }

  tabs(list: Full) {
    const tab = (view: View, label: Mithril.Children, icon: string) => (
      <button
        type="button"
        role="tab"
        aria-controls="TierListPage-panel"
        aria-selected={this.view_ === view ? 'true' : 'false'}
        className={'TierListsPage-tab' + (this.view_ === view ? ' is-active' : '')}
        onclick={() => {
          this.view_ = view;
          const url = new URL(window.location.href);
          if (view === 'list') url.searchParams.delete('view');
          else url.searchParams.set('view', view);
          window.history.replaceState(window.history.state, '', url.pathname + url.search);
        }}
      >
        <Icon name={icon} /> {label}
      </button>
    );

    return (
      <div className="TierListsPage-tabs TierListPage-tabs" role="tablist" aria-label={tt('show.views_label')}>
        {tab('list', list.root ? t('show.view_this') : t('show.view_list'), 'fas fa-list')}
        {tab('crowd', t('show.view_crowd', { count: list.familySize }), 'fas fa-users')}
        {tab('compare', list.mine ? t('show.view_you') : t('show.view_this_vs'), 'fas fa-code-compare')}
      </div>
    );
  }

  board(list: Full) {
    if (this.view_ === 'crowd') {
      return [
        <p className="TierListPage-note">{t('show.crowd_note', { count: list.familySize })}</p>,
        <Board tiers={list.tiers} items={list.allItems} rowOf={(item: Entry) => this.crowdRow(item)} pool={t('show.unranked')} />,
      ];
    }

    // The crowd's place beside each item it disagrees on.
    const badge = this.hasCrowd()
      ? (item: Entry) => {
          const row = this.crowdRow(item);
          if (row === null || item.tier === null || row === item.tier) return null;
          const tier = list.tiers[row];
          return (
            <span
              className="TierTile-crowd"
              style={{ background: tier.color, color: ink(tier.color) }}
              title={tt('show.crowd_says', { tier: tier.label })}
            >
              <Icon name={row < item.tier ? 'fas fa-arrow-up' : 'fas fa-arrow-down'} /> {tier.label}
            </span>
          );
        }
      : undefined;

    return <Board tiers={list.tiers} items={list.allItems} badge={badge} pool={t('show.unranked')} />;
  }

  /** Each item: where "you" put it, where the crowd puts it, biggest differences first. */
  compare(list: Full) {
    const tiers = list.tiers;
    const mine = list.mine;
    const rows: { item: Entry; yours: number; crowd: number; diff: number }[] = [];

    for (const item of list.allItems) {
      const crowd = this.crowdRow(item);
      let yours: number | null = item.tier;
      if (mine) yours = item.key && mine.items[item.key] !== undefined ? rowFor(score(mine.items[item.key], mine.tiers), tiers.length) : null;
      if (crowd === null || yours === null) continue;
      rows.push({ item, yours, crowd, diff: yours - crowd });
    }
    rows.sort((a, b) => Math.abs(b.diff) - Math.abs(a.diff) || a.crowd - b.crowd);

    if (!rows.length) return <p className="TierListPage-note">{t('show.compare_empty')}</p>;

    const agree = rows.filter((row) => row.diff === 0).length;
    const hottest = rows[0].diff !== 0 ? rows[0] : null;
    const who = mine ? 'you' : 'this';

    return (
      <div className="TierCompare">
        <div className="TierCompare-summary">
          <div className="TierCompare-meter" role="img" aria-label={tt(`show.compare.${who}_agree`, { agree, total: rows.length })}>
            <span style={{ width: `${Math.round((agree / rows.length) * 100)}%` }} />
          </div>
          <p>
            <strong>{t(`show.compare.${who}_agree`, { agree, total: rows.length })}</strong>
            {hottest ? (
              <>
                {' '}
                {t(`show.compare.${who}_hottest`, {
                  item: hottest.item.label || tt('show.compare.untitled'),
                  yours: tiers[hottest.yours].label,
                  crowd: tiers[hottest.crowd].label,
                })}
              </>
            ) : null}
          </p>
          {mine ? (
            <p>
              <Link href={listUrl(mine.id)}>{t('show.compare.open_yours')}</Link>
            </p>
          ) : null}
        </div>
        <ul className="TierCompare-list">
          {rows.map((row) => (
            <li className={'TierCompare-row' + (row.diff === 0 ? ' is-same' : '')} key={row.item.key}>
              {row.item.image ? <Tile item={row.item} /> : <span className="TierCompare-dot" aria-hidden="true" />}
              <span className="TierCompare-name">{row.item.label}</span>
              <span className="TierCompare-chips">
                <Chip tier={tiers[row.yours]} label={tt(`show.compare.${who}`)} />
                <Icon
                  name={row.diff === 0 ? 'fas fa-equals' : row.diff < 0 ? 'fas fa-arrow-trend-up' : 'fas fa-arrow-trend-down'}
                  className="TierCompare-arrow"
                />
                <Chip tier={tiers[row.crowd]} label={tt('show.compare.crowd')} />
              </span>
              <span className="TierCompare-say">
                {row.diff === 0
                  ? t('show.compare.same')
                  : t(row.diff < 0 ? `show.compare.${who}_higher` : `show.compare.${who}_lower`, { count: Math.abs(row.diff) })}
              </span>
            </li>
          ))}
        </ul>
      </div>
    );
  }

  actions(list: Full) {
    const signedIn = !!app.session.user;
    // A ranking of a template is remixed by ranking the template's items too.
    const rank = list.isTemplate || !!list.root?.isTemplate;
    return [
      <Button
        className={'Button TierListPage-like' + (list.liked ? ' is-liked' : '')}
        icon={list.liked ? 'fas fa-heart' : 'far fa-heart'}
        aria-pressed={list.liked ? 'true' : 'false'}
        onclick={() => (signedIn ? this.like(list) : app.modal.show(() => import('flarum/forum/components/LogInModal')))}
      >
        {list.likes} <span className="visually-hidden">{tt('show.likes')}</span>
      </Button>,
      list.can.remix ? (
        <Link className="Button Button--primary" href={listUrl(list.id, 'remix')}>
          <Icon name={rank ? 'fas fa-ranking-star' : 'fas fa-shuffle'} className="Button-icon" />
          <span className="Button-label">{rank ? t('show.rank_it') : t('show.remix')}</span>
        </Link>
      ) : !signedIn ? (
        <Button
          className="Button Button--primary"
          icon="fas fa-shuffle"
          onclick={() => app.modal.show(() => import('flarum/forum/components/LogInModal'))}
        >
          {rank ? t('show.rank_it') : t('show.remix')}
        </Button>
      ) : null,
      <Button className="Button" icon="fas fa-link" onclick={() => this.share(list)}>
        {t('show.share')}
      </Button>,
      list.can.edit ? (
        <Link className="Button" href={listUrl(list.id, 'edit')}>
          <Icon name="fas fa-pen" className="Button-icon" />
          <span className="Button-label">{t('show.edit')}</span>
        </Link>
      ) : null,
      list.can.edit ? (
        <Button className="Button Button--danger" icon="fas fa-trash" disabled={this.busy} onclick={() => this.remove(list)}>
          {t('show.delete')}
        </Button>
      ) : null,
    ];
  }

  footer(list: Full) {
    return (
      <section className="TierListPage-foot">
        {list.discussion ? (
          <Link className="Button TierListPage-discussion" href={app.route('discussion', { id: list.discussion.slug })}>
            <Icon name="far fa-comments" className="Button-icon" />
            <span className="Button-label">
              {list.root
                ? t('show.discussion_original', { count: list.discussion.comments })
                : t('show.discussion', { count: list.discussion.comments })}
            </span>
          </Link>
        ) : list.can.discuss ? (
          <Button className="Button" icon="far fa-comments" disabled={this.busy} onclick={() => this.discuss(list)}>
            {t('show.start_discussion')}
          </Button>
        ) : null}

        {list.remixList.length ? (
          <div className="TierListPage-remixes">
            <h3>{t('show.remixes', { count: list.familySize - 1 })}</h3>
            <ul>
              {list.remixList.map((remix) => (
                <li key={remix.id}>
                  <Link href={listUrl(remix.id)} title={remix.user?.displayName || tt('deleted_member')}>
                    {remix.user ? <Avatar user={avatarUser(remix.user)} className="TierListPage-remixAvatar" /> : <Icon name="fas fa-user" />}
                    <span>{remix.user?.displayName || tt('deleted_member')}</span>
                  </Link>
                </li>
              ))}
            </ul>
            {list.familySize - 1 > list.remixList.length ? (
              <Link href={app.route('tier-lists') + '?family=' + (list.root?.id || list.id)}>{t('show.all_remixes')}</Link>
            ) : null}
          </div>
        ) : null}
      </section>
    );
  }

  like(list: Full) {
    const liked = !list.liked;
    list.liked = liked;
    list.likes += liked ? 1 : -1;
    app
      .request<{ likes: number; liked: boolean }>({ method: liked ? 'POST' : 'DELETE', url: api(`/${list.id}/like`), ...quiet })
      .then((res) => {
        list.likes = res.likes;
        list.liked = res.liked;
      })
      .catch((error) => {
        list.liked = !liked;
        list.likes += liked ? -1 : 1;
        app.alerts.show({ type: 'error' }, errorText(error));
      })
      .finally(() => m.redraw());
  }

  share(list: Full) {
    const url = app.forum.attribute<string>('baseOrigin') + listUrl(list.id);
    const done = () => app.alerts.show({ type: 'success' }, t('show.copied'));
    if (navigator.clipboard?.writeText) navigator.clipboard.writeText(url).then(done, () => window.prompt(tt('show.copy_prompt'), url));
    else window.prompt(tt('show.copy_prompt'), url);
  }

  remove(list: Full) {
    if (!window.confirm(tt('show.delete_confirm'))) return;
    this.busy = true;
    app
      .request({ method: 'DELETE', url: api('/' + list.id), ...quiet })
      .then(() => m.route.set(app.route('tier-lists')))
      .catch((error) => {
        this.busy = false;
        app.alerts.show({ type: 'error' }, errorText(error));
        m.redraw();
      });
  }

  discuss(list: Full) {
    this.busy = true;
    app
      .request<{ data: Full }>({ method: 'POST', url: api(`/${list.id}/discussion`), ...quiet })
      .then((res) => (this.list = res.data))
      .catch((error) => app.alerts.show({ type: 'error' }, errorText(error)))
      .finally(() => {
        this.busy = false;
        m.redraw();
      });
  }
}

function Chip() {
  return {
    view: ({ attrs }: { attrs: { tier: Tier; label: string } }) => (
      <span className="TierChip" style={{ background: attrs.tier.color, color: ink(attrs.tier.color) }} title={attrs.label}>
        <span className="visually-hidden">{attrs.label}: </span>
        {attrs.tier.label}
      </span>
    ),
  };
}
