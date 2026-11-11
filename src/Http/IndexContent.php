<?php

namespace ErnestDefoe\TierLists\Http;

use ErnestDefoe\TierLists\Access;
use ErnestDefoe\TierLists\TierList;
use Flarum\Frontend\Document;
use Flarum\Http\RequestUtil;
use Flarum\Http\UrlGenerator;
use Flarum\Locale\TranslatorInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * /tierlists as the server sends it: a title and the newest lists as plain
 * links, for search engines and readers without JavaScript. The browser
 * replaces it with the real page.
 */
class IndexContent
{
    public function __construct(private readonly UrlGenerator $url, private readonly TranslatorInterface $translator)
    {
    }

    public function __invoke(Document $document, ServerRequestInterface $request): Document
    {
        $title = (string) $this->translator->trans('ernestdefoe-tier-lists.forum.index.title');
        $document->title = $title;
        $document->canonicalUrl = $this->url->to('forum')->route('tier-lists');
        $document->meta['description'] = (string) $this->translator->trans('ernestdefoe-tier-lists.forum.index.meta');

        if (! Access::view(RequestUtil::getActor($request))) {
            return $document;
        }

        $html = '<div class="container"><h1>'.e($title).'</h1><ul>';
        foreach (TierList::query()->whereNull('root_id')->orderByDesc('id')->limit(50)->get(['id', 'title']) as $list) {
            $html .= '<li><a href="'.e($this->url->to('forum')->route('tier-lists.show', ['id' => $list->id])).'">'.e($list->title).'</a></li>';
        }
        $document->content = $html.'</ul></div>';

        return $document;
    }
}
