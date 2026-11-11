<x-mail::plain.notification>
<x-slot:body>
{!! $translator->trans('ernestdefoe-tier-lists.email.remixed.plain.body', [
'{username}' => $blueprint->remixer->display_name,
'{title}' => $blueprint->remix->title,
'{url}' => $url->to('forum')->route('tier-lists.show', ['id' => $blueprint->remix->id]),
]) !!}
</x-slot:body>
</x-mail::plain.notification>
