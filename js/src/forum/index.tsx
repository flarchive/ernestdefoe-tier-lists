import app from 'flarum/forum/app';
import { extend } from 'flarum/common/extend';
import type ItemList from 'flarum/common/utils/ItemList';
import LinkButton from 'flarum/common/components/LinkButton';
import Notification from 'flarum/forum/components/Notification';
import Model from 'flarum/common/Model';
import type Mithril from 'mithril';
import { listUrl, loadCore, t } from './api';
import { releaseLinks, upgradeLinks } from './embed';

/*
 * forum.js is loaded on every page, so it only wires things up: the routes,
 * the sidebar link, notifications, the profile link and boards in posts. The
 * pages and the drag-and-drop editor are chunks fetched when opened.
 */

class TierListModel extends Model {}

function notification(icon: string, key: string) {
  return class extends Notification {
    icon() {
      return icon;
    }

    href() {
      const subject: any = this.attrs.notification.subject();
      return subject ? listUrl(subject.id()) : app.route('tier-lists');
    }

    content(): Mithril.Children {
      // 🚨 Never a placeholder named `user`: the translator calls displayName() on it.
      return t(`notifications.${key}`, { username: this.attrs.notification.fromUser()?.displayName() || '' });
    }

    excerpt(): Mithril.Children {
      const subject: any = this.attrs.notification.subject();
      return subject?.attribute('title') || null;
    }
  };
}

const page = (load: () => Promise<any>) => () => Promise.all([load(), loadCore()]).then(([module]) => module);

app.initializers.add('ernestdefoe/tier-lists', () => {
  app.store.models['tier-lists'] = TierListModel;

  app.routes['tier-lists'] = { path: '/tierlists', component: page(() => import('./pages/IndexPage')) };
  app.routes['tier-lists.new'] = { path: '/tierlists/new', component: page(() => import('./pages/EditorPage')) };
  app.routes['tier-lists.show'] = { path: '/tierlists/:id', component: page(() => import('./pages/ShowPage')) };
  app.routes['tier-lists.edit'] = { path: '/tierlists/:id/edit', component: page(() => import('./pages/EditorPage')) };
  app.routes['tier-lists.remix'] = { path: '/tierlists/:id/remix', component: page(() => import('./pages/EditorPage')) };

  app.notificationComponents.tierListRemixed = notification('fas fa-shuffle', 'remixed');
  app.notificationComponents.tierListLiked = notification('fas fa-heart', 'liked');

  extend('flarum/forum/components/NotificationGrid', 'notificationTypes', function (items: ItemList<any>) {
    if (!app.forum.attribute('tierListsCanCreate')) return;
    items.add('tierListRemixed', { name: 'tierListRemixed', icon: 'fas fa-shuffle', label: t('notifications.remixed_preference') });
    items.add('tierListLiked', { name: 'tierListLiked', icon: 'fas fa-heart', label: t('notifications.liked_preference') });
  });

  // String form: these components live in lazy chunks, and extending an imported copy patches one core never renders.
  extend('flarum/forum/components/IndexSidebar', 'navItems', function (items: ItemList<any>) {
    if (!app.forum.attribute('tierListsCanView') || !app.forum.attribute('tierListsNav')) return;
    items.add('tier-lists', LinkButton.component({ href: app.route('tier-lists'), icon: 'fas fa-layer-group' }, t('nav')), 79);
  });

  extend('flarum/forum/components/UserPage', 'navItems', function (this: any, items: ItemList<any>) {
    const user = this.user;
    if (!user || !app.forum.attribute('tierListsCanView')) return;
    items.add(
      'tier-lists',
      LinkButton.component(
        { href: app.route('tier-lists') + '?author=' + encodeURIComponent(user.slug()), icon: 'fas fa-layer-group' },
        t('profile_link')
      ),
      55
    );
  });

  extend('flarum/forum/components/CommentPost', 'oncreate', function (this: any) {
    upgradeLinks(this.element);
  });
  extend('flarum/forum/components/CommentPost', 'onupdate', function (this: any) {
    upgradeLinks(this.element);
  });
  extend('flarum/forum/components/CommentPost', 'onremove', function (this: any) {
    releaseLinks(this.element);
  });
});
