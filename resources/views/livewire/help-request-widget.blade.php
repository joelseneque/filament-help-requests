<div
    x-data="{
        open: @entangle('open'),
        pageName: '',
        brandName: @js($brandName),
        {{-- Filament titles read "{page} - {brand}"; the brand says nothing about where you are. --}}
        capturePage() {
            const suffix = ' - ' + this.brandName;
            const title = document.title.trim();

            this.pageName = this.brandName !== '' && title.endsWith(suffix)
                ? title.slice(0, -suffix.length)
                : title;
        },
    }"
    x-init="capturePage(); $watch('open', (isOpen) => isOpen && capturePage())"
    x-on:livewire:navigated.window="capturePage()"
    class="help-widget fixed bottom-6 right-6 z-30 print:hidden"
>
    {{-- Panel --}}
    <div
        x-show="open"
        x-cloak
        x-transition:enter="transition ease-out duration-150"
        x-transition:enter-start="opacity-0 translate-y-2"
        x-transition:enter-end="opacity-100 translate-y-0"
        x-transition:leave="transition ease-in duration-100"
        x-transition:leave-start="opacity-100 translate-y-0"
        x-transition:leave-end="opacity-0 translate-y-2"
        {{-- The screenshot marker dialog is teleported to <body>, so clicks in it count as "outside". --}}
        @click.outside="$event.target.closest('[data-help-screenshot-modal]') || (open = false)"
        class="absolute bottom-16 right-0 flex w-[24rem] max-w-[calc(100vw-3rem)] flex-col overflow-hidden rounded-xl bg-white shadow-2xl ring-1 ring-gray-950/10 dark:bg-gray-900 dark:ring-white/10"
        style="display: none;"
    >
        {{-- Header / Tabs --}}
        <div class="flex items-center justify-between border-b border-gray-100 px-4 pt-3 dark:border-white/10">
            <div class="flex gap-4">
                @php
                    $tabs = array_filter([
                        'parley' => $this->parleyEnabled ? 'Ask '.$this->parleyName : null,
                        'new' => 'Help request',
                        'mine' => 'My requests',
                    ]);
                @endphp
                @foreach ($tabs as $key => $label)
                    <button
                        type="button"
                        wire:key="hr-tab-{{ $key }}"
                        wire:click="$set('tab', '{{ $key }}')"
                        @class([
                            'pb-2 text-sm font-medium transition',
                            'border-b-2 border-primary-500 text-gray-900 dark:text-white' => $tab === $key,
                            'text-gray-500 hover:text-gray-700 dark:text-gray-400 dark:hover:text-gray-200' => $tab !== $key,
                        ])
                    >
                        {{ $label }}
                    </button>
                @endforeach
            </div>
            <button type="button" @click="open = false" class="mb-2 text-gray-400 hover:text-gray-600 dark:hover:text-gray-200">
                <x-heroicon-o-x-mark class="h-5 w-5" />
            </button>
        </div>

        {{-- Parley: the help chat --}}
        @if ($tab === 'parley' && $this->parleyEnabled)
            <div
                class="flex flex-col"
                x-data="{
                    draft: '',
                    pending: null,
                    send(question = null) {
                        const text = (question ?? this.draft).trim();

                        if (text.length < 2 || this.pending) {
                            return;
                        }

                        this.pending = text;
                        this.draft = '';
                        capturePage();
                        $wire.pageUrl = window.location.href;
                        $wire.pageTitle = pageName;
                        this.$nextTick(() => this.scrollDown());

                        $wire.ask(text).finally(() => {
                            this.pending = null;
                            this.$nextTick(() => this.scrollDown());
                        });
                    },
                    scrollDown() {
                        if (this.$refs.thread) {
                            this.$refs.thread.scrollTop = this.$refs.thread.scrollHeight;
                        }
                    },
                }"
                x-init="$nextTick(() => scrollDown()); @if ($this->parleyMessages->isEmpty() && $starterQuestions === []) $wire.loadStarterQuestions(window.location.pathname) @endif"
            >
                {{-- The chat in view, the list of earlier chats, and a fresh one. --}}
                <div class="flex items-center justify-between gap-2 border-b border-gray-100 px-4 py-2 text-xs dark:border-white/10">
                    <button
                        type="button"
                        wire:click="$toggle('showingChats')"
                        @class([
                            'inline-flex shrink-0 items-center gap-1 font-medium transition',
                            'text-primary-600 dark:text-primary-400' => $showingChats,
                            'text-gray-500 hover:text-gray-700 dark:text-gray-400 dark:hover:text-gray-200' => ! $showingChats,
                        ])
                    >
                        <x-heroicon-m-clock class="h-3.5 w-3.5" />
                        {{ $showingChats ? 'Back to chat' : 'Chats' }}
                    </button>
                    <span class="truncate text-gray-400">{{ $showingChats ? '' : $this->conversationLabel }}</span>
                    <button type="button" wire:click="newChat" class="inline-flex shrink-0 items-center gap-1 font-medium text-gray-500 transition hover:text-gray-700 dark:text-gray-400 dark:hover:text-gray-200">
                        <x-heroicon-m-plus class="h-3.5 w-3.5" />
                        New chat
                    </button>
                </div>

                @if ($showingChats)
                    <div class="max-h-[30rem] min-h-[12rem] overflow-y-auto p-2">
                        @forelse ($this->chatHistory as $day => $chats)
                            <p wire:key="parley-day-{{ $loop->index }}" class="px-2 pb-1 pt-3 text-xs font-semibold uppercase tracking-wide text-gray-400 first:pt-1">{{ $day }}</p>
                            @foreach ($chats as $chat)
                                <button
                                    type="button"
                                    wire:key="parley-chat-{{ $chat['id'] }}"
                                    wire:click="openConversation({{ $chat['id'] }})"
                                    @class([
                                        'flex w-full items-center gap-2 rounded-lg px-2 py-1.5 text-left transition hover:bg-gray-50 dark:hover:bg-white/5',
                                        'bg-primary-50 dark:bg-primary-400/10' => $chat['id'] === $conversationId,
                                    ])
                                >
                                    <span class="min-w-0 flex-1 truncate text-sm text-gray-700 dark:text-gray-200">{{ $chat['title'] }}</span>
                                    <span class="shrink-0 text-xs text-gray-400">{{ $chat['time'] }}</span>
                                </button>
                            @endforeach
                        @empty
                            <p class="py-8 text-center text-sm text-gray-400">No chats yet.</p>
                        @endforelse
                    </div>
                @else
                <div x-ref="thread" class="flex max-h-[26rem] min-h-[12rem] flex-col gap-3 overflow-y-auto p-4">
                    @if ($this->parleyMessages->isEmpty())
                        <div x-show="! pending" class="py-2">
                            <p class="text-sm text-gray-700 dark:text-gray-200">
                                Hi {{ \Joelseneque\HelpRequests\HelpRequests::userFirstName(auth()->user()) }}, I'm {{ $this->parleyName }}. Ask me how to do something in {{ \Joelseneque\HelpRequests\HelpRequests::appName() }}, or what something means.
                            </p>
                            @if ($starterQuestions !== [])
                                <div class="mt-3 flex flex-col items-start gap-1.5">
                                    @foreach ($starterQuestions as $index => $starter)
                                        <button
                                            type="button"
                                            wire:key="parley-starter-{{ $index }}"
                                            x-on:click="send(@js($starter))"
                                            class="rounded-full border border-gray-300 px-3 py-1 text-left text-xs text-gray-600 transition hover:border-primary-500 hover:text-primary-600 dark:border-white/15 dark:text-gray-300"
                                        >{{ $starter }}</button>
                                    @endforeach
                                </div>
                            @endif
                        </div>
                    @endif

                    @php
                        $messages = $this->parleyMessages;
                        $keep = max(2, (int) config('help-requests.parley.condense_after', 6));
                        $earlier = $messages->count() > $keep ? $messages->slice(0, $messages->count() - $keep) : collect();
                        $recent = $messages->slice($earlier->count());
                    @endphp

                    {{--
                        A long chat is folded above its last few turns: the earlier questions stay
                        readable as one line each, and "Show all" opens the turns themselves.
                    --}}
                    @if ($earlier->isNotEmpty())
                        <div x-data="{ showEarlier: false }" wire:key="parley-earlier-{{ $conversationId }}-{{ $earlier->count() }}" class="flex flex-col gap-3">
                            <div x-show="! showEarlier" class="rounded-lg border border-dashed border-gray-200 px-3 py-2 dark:border-white/10">
                                <div class="flex items-center justify-between gap-2">
                                    <p class="text-xs font-medium text-gray-500 dark:text-gray-400">Earlier in this chat</p>
                                    <button type="button" x-on:click="showEarlier = true" class="text-xs font-medium text-primary-600 hover:underline dark:text-primary-400">Show all</button>
                                </div>
                                <ul class="mt-1 space-y-0.5">
                                    @foreach ($earlier->reject->isFromAssistant() as $question)
                                        <li class="truncate text-xs text-gray-500 dark:text-gray-400">{{ $question->text }}</li>
                                    @endforeach
                                </ul>
                            </div>
                            <div x-show="showEarlier" x-cloak class="flex flex-col gap-3">
                                @foreach ($earlier as $message)
                                    @include('help-requests::livewire.partials.parley-message', ['message' => $message])
                                @endforeach
                                <button type="button" x-on:click="showEarlier = false" class="self-center text-xs text-gray-400 hover:text-gray-600 dark:hover:text-gray-200">Fold earlier messages</button>
                            </div>
                        </div>
                    @endif

                    @foreach ($recent as $message)
                        @include('help-requests::livewire.partials.parley-message', ['message' => $message])
                    @endforeach

                    <template x-if="pending">
                        <div class="flex flex-col gap-3">
                            <div class="flex justify-end">
                                <p class="max-w-[85%] whitespace-pre-line rounded-2xl rounded-tr-sm bg-primary-600 px-3 py-2 text-sm text-white" x-text="pending"></p>
                            </div>
                            <div class="flex items-center gap-1 px-1 py-2" aria-label="{{ $this->parleyName }} is answering">
                                <span class="h-1.5 w-1.5 animate-bounce rounded-full bg-gray-400 [animation-delay:-0.3s]"></span>
                                <span class="h-1.5 w-1.5 animate-bounce rounded-full bg-gray-400 [animation-delay:-0.15s]"></span>
                                <span class="h-1.5 w-1.5 animate-bounce rounded-full bg-gray-400"></span>
                            </div>
                        </div>
                    </template>

                    @if ($parleyError !== '')
                        <p class="rounded-lg bg-danger-50 px-3 py-2 text-xs text-danger-700 dark:bg-danger-400/10 dark:text-danger-400">{{ $parleyError }}</p>
                    @endif
                </div>

                <form x-on:submit.prevent="send()" class="border-t border-gray-100 p-3 dark:border-white/10">
                    <div class="flex items-end gap-2 rounded-lg border border-gray-200 bg-white px-2.5 py-1.5 transition focus-within:border-primary-500 focus-within:ring-1 focus-within:ring-primary-500 dark:border-white/10 dark:bg-white/5">
                        {{--
                            Grows with the question, upwards, since the panel is pinned to the bottom.
                            While the panel is closed the box has no size to measure, and fixing its
                            height then left a 0px box behind; so a hidden box keeps its natural
                            height, and focusing it measures again.
                        --}}
                        <textarea
                            x-model="draft"
                            x-data="{ fit() { if (! this.$el.getClientRects().length) { this.$el.style.height = ''; return } this.$el.style.height = 'auto'; this.$el.style.height = Math.min(this.$el.scrollHeight, 160) + 'px'; this.$el.style.overflowY = this.$el.scrollHeight > 160 ? 'auto' : 'hidden' } }"
                            x-on:focus="fit()"
                            x-effect="draft; $nextTick(() => fit())"
                            x-on:keydown.enter="if (! $event.shiftKey) { $event.preventDefault(); send() }"
                            rows="1"
                            maxlength="2000"
                            placeholder="Ask {{ $this->parleyName }} a question..."
                            class="block w-full resize-none border-0 bg-transparent p-0 py-1 text-sm text-gray-900 outline-none placeholder:text-gray-400 focus:outline-none focus:ring-0 dark:text-white dark:placeholder:text-gray-500"
                        ></textarea>
                        <button
                            type="submit"
                            x-bind:disabled="pending || draft.trim().length < 2"
                            class="shrink-0 rounded-md bg-primary-600 p-1.5 text-white transition hover:bg-primary-500 disabled:opacity-40"
                            title="Send"
                        >
                            <x-heroicon-m-paper-airplane class="h-4 w-4" />
                        </button>
                    </div>
                    <div class="mt-2 text-xs text-gray-400">
                        <button type="button" wire:click="raiseRequest" class="hover:text-gray-600 hover:underline dark:hover:text-gray-200">Still stuck? Raise a help request</button>
                    </div>
                </form>
                @endif
            </div>
        @endif

        {{-- New request tab --}}
        @if ($tab === 'new')
            <form wire:submit="submit" class="flex flex-col gap-3 p-4">
                <div x-show="pageName !== ''">
                    <p class="text-sm text-gray-500 dark:text-gray-400">
                        About <span class="font-semibold text-gray-700 dark:text-gray-200" x-text="pageName"></span>
                    </p>
                </div>

                @if ($requestConversationId)
                    <p class="flex items-center gap-1.5 rounded-lg bg-gray-50 px-3 py-2 text-xs text-gray-500 dark:bg-white/5 dark:text-gray-400">
                        <x-heroicon-m-chat-bubble-left-right class="h-4 w-4 shrink-0" />
                        Your chat with {{ $this->parleyName }} is sent with this request.
                    </p>
                @endif

                @if ($this->categories !== [])
                    <div role="radiogroup" aria-label="What kind of feedback is this?" class="flex flex-wrap gap-2">
                        @foreach ($this->categories as $key => $label)
                            <label wire:key="hr-category-{{ $key }}" class="cursor-pointer">
                                <input
                                    type="radio"
                                    name="help-request-category"
                                    value="{{ $key }}"
                                    wire:model="category"
                                    class="peer sr-only"
                                />
                                <span class="inline-flex items-center rounded-full border border-gray-300 bg-white px-3.5 py-1.5 text-sm text-gray-600 transition hover:border-gray-400 peer-checked:border-primary-600 peer-checked:bg-primary-600 peer-checked:text-white peer-focus-visible:ring-2 peer-focus-visible:ring-primary-500 peer-focus-visible:ring-offset-1 dark:border-white/15 dark:bg-white/5 dark:text-gray-300 dark:peer-checked:border-primary-500 dark:peer-checked:bg-primary-500">
                                    {{ $label }}
                                </span>
                            </label>
                        @endforeach
                    </div>
                    @error('category')
                        <p class="-mt-1 text-xs text-danger-600 dark:text-danger-400">{{ $message }}</p>
                    @enderror
                @endif

                <div>
                    <label class="mb-1 block text-sm font-medium text-gray-700 dark:text-gray-200">How can we help?</label>
                    <textarea
                        wire:model="comment"
                        x-data="{ fit() { if (! this.$el.getClientRects().length) { this.$el.style.height = ''; return } this.$el.style.height = 'auto'; this.$el.style.height = Math.min(this.$el.scrollHeight, 288) + 'px'; this.$el.style.overflowY = this.$el.scrollHeight > 288 ? 'auto' : 'hidden' } }"
                        x-on:focus="fit()"
                        x-init="$nextTick(() => fit())"
                        x-on:input="fit()"
                        rows="4"
                        placeholder="Describe the issue or question..."
                        class="block w-full resize-none rounded-lg border border-gray-200 bg-white px-3 py-2 text-sm text-gray-900 shadow-sm outline-none transition placeholder:text-gray-400 focus:border-primary-500 focus:ring-1 focus:ring-primary-500 dark:border-white/10 dark:bg-white/5 dark:text-white dark:placeholder:text-gray-500"
                    ></textarea>
                    @error('comment')
                        <p class="mt-1 text-xs text-danger-600 dark:text-danger-400">{{ $message }}</p>
                    @enderror
                </div>

                <div>
                    @include('help-requests::components.screenshot-input', [
                        'model' => 'screenshots',
                        'notesModel' => 'screenshotNotes',
                        'files' => $screenshots,
                    ])
                    @error('screenshots')
                        <p class="mt-1 text-xs text-danger-600 dark:text-danger-400">{{ $message }}</p>
                    @enderror
                    @error('screenshots.*')
                        <p class="mt-1 text-xs text-danger-600 dark:text-danger-400">{{ $message }}</p>
                    @enderror
                    @error('screenshotNotes.*.*')
                        <p class="mt-1 text-xs text-danger-600 dark:text-danger-400">{{ $message }}</p>
                    @enderror
                </div>

                @if (\Joelseneque\HelpRequests\HelpRequests::videoLinksEnabled())
                    <div>
                        <div class="mb-1 flex items-baseline justify-between gap-2">
                            <label for="help-request-video-url" class="block text-sm font-medium text-gray-700 dark:text-gray-200">
                                Loom video <span class="font-normal text-gray-400">(optional)</span>
                            </label>
                            {{--
                                Tries the Loom desktop app first. A browser gives no way to ask whether an
                                app handles a URL scheme, so "the app opened" is inferred from this page
                                losing focus shortly after; if it does not, the website opens instead.
                                Cmd/Ctrl-click and no-JS clicks still just follow the href to the website.
                            --}}
                            <a
                                href="{{ config('help-requests.video.record_url') }}"
                                target="_blank"
                                rel="noopener"
                                x-data="{
                                    appUrl: @js(config('help-requests.video.record_app_url')),
                                    openRecorder(event) {
                                        if (! this.appUrl || event.metaKey || event.ctrlKey || event.shiftKey) {
                                            return;
                                        }

                                        event.preventDefault();

                                        const webUrl = this.$el.href;
                                        let appOpened = false;
                                        const markOpened = () => { appOpened = true; };
                                        const markHidden = () => { if (document.hidden) appOpened = true; };

                                        window.addEventListener('blur', markOpened);
                                        document.addEventListener('visibilitychange', markHidden);

                                        window.location.href = this.appUrl;

                                        {{-- Short enough that the fallback still counts as part of the click, so popup blockers allow it. --}}
                                        setTimeout(() => {
                                            window.removeEventListener('blur', markOpened);
                                            document.removeEventListener('visibilitychange', markHidden);

                                            if (! appOpened) {
                                                window.open(webUrl, '_blank', 'noopener');
                                            }
                                        }, 1200);
                                    },
                                }"
                                x-on:click="openRecorder($event)"
                                class="inline-flex items-center gap-1 text-xs font-medium text-primary-600 hover:underline dark:text-primary-400"
                            >
                                Record a Loom
                                <x-heroicon-m-arrow-top-right-on-square class="h-3.5 w-3.5" />
                            </a>
                        </div>
                        <input
                            id="help-request-video-url"
                            type="url"
                            inputmode="url"
                            wire:model="videoUrl"
                            placeholder="https://www.loom.com/share/…"
                            class="block w-full rounded-lg border border-gray-200 bg-white px-3 py-2 text-sm text-gray-900 shadow-sm outline-none transition placeholder:text-gray-400 focus:border-primary-500 focus:ring-1 focus:ring-primary-500 dark:border-white/10 dark:bg-white/5 dark:text-white dark:placeholder:text-gray-500"
                        />
                        @error('videoUrl')
                            <p class="mt-1 text-xs text-danger-600 dark:text-danger-400">{{ $message }}</p>
                        @enderror
                    </div>
                @endif

                <button
                    type="submit"
                    x-on:click="capturePage(); $wire.pageUrl = window.location.href; $wire.pageTitle = pageName"
                    wire:loading.attr="disabled"
                    wire:target="submit"
                    class="inline-flex items-center justify-center rounded-lg bg-primary-600 px-4 py-2 text-sm font-semibold text-white shadow-sm transition hover:bg-primary-500 disabled:opacity-60"
                >
                    <span wire:loading.remove wire:target="submit">Send request</span>
                    <span wire:loading wire:target="submit">Sending…</span>
                </button>
            </form>
        @endif

        {{-- My requests tab --}}
        @if ($tab === 'mine')
            {{-- One thread open at a time; the one a notification linked to starts open. --}}
            <div class="max-h-[26rem] overflow-y-auto p-4" x-data="{ expanded: @js($highlightRequestId) }">
                @forelse ($this->myRequests as $request)
                    @php($isLinked = $request->id === $highlightRequestId)
                    <div
                        wire:key="hr-{{ $request->id }}"
                        @if ($isLinked) x-init="$el.scrollIntoView({ block: 'nearest' })" @endif
                        @class([
                            'mb-2 rounded-lg border last:mb-0',
                            'border-gray-100 dark:border-white/10' => ! $isLinked,
                            'border-primary-500 ring-1 ring-primary-500' => $isLinked,
                        ])
                    >
                        <button
                            type="button"
                            x-on:click="expanded = expanded === {{ $request->id }} ? null : {{ $request->id }}"
                            x-bind:aria-expanded="expanded === {{ $request->id }}"
                            aria-controls="hr-thread-{{ $request->id }}"
                            class="block w-full rounded-lg p-3 text-left transition hover:bg-gray-50 dark:hover:bg-white/5"
                        >
                            <div class="mb-1 flex items-center gap-2">
                                <span @class([
                                    'rounded-full px-2 py-0.5 text-xs font-medium',
                                    'bg-warning-100 text-warning-700 dark:bg-warning-400/10 dark:text-warning-400' => $request->status === \Joelseneque\HelpRequests\Enums\HelpRequestStatus::Open,
                                    'bg-info-100 text-info-700 dark:bg-info-400/10 dark:text-info-400' => $request->status === \Joelseneque\HelpRequests\Enums\HelpRequestStatus::InProgress,
                                    'bg-success-100 text-success-700 dark:bg-success-400/10 dark:text-success-400' => $request->status === \Joelseneque\HelpRequests\Enums\HelpRequestStatus::Resolved,
                                    'bg-gray-100 text-gray-600 dark:bg-white/10 dark:text-gray-300' => $request->status === \Joelseneque\HelpRequests\Enums\HelpRequestStatus::Closed,
                                ])>{{ $request->status->getLabel() }}</span>
                                @if ($request->category)
                                    <span class="truncate text-xs font-medium text-gray-500 dark:text-gray-400">{{ $request->categoryLabel() }}</span>
                                @endif
                                <span class="ml-auto shrink-0 text-xs text-gray-400">{{ $request->created_at->diffForHumans() }}</span>
                                <x-heroicon-m-chevron-right
                                    class="h-4 w-4 shrink-0 text-gray-400 transition-transform"
                                    x-bind:class="expanded === {{ $request->id }} && 'rotate-90'"
                                />
                            </div>
                            <div class="flex items-start gap-2">
                                <p
                                    class="min-w-0 flex-1 whitespace-pre-line text-sm text-gray-700 dark:text-gray-200"
                                    x-bind:class="expanded !== {{ $request->id }} && 'line-clamp-1'"
                                >{{ $request->comment }}</p>
                                @if ($request->replies->isNotEmpty())
                                    <span
                                        x-show="expanded !== {{ $request->id }}"
                                        class="inline-flex shrink-0 items-center gap-0.5 text-xs text-gray-400"
                                        title="{{ $request->replies->count() }} {{ str('reply')->plural($request->replies->count()) }}"
                                    >
                                        <x-heroicon-m-chat-bubble-left class="h-3.5 w-3.5" />
                                        {{ $request->replies->count() }}
                                    </span>
                                @endif
                            </div>
                        </button>

                        <div
                            id="hr-thread-{{ $request->id }}"
                            x-show="expanded === {{ $request->id }}"
                            x-collapse
                            class="px-3 pb-3"
                        >
                            @if ($request->hasVideo())
                                <a
                                    href="{{ $request->video_url }}"
                                    target="_blank"
                                    rel="noopener"
                                    class="mt-1.5 inline-flex items-center gap-1 text-xs font-medium text-primary-600 hover:underline dark:text-primary-400"
                                >
                                    <x-heroicon-m-play-circle class="h-4 w-4" />
                                    Watch video
                                </a>
                            @endif

                            @if ($request->replies->isNotEmpty())
                                <div class="mt-2 space-y-2 border-t border-gray-100 pt-2 dark:border-white/10">
                                    @foreach ($request->replies as $reply)
                                        @php($isMine = $reply->user_id === auth()->id())
                                        <div wire:key="reply-{{ $reply->id }}" @class([
                                            'rounded-md p-2',
                                            'bg-primary-50 dark:bg-primary-400/10' => $isMine,
                                            'bg-gray-50 dark:bg-white/5' => ! $isMine,
                                        ])>
                                            <p class="text-xs font-medium text-gray-500 dark:text-gray-400">
                                                {{ $isMine ? 'You' : ($reply->user ? \Joelseneque\HelpRequests\HelpRequests::userFirstName($reply->user) : $reply->authorName()) }} replied
                                            </p>
                                            <p class="text-sm text-gray-700 dark:text-gray-200">{{ $reply->body }}</p>
                                            @if ($reply->imageAttachmentPaths() !== [])
                                                <div class="mt-1.5 flex flex-wrap gap-1.5">
                                                    @foreach ($reply->imageAttachmentPaths() as $path)
                                                        <a href="{{ $reply->attachmentUrl($path) }}" target="_blank" rel="noopener" class="block">
                                                            <img
                                                                src="{{ $reply->attachmentUrl($path) }}"
                                                                alt="{{ $reply->attachmentName($path) }}"
                                                                class="max-h-32 w-auto rounded border border-gray-200 dark:border-white/10"
                                                            />
                                                        </a>
                                                    @endforeach
                                                </div>
                                            @endif
                                            @foreach ($reply->fileAttachmentPaths() as $path)
                                                <a href="{{ $reply->attachmentUrl($path) }}" target="_blank" rel="noopener" class="mt-1 flex items-center gap-1 text-xs font-medium text-primary-600 hover:underline dark:text-primary-400">
                                                    <x-heroicon-o-paper-clip class="h-3.5 w-3.5 shrink-0" />
                                                    <span class="truncate">{{ $reply->attachmentName($path) }}</span>
                                                </a>
                                            @endforeach
                                        </div>
                                    @endforeach
                                </div>
                            @endif

                            @if ($request->status !== \Joelseneque\HelpRequests\Enums\HelpRequestStatus::Closed)
                                <form
                                    wire:submit="replyTo({{ $request->id }})"
                                    wire:key="reply-form-{{ $request->id }}-{{ $request->replies->count() }}"
                                    class="mt-2 flex flex-col gap-2"
                                >
                                    <div class="flex items-start gap-2">
                                        <textarea
                                            wire:model="replyBody.{{ $request->id }}"
                                            x-data="{ fit() { if (! this.$el.getClientRects().length) { this.$el.style.height = ''; return } this.$el.style.height = 'auto'; this.$el.style.height = Math.min(this.$el.scrollHeight, 160) + 'px'; this.$el.style.overflowY = this.$el.scrollHeight > 160 ? 'auto' : 'hidden' } }"
                                            x-on:focus="fit()"
                                            x-init="$nextTick(() => fit())"
                                            x-on:input="fit()"
                                            rows="1"
                                            placeholder="Write a reply..."
                                            class="block w-full resize-none rounded-lg border border-gray-200 bg-white px-2.5 py-1.5 text-sm text-gray-900 outline-none transition placeholder:text-gray-400 focus:border-primary-500 focus:ring-1 focus:ring-primary-500 dark:border-white/10 dark:bg-white/5 dark:text-white dark:placeholder:text-gray-500"
                                        ></textarea>
                                        <button
                                            type="submit"
                                            wire:loading.attr="disabled"
                                            wire:target="replyTo({{ $request->id }})"
                                            class="shrink-0 rounded-lg bg-primary-600 px-3 py-1.5 text-sm font-medium text-white transition hover:bg-primary-500 disabled:opacity-60"
                                        >
                                            Send
                                        </button>
                                    </div>

                                    <div>
                                        @include('help-requests::components.screenshot-input', [
                                            'model' => "replyScreenshots.{$request->id}",
                                            'notesModel' => "replyScreenshotNotes.{$request->id}",
                                            'files' => $replyScreenshots[$request->id] ?? [],
                                        ])
                                        @error('replyScreenshots.'.$request->id.'.*')
                                            <p class="mt-1 text-xs text-danger-600 dark:text-danger-400">{{ $message }}</p>
                                        @enderror
                                        @error('replyScreenshots.'.$request->id)
                                            <p class="mt-1 text-xs text-danger-600 dark:text-danger-400">{{ $message }}</p>
                                        @enderror
                                    </div>
                                </form>
                                @error('replyBody.'.$request->id)
                                    <p class="mt-1 text-xs text-danger-600 dark:text-danger-400">{{ $message }}</p>
                                @enderror
                            @else
                                <p class="mt-2 text-xs text-gray-400">This request is closed.</p>
                            @endif
                        </div>
                    </div>
                @empty
                    <p class="py-6 text-center text-sm text-gray-400">You haven't sent any help requests yet.</p>
                @endforelse
            </div>
        @endif
    </div>

    {{-- Floating ? button --}}
    <button
        type="button"
        @click="open = ! open"
        class="flex h-12 w-12 items-center justify-center rounded-full bg-primary-600 text-white shadow-lg ring-1 ring-primary-500/50 transition hover:bg-primary-500 focus:outline-none focus:ring-2 focus:ring-primary-400"
        title="Get help"
    >
        <x-heroicon-o-question-mark-circle x-show="!open" class="h-6 w-6" />
        <x-heroicon-o-x-mark x-show="open" x-cloak class="h-6 w-6" />
    </button>
</div>
