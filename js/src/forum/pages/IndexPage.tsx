import app from 'flarum/forum/app';
import Page from 'flarum/common/components/Page';
import Button from 'flarum/common/components/Button';
import Link from 'flarum/common/components/Link';
import LoadingIndicator from 'flarum/common/components/LoadingIndicator';
import Icon from 'flarum/common/components/Icon';
import Select from 'flarum/common/components/Select';
import type Mithril from 'mithril';
import { Byline } from '../Board';
import { api, core, ink, listUrl, t, tt, type Card } from '../api';

/**
 * /tierlists: every original list as a card with a peek at its top rows,
 * sorted new / popular / most remixed, with tabs for templates, remixes and
 * your own, and a title search. ?author=<slug> shows one member's lists,
 * ?family=<id> every remix of one list.
 */

declare const m: Mithril.Static;

type Filter = 'all' | 'templates' | 'remixes' | 'mine';

export default class IndexPage extends Page {
  cards: Card[] = [];
  loading = true;
  more = false;
  sort = 'new';
  filter: Filter = 'all';
  q = '';
  author = '';
  family = '';
  private ticket = 0;
  private debounce = 0;

  oninit(vnode: Mithril.Vnode) {
    super.oninit(vnode);
    this.bodyClass = 'App--tierLists';
    this.author = m.route.param('author') || '';
    this.family = m.route.param('family') || '';
    this.sort = ['new', 'popular', 'remixed'].includes(m.route.param('sort')) ? m.route.param('sort') : 'new';
    const filter = m.route.param('filter');
    this.filter = ['templates', 'remixes', 'mine'].includes(filter) ? filter : 'all';
    app.history.push('tier-lists', tt('index.title'));
    this.load(0);
  }

  oncreate(vnode: Mithril.VnodeDOM) {
    super.oncreate(vnode);
    app.setTitle(tt('index.title'));
    app.setTitleCount(0);
  }

  load(offset: number) {
    const ticket = ++this.ticket;
    this.loading = true;
    const params: Record<string, string | number> = { sort: this.sort, offset };
    if (this.author) params.author = this.author;
    else if (this.family) params.family = this.family;
    else params.filter = this.filter;
    if (this.q.trim()) params.q = this.q.trim();

    app
      .request<{ data: Card[]; hasMore: boolean }>({ method: 'GET', url: api(), params })
      .then((res) => {
        // A slower, older search must not overwrite a newer one.
        if (ticket !== this.ticket) return;
        this.cards = offset ? this.cards.concat(res.data) : res.data;
        this.more = res.hasMore;
      })
      .catch(() => {})
      .finally(() => {
        if (ticket === this.ticket) this.loading = false;
        m.redraw();
      });
  }

  /** Keep the address in step, so a refresh or a shared link shows the same view. */
  private go(change: Partial<{ sort: string; filter: Filter }>) {
    Object.assign(this, change);
    const params: Record<string, string> = {};
    if (this.sort !== 'new') params.sort = this.sort;
    if (this.filter !== 'all') params.filter = this.filter;
    const query = new URLSearchParams(params).toString();
    // Not m.route.set: that would rebuild the page; the address only has to follow.
    window.history.replaceState(window.history.state, '', app.route('tier-lists') + (query ? '?' + query : ''));
    this.load(0);
  }

  view() {
    const { PageStructure, IndexSidebar } = core;
    const scoped = this.author || this.family;

    return (
      <PageStructure className="TierListsPage" sidebar={() => <IndexSidebar />}>
        <div className="TierListsPage-hero">
          <div className="TierListsPage-heroText">
            <h2 className="TierListsPage-title">
              <Icon name="fas fa-layer-group" /> {t('index.title')}
            </h2>
            <p className="TierListsPage-lead">{scoped ? (this.author ? t('index.by_author') : t('index.family')) : t('index.lead')}</p>
          </div>
          {app.forum.attribute('tierListsCanCreate') ? (
            <Link className="Button Button--primary TierListsPage-new" href={app.route('tier-lists.new')}>
              <Icon name="fas fa-plus" className="Button-icon" />
              <span className="Button-label">{t('index.new')}</span>
            </Link>
          ) : null}
        </div>

        {scoped ? (
          <p>
            <Link href={app.route('tier-lists')}>
              <Icon name="fas fa-arrow-left" /> {t('index.all')}
            </Link>
          </p>
        ) : (
          <div className="TierListsPage-toolbar">
            <div className="TierListsPage-tabs" role="tablist" aria-label={tt('index.filter_label')}>
              {(['all', 'templates', 'remixes', 'mine'] as Filter[])
                .filter((filter) => filter !== 'mine' || app.session.user)
                .map((filter) => (
                  <button
                    type="button"
                    role="tab"
                    aria-selected={this.filter === filter ? 'true' : 'false'}
                    className={'TierListsPage-tab' + (this.filter === filter ? ' is-active' : '')}
                    onclick={() => this.go({ filter })}
                  >
                    {t(`index.filters.${filter}`)}
                  </button>
                ))}
            </div>
            <div className="TierListsPage-controls">
              <input
                className="FormControl TierListsPage-search"
                type="search"
                placeholder={tt('index.search')}
                aria-label={tt('index.search')}
                value={this.q}
                oninput={(e: InputEvent) => {
                  this.q = (e.target as HTMLInputElement).value;
                  window.clearTimeout(this.debounce);
                  this.debounce = window.setTimeout(() => this.load(0), 300);
                }}
              />
              <Select
                className="TierListsPage-sort"
                value={this.sort}
                aria-label={tt('index.sort_label')}
                options={{ new: tt('index.sorts.new'), popular: tt('index.sorts.popular'), remixed: tt('index.sorts.remixed') }}
                onchange={(sort: string) => this.go({ sort })}
              />
            </div>
          </div>
        )}

        {this.cards.length ? (
          <ul className="TierListsPage-grid">
            {this.cards.map((card) => (
              <li key={card.id}>
                <TierCard card={card} />
              </li>
            ))}
          </ul>
        ) : this.loading ? null : (
          <div className="TierListsPage-empty">
            <Icon name="fas fa-layer-group" />
            <p>{t(this.q ? 'index.no_results' : this.filter === 'mine' ? 'index.empty_mine' : 'index.empty')}</p>
          </div>
        )}

        {this.loading ? (
          <LoadingIndicator />
        ) : this.more ? (
          <div className="TierListsPage-more">
            <Button className="Button" onclick={() => this.load(this.cards.length)}>
              {t('index.load_more')}
            </Button>
          </div>
        ) : null}
      </PageStructure>
    );
  }
}

/** A list at a glance: its top three rows, with up to six items each. */
function TierCard() {
  return {
    view: ({ attrs }: { attrs: { card: Card } }) => {
      const card = attrs.card;
      return (
        <article className={'TierCard' + (card.isTemplate ? ' TierCard--template' : '')}>
          <Link className="TierCard-peek" href={listUrl(card.id)} aria-label={card.title} tabindex="-1">
            {card.tiers.slice(0, 3).map((tier, index) => (
              <div className="TierCard-row">
                <span className="TierCard-label" style={{ background: tier.color, color: ink(tier.color) }}>
                  {tier.label}
                </span>
                <span className="TierCard-items">
                  {(card.preview[index] || []).map((item) =>
                    item.image ? <img src={item.image} alt="" loading="lazy" decoding="async" /> : <span className="TierCard-text">{item.label}</span>
                  )}
                </span>
              </div>
            ))}
          </Link>
          <div className="TierCard-body">
            {card.isTemplate ? (
              <span className="TierCard-badge">
                <Icon name="fas fa-clipboard-list" /> {t('card.template')}
              </span>
            ) : null}
            <h3 className="TierCard-title">
              <Link href={listUrl(card.id)}>{card.title}</Link>
            </h3>
            {card.parent ? <p className="TierCard-parent">{t('card.remix_of', { title: card.parent.title })}</p> : null}
            <div className="TierCard-meta">
              <Byline user={card.user} fallback={tt('deleted_member')} />
              <span className="TierCard-stats">
                <span title={tt('card.likes', { count: card.likes })}>
                  <Icon name={card.liked ? 'fas fa-heart' : 'far fa-heart'} /> {card.likes}
                </span>
                <span title={tt('card.remixes', { count: card.remixes })}>
                  <Icon name="fas fa-shuffle" /> {card.remixes}
                </span>
                {card.discussion ? (
                  <span title={tt('card.comments', { count: card.discussion.comments })}>
                    <Icon name="far fa-comment" /> {card.discussion.comments}
                  </span>
                ) : null}
              </span>
            </div>
          </div>
        </article>
      );
    },
  };
}
