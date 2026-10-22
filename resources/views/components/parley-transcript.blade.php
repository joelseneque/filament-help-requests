{{-- The visible turns of a Parley chat, on the admin request page. --}}
<div class="flex flex-col gap-3">
    @forelse ($getState() as $message)
        @if ($message->isFromAssistant())
            <div class="flex flex-col items-start gap-1">
                <div class="max-w-[85%] rounded-xl rounded-tl-sm bg-gray-100 px-3 py-2 text-sm text-gray-800 dark:bg-white/5 dark:text-gray-100 [&_a]:text-primary-600 [&_a]:underline [&_ol]:list-decimal [&_ol]:pl-5 [&_ul]:list-disc [&_ul]:pl-5 [&>*+*]:mt-2">
                    {!! $message->html() !!}
                </div>
                <p class="text-xs text-gray-400">
                    {{ \Joelseneque\HelpRequests\Parley\Parley::name() }} · {{ $message->created_at->format('d M, g:i A') }}
                    @if ($message->rating === 1) · marked helpful @endif
                    @if ($message->rating === -1) · <span class="text-danger-600 dark:text-danger-400">marked unhelpful</span> @endif
                    @if (! empty($message->meta['articles_read'])) · read {{ implode(', ', $message->meta['articles_read']) }} @endif
                </p>
            </div>
        @else
            <div class="flex flex-col items-end gap-1">
                <p class="max-w-[85%] whitespace-pre-line rounded-xl rounded-tr-sm bg-primary-50 px-3 py-2 text-sm text-gray-800 dark:bg-primary-400/10 dark:text-gray-100">{{ $message->text }}</p>
                <p class="text-xs text-gray-400">{{ $message->created_at->format('d M, g:i A') }}</p>
            </div>
        @endif
    @empty
        <p class="text-sm text-gray-400">No messages.</p>
    @endforelse
</div>
