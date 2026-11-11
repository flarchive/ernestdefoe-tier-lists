<?php

namespace ErnestDefoe\TierLists\Http;

use ErnestDefoe\TierLists\Access;
use ErnestDefoe\TierLists\TierList;
use Flarum\Frontend\Document;
use Flarum\Http\RequestUtil;
use Flarum\Http\UrlGenerator;
use Flarum\Locale\TranslatorInterface;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use Psr\Http\Message\ServerRequestInterface;

/**
 * One list as the server sends it: its rows as plain text, and link-preview
 * tags (a chat app's crawler runs no JavaScript) with the top item's picture.
 */
class ShowContent
{
    public function __construct(private readonly UrlGenerator $url, private readonly TranslatorInterface $translator)
    {
    }

    public function __invoke(Document $document, ServerRequestInterface $request): Document
    {
        $id = (int) Arr::get($request->getQueryParams(), 'id');
        $list = Access::view(RequestUtil::getActor($request)) ? TierList::query()->with(['user', 'items'])->find($id) : null;
        if (! $list) {
            return $document;
        }

        $document->title = $list->title;
        $document->canonicalUrl = $this->url->to('forum')->route('tier-lists.show', ['id' => $list->id]);

        $rows = [];
        $html = '<div class="container"><h1>'.e($list->title).'</h1>';
        if ($list->description) {
            $html .= '<p>'.e($list->description).'</p>';
        }
        $html .= '<dl>';
        foreach ($list->tierRows() as $index => $tier) {
            $labels = $list->items->where('tier', $index)->pluck('label')->filter()->all();
            $rows[] = $tier['label'].': '.($labels ? implode(', ', $labels) : '–');
            $html .= '<dt>'.e($tier['label']).'</dt><dd>'.e(implode(', ', $labels)).'</dd>';
        }
        $document->content = $html.'</dl></div>';

        $description = $list->description ?: implode(' · ', array_slice($rows, 0, 3));
        $description = Str::limit(preg_replace('/\s+/u', ' ', $description), 200);
        $document->meta['description'] = $description;

        $by = $list->user ? (string) $this->translator->trans('ernestdefoe-tier-lists.forum.meta.by', ['{username}' => $list->user->display_name]) : '';
        $image = $list->items->filter(fn ($item) => $item->image && $item->tier !== null)->sortBy(fn ($item) => [$item->tier, $item->position])->first()?->image;

        foreach (array_filter([
            'og:type' => 'article',
            'og:title' => $list->title.($by ? " · $by" : ''),
            'og:description' => $description,
            'og:image' => $image,
            'twitter:card' => 'summary',
        ]) as $property => $value) {
            $document->head[] = '<meta property="'.e($property).'" content="'.e($value).'">';
        }

        return $document;
    }
}
