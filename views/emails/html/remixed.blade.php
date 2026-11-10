<x-mail::html.notification>
    <x-slot:body>
        {!! $formatter->convert($translator->trans('ernestdefoe-tier-lists.email.remixed.html.body', [
            '{username}' => $blueprint->remixer->display_name,
            '{title}' => $blueprint->remix->title,
            '{url}' => $url->to('forum')->route('tier-lists.show', ['id' => $blueprint->remix->id]),
        ])) !!}
    </x-slot:body>

    <x-slot:preview>
        {{ $blueprint->remix->title }}
    </x-slot:preview>
</x-mail::html.notification>
