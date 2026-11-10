import app from 'flarum/admin/app';
import Extend from 'flarum/common/extenders';

const t = (key: string) => app.translator.trans(`ernestdefoe-tier-lists.admin.${key}`, {}, true);
const setting = (key: string) => `ernestdefoe-tier-lists.${key}`;

/** Tags a list's discussion can start in, when flarum/tags is on. */
function tagOptions(): Record<string, string> {
  const options: Record<string, string> = { '': String(t('settings.tag_none')) };
  for (const tag of app.store.all<any>('tags')) options[tag.id()] = tag.name();
  return options;
}

export default [
  new Extend.Admin()
    .setting(() => ({ setting: setting('nav'), type: 'boolean', label: t('settings.nav') }), 100)
    .setting(
      () =>
        'flarum-tags' in ((globalThis as any).flarum?.extensions || {})
          ? { setting: setting('tag'), type: 'select', options: tagOptions(), default: '', label: t('settings.tag'), help: t('settings.tag_help') }
          : null,
      90
    )
    .permission(
      () => ({ icon: 'fas fa-layer-group', label: t('permissions.view'), permission: 'ernestdefoe-tier-lists.view', allowGuest: true }),
      'view',
      90
    )
    .permission(() => ({ icon: 'fas fa-layer-group', label: t('permissions.create'), permission: 'ernestdefoe-tier-lists.create' }), 'start', 90)
    .permission(
      () => ({ icon: 'fas fa-layer-group', label: t('permissions.moderate'), permission: 'ernestdefoe-tier-lists.moderate' }),
      'moderate',
      90
    ),
];
