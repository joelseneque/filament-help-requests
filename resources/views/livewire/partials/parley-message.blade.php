{{-- One turn of a Parley chat in the widget: a question, or an answer with its buttons. --}}
@if ($message->isFromAssistant())
    <div wire:key="parley-{{ $message->id }}" class="flex flex-col items-start gap-1">
        <div class="parley-answer max-w-[90%] rounded-2xl rounded-tl-sm bg-gray-100 px-3 py-2 text-sm text-gray-800 dark:bg-white/5 dark:text-gray-100 [&_a]:font-medium [&_a]:text-primary-600 [&_a]:underline dark:[&_a]:text-primary-400 [&_code]:rounded [&_code]:bg-gray-200 [&_code]:px-1 [&_code]:text-xs dark:[&_code]:bg-white/10 [&_li]:mt-0.5 [&_ol]:list-decimal [&_ol]:pl-5 [&_p+*]:mt-2 [&_strong]:font-semibold [&_ul]:list-disc [&_ul]:pl-5 [&>*+*]:mt-2">
            {!! $message->html() !!}
        </div>

        @if ($suggestion = $message->suggestedRequest())
            <button
                type="button"
                wire:click="raiseRequest({{ $message->id }})"
                class="inline-flex items-center gap-1 rounded-lg bg-primary-600 px-2.5 py-1 text-xs font-semibold text-white transition hover:bg-primary-500"
            >
                <x-heroicon-m-lifebuoy class="h-3.5 w-3.5" />
                Raise {{ filled($suggestion['type']) ? strtolower(\Joelseneque\HelpRequests\HelpRequests::categoryLabel($suggestion['type'])).' request' : 'a help request' }}
            </button>
        @endif

        <div class="flex items-center gap-1 text-gray-400">
            <button
                type="button"
                wire:click="rateAnswer({{ $message->id }}, 1)"
                title="Helpful"
                @class(['rounded p-0.5 transition hover:text-gray-600 dark:hover:text-gray-200', 'text-primary-600 dark:text-primary-400' => $message->rating === 1])
            >
                <x-heroicon-m-hand-thumb-up class="h-3.5 w-3.5" />
            </button>
            <button
                type="button"
                wire:click="rateAnswer({{ $message->id }}, -1)"
                title="Not helpful"
                @class(['rounded p-0.5 transition hover:text-gray-600 dark:hover:text-gray-200', 'text-danger-600 dark:text-danger-400' => $message->rating === -1])
            >
                <x-heroicon-m-hand-thumb-down class="h-3.5 w-3.5" />
            </button>
            @if ($message->rating === -1)
                <span class="text-xs">Thanks, we'll improve this answer.</span>
            @endif
        </div>
    </div>
@else
    <div wire:key="parley-{{ $message->id }}" class="flex justify-end">
        <p class="max-w-[85%] whitespace-pre-line rounded-2xl rounded-tr-sm bg-primary-600 px-3 py-2 text-sm text-white">{{ $message->text }}</p>
    </div>
@endif
