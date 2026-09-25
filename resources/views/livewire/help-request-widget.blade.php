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
        @click.outside="open = false"
        class="absolute bottom-16 right-0 flex w-[22rem] max-w-[calc(100vw-3rem)] flex-col overflow-hidden rounded-xl bg-white shadow-2xl ring-1 ring-gray-950/10 dark:bg-gray-900 dark:ring-white/10"
        style="display: none;"
    >
        {{-- Header / Tabs --}}
        <div class="flex items-center justify-between border-b border-gray-100 px-4 pt-3 dark:border-white/10">
            <div class="flex gap-4">
                <button
                    type="button"
                    wire:click="$set('tab', 'new')"
                    @class([
                        'pb-2 text-sm font-medium transition',
                        'border-b-2 border-primary-500 text-gray-900 dark:text-white' => $tab === 'new',
                        'text-gray-500 hover:text-gray-700 dark:text-gray-400 dark:hover:text-gray-200' => $tab !== 'new',
                    ])
                >
                    Help Request
                </button>
                <button
                    type="button"
                    wire:click="$set('tab', 'mine')"
                    @class([
                        'pb-2 text-sm font-medium transition',
                        'border-b-2 border-primary-500 text-gray-900 dark:text-white' => $tab === 'mine',
                        'text-gray-500 hover:text-gray-700 dark:text-gray-400 dark:hover:text-gray-200' => $tab !== 'mine',
                    ])
                >
                    My Requests
                </button>
            </div>
            <button type="button" @click="open = false" class="mb-2 text-gray-400 hover:text-gray-600 dark:hover:text-gray-200">
                <x-heroicon-o-x-mark class="h-5 w-5" />
            </button>
        </div>

        {{-- New request tab --}}
        @if ($tab === 'new')
            <form wire:submit="submit" class="flex flex-col gap-3 p-4">
                <div x-show="pageName !== ''">
                    <p class="text-sm text-gray-500 dark:text-gray-400">
                        About <span class="font-semibold text-gray-700 dark:text-gray-200" x-text="pageName"></span>
                    </p>
                </div>

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
                        rows="4"
                        placeholder="Describe the issue or question..."
                        class="block w-full resize-y rounded-lg border border-gray-200 bg-white px-3 py-2 text-sm text-gray-900 shadow-sm outline-none transition placeholder:text-gray-400 focus:border-primary-500 focus:ring-1 focus:ring-primary-500 dark:border-white/10 dark:bg-white/5 dark:text-white dark:placeholder:text-gray-500"
                    ></textarea>
                    @error('comment')
                        <p class="mt-1 text-xs text-danger-600 dark:text-danger-400">{{ $message }}</p>
                    @enderror
                </div>

                <div>
                    <label class="mb-1 block text-sm font-medium text-gray-700 dark:text-gray-200">
                        Screenshot <span class="font-normal text-gray-400">(optional)</span>
                    </label>
                    <input
                        type="file"
                        wire:model="screenshot"
                        accept="image/*"
                        class="block w-full text-sm text-gray-600 file:mr-3 file:rounded-lg file:border-0 file:bg-gray-100 file:px-3 file:py-1.5 file:text-sm file:font-medium file:text-gray-700 hover:file:bg-gray-200 dark:text-gray-300 dark:file:bg-white/10 dark:file:text-gray-200"
                    />
                    <div wire:loading wire:target="screenshot" class="mt-1 text-xs text-gray-400">Uploading…</div>
                    @error('screenshot')
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
            <div class="max-h-[26rem] overflow-y-auto p-4">
                @forelse ($this->myRequests as $request)
                    @php($isLinked = $request->id === $highlightRequestId)
                    <div
                        wire:key="hr-{{ $request->id }}"
                        @if ($isLinked) x-init="$el.scrollIntoView({ block: 'nearest' })" @endif
                        @class([
                            'mb-3 rounded-lg border p-3 last:mb-0',
                            'border-gray-100 dark:border-white/10' => ! $isLinked,
                            'border-primary-500 ring-1 ring-primary-500' => $isLinked,
                        ])
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
                        </div>
                        <p class="text-sm text-gray-700 dark:text-gray-200">{{ $request->comment }}</p>

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
                                        @if ($reply->hasScreenshot())
                                            <a href="{{ $reply->getScreenshotUrl() }}" target="_blank" rel="noopener" class="mt-1.5 block">
                                                <img
                                                    src="{{ $reply->getScreenshotUrl() }}"
                                                    alt="Reply screenshot"
                                                    class="max-h-32 w-auto rounded border border-gray-200 dark:border-white/10"
                                                />
                                            </a>
                                        @endif
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
                                        rows="1"
                                        placeholder="Write a reply..."
                                        class="block w-full resize-y rounded-lg border border-gray-200 bg-white px-2.5 py-1.5 text-sm text-gray-900 outline-none transition placeholder:text-gray-400 focus:border-primary-500 focus:ring-1 focus:ring-primary-500 dark:border-white/10 dark:bg-white/5 dark:text-white dark:placeholder:text-gray-500"
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
                                    <label class="block text-xs text-gray-500 dark:text-gray-400">
                                        Screenshot <span class="text-gray-400">(optional)</span>
                                    </label>
                                    <input
                                        type="file"
                                        wire:model="replyScreenshot.{{ $request->id }}"
                                        accept="image/*"
                                        class="mt-1 block w-full text-xs text-gray-600 file:mr-2 file:rounded-md file:border-0 file:bg-gray-100 file:px-2 file:py-1 file:text-xs file:font-medium file:text-gray-700 hover:file:bg-gray-200 dark:text-gray-300 dark:file:bg-white/10 dark:file:text-gray-200"
                                    />
                                    <div wire:loading wire:target="replyScreenshot.{{ $request->id }}" class="mt-1 text-xs text-gray-400">Uploading…</div>
                                    @if (($replyScreenshot[$request->id] ?? null)?->isPreviewable())
                                        <img
                                            src="{{ $replyScreenshot[$request->id]->temporaryUrl() }}"
                                            alt="Selected screenshot"
                                            class="mt-1.5 max-h-24 w-auto rounded border border-gray-200 dark:border-white/10"
                                        />
                                    @endif
                                    @error('replyScreenshot.'.$request->id)
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
