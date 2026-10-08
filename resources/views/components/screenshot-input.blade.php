{{--
    Screenshot picker with "mark where it goes wrong": choose, paste or drop
    images, click each to drop numbered markers, note each one. On Done the
    markers are burned into the image in the browser and uploaded to
    "{$model}.{slot}", and the notes go to "{$notesModel}.{slot}" for the
    component to append to the text, so every channel (admin, email, GitHub)
    gets them without knowing about markers. Several images are marked one
    after another.

    @param string $model        Livewire array of uploads, e.g. "screenshots" or "replyScreenshots.5"
    @param string $notesModel   Livewire array of marker notes, keyed like $model
    @param array<int, TemporaryUploadedFile|null> $files  The current uploads, for the thumbnails
--}}
@php
    $attached = array_filter($files);
    $max = (int) config('help-requests.storage.max_files', 5);
@endphp

<div
    x-data="{
        model: @js($model),
        notesModel: @js($notesModel),
        max: @js($max),
        maxBytes: @js(config('help-requests.storage.max_size_kb') * 1024),
        {{-- Slots only ever grow, so a removed screenshot's slot is never reused for another's notes. --}}
        nextSlot: @js(count($files)),
        editing: false,
        saving: false,
        dragging: false,
        progress: null,
        queue: [],
        {{-- Per slot: the unmarked image and its markers, so "Edit marks" starts from a clean copy. --}}
        originals: {},
        slot: null,
        pending: null,
        draft: [],
        nextId: 1,

        init() {
            const form = this.$el.closest('form');

            form?.addEventListener('paste', (event) => {
                const files = [...(event.clipboardData?.files ?? [])].filter((f) => f.type.startsWith('image/'));

                if (files.length) {
                    event.preventDefault();
                    this.pick(files);
                }
            });
            form?.addEventListener('dragover', (event) => { event.preventDefault(); this.dragging = true; });
            form?.addEventListener('dragleave', () => { this.dragging = false; });
            form?.addEventListener('drop', (event) => {
                event.preventDefault();
                this.dragging = false;
                this.pick(event.dataTransfer?.files ?? []);
            });
        },

        pick(files) {
            const attached = this.$root.querySelectorAll('[data-screenshot-slot]').length;
            const room = this.max - attached - this.queue.length - (this.editing && this.originals[this.slot] === undefined ? 1 : 0);
            const images = [...files].filter((f) => f.type.startsWith('image/')).slice(0, Math.max(0, room));

            this.queue.push(...images);

            if (! this.editing) {
                this.next();
            }
        },

        next() {
            const file = this.queue.shift();

            if (! file) {
                return;
            }

            this.pending = { url: URL.createObjectURL(file), name: file.name || 'screenshot.png' };
            this.draft = [];
            this.slot = this.nextSlot++;
            this.editing = true;
        },

        editMarks(slot) {
            this.pending = this.originals[slot].image;
            this.draft = this.originals[slot].markers.map((m) => ({ ...m }));
            this.slot = slot;
            this.editing = true;
        },

        addMarker(event) {
            if (this.draft.length >= 20) {
                return;
            }

            const rect = event.currentTarget.getBoundingClientRect();

            this.draft.push({
                id: this.nextId++,
                x: ((event.clientX - rect.left) / rect.width) * 100,
                y: ((event.clientY - rect.top) / rect.height) * 100,
                note: '',
            });

            this.$nextTick(() => [...this.$refs.notes.querySelectorAll('textarea')].pop()?.focus());
        },

        close() {
            this.pending = null;
            this.editing = false;
            this.saving = false;
            this.progress = null;
            this.next();
        },

        cancel() {
            if (this.pending && this.pending !== this.originals[this.slot]?.image) {
                URL.revokeObjectURL(this.pending.url);
            }

            this.close();
        },

        async flatten() {
            const img = new Image();
            img.src = this.pending.url;
            await img.decode();

            const canvas = document.createElement('canvas');
            canvas.width = img.naturalWidth;
            canvas.height = img.naturalHeight;

            const ctx = canvas.getContext('2d');
            ctx.drawImage(img, 0, 0);

            const r = Math.max(12, Math.round(canvas.width * 0.014));

            this.draft.forEach((m, i) => {
                const x = (m.x / 100) * canvas.width;
                const y = (m.y / 100) * canvas.height;

                ctx.beginPath();
                ctx.arc(x, y, r, 0, Math.PI * 2);
                ctx.fillStyle = '#dc2626';
                ctx.fill();
                ctx.lineWidth = Math.max(2, r * 0.2);
                ctx.strokeStyle = '#ffffff';
                ctx.stroke();
                ctx.fillStyle = '#ffffff';
                ctx.font = `bold ${Math.round(r * 1.1)}px system-ui, sans-serif`;
                ctx.textAlign = 'center';
                ctx.textBaseline = 'middle';
                ctx.fillText(String(i + 1), x, y);
            });

            const png = await new Promise((resolve) => canvas.toBlob(resolve, 'image/png'));

            {{-- Big retina PNGs can blow the upload limit; JPEG keeps them under it. --}}
            return png.size <= this.maxBytes
                ? png
                : await new Promise((resolve) => canvas.toBlob(resolve, 'image/jpeg', 0.85));
        },

        async done() {
            this.saving = true;

            const slot = this.slot;
            const blob = await this.flatten();
            const base = this.pending.name.replace(/\.[^.]+$/, '');
            const file = new File([blob], base + (blob.type === 'image/png' ? '.png' : '.jpg'), { type: blob.type });

            this.$wire.$set(`${this.notesModel}.${slot}`, this.draft.map((m) => m.note), false);

            this.$wire.upload(
                `${this.model}.${slot}`,
                file,
                () => {
                    const previous = this.originals[slot]?.image;

                    if (previous && previous !== this.pending) {
                        URL.revokeObjectURL(previous.url);
                    }

                    this.originals[slot] = { image: this.pending, markers: this.draft };
                    this.close();
                },
                () => this.cancel(),
                (event) => { this.progress = event.detail.progress; },
            );
        },

        remove(slot) {
            if (this.originals[slot]) {
                URL.revokeObjectURL(this.originals[slot].image.url);
                delete this.originals[slot];
            }

            this.$wire.$set(`${this.notesModel}.${slot}`, [], false);
            this.$wire.$set(`${this.model}.${slot}`, null);
        },
    }"
>
    <input
        type="file"
        x-ref="file"
        accept="image/*"
        multiple
        class="sr-only"
        tabindex="-1"
        x-on:change="pick($event.target.files); $event.target.value = ''"
    />

    {{--
        Focusable so a click then Cmd/Ctrl+V lands here; the paste bubbles to the
        form listener above. Clicking only focuses, so "browse" opens the picker.
    --}}
    @if (count($attached) < $max)
        <div
            tabindex="0"
            role="button"
            aria-label="Screenshots: paste, drop or browse for images"
            x-on:keydown.enter.prevent="$refs.file.click()"
            x-bind:class="dragging
                ? 'border-primary-500 bg-primary-50 dark:bg-primary-400/10'
                : 'border-gray-300 hover:border-gray-400 dark:border-white/15 dark:hover:border-white/25'"
            class="flex cursor-text flex-col items-center justify-center gap-1 rounded-lg border-2 border-dashed px-3 py-4 text-center outline-none transition focus:border-primary-500 focus:ring-1 focus:ring-primary-500"
        >
            <x-heroicon-o-photo class="h-6 w-6 text-gray-400" />
            <p class="text-sm text-gray-600 dark:text-gray-300">
                <span x-text="dragging ? 'Drop the images here' : 'Paste or drop screenshots'"></span>
            </p>
            <p class="text-xs text-gray-400">
                Click here and press <kbd class="font-sans">⌘V</kbd> / <kbd class="font-sans">Ctrl+V</kbd>, or
                <button
                    type="button"
                    x-on:click.stop="$refs.file.click()"
                    class="font-medium text-primary-600 hover:underline dark:text-primary-400"
                >browse</button>
                · up to {{ $max }}
            </p>
        </div>
    @endif

    <p x-show="progress !== null" x-cloak class="mt-1 text-xs text-gray-400">
        Uploading… <span x-text="progress"></span>%
    </p>

    @if ($attached !== [])
        <div class="mt-2 flex flex-wrap gap-3">
            @foreach ($attached as $slot => $file)
                <div wire:key="{{ $model }}-{{ $slot }}" data-screenshot-slot class="flex flex-col gap-1">
                    @if ($file->isPreviewable())
                        <img
                            src="{{ $file->temporaryUrl() }}"
                            alt="Screenshot {{ $loop->iteration }}"
                            class="h-20 w-auto max-w-[9rem] rounded border border-gray-200 object-cover dark:border-white/10"
                        />
                    @else
                        <span class="max-w-[9rem] truncate text-xs text-gray-500">{{ $file->getClientOriginalName() }}</span>
                    @endif
                    <div class="flex gap-2 text-xs font-medium">
                        <button type="button" x-show="originals[{{ $slot }}]" x-on:click="editMarks({{ $slot }})" class="text-primary-600 hover:underline dark:text-primary-400">
                            Edit marks
                        </button>
                        <button type="button" x-on:click="remove({{ $slot }})" class="text-gray-500 hover:text-danger-600 dark:text-gray-400">
                            Remove
                        </button>
                    </div>
                </div>
            @endforeach
        </div>
    @endif

    <template x-teleport="body">
        <div
            x-show="editing"
            x-cloak
            x-trap.noscroll="editing"
            x-on:keydown.escape.window="editing && ! saving && cancel()"
            data-help-screenshot-modal
            role="dialog"
            aria-modal="true"
            aria-label="Mark where it goes wrong"
            class="fixed inset-0 z-50 flex items-center justify-center bg-gray-950/50 p-4 print:hidden"
            style="display: none;"
        >
            <div class="flex max-h-full w-full max-w-5xl flex-col overflow-hidden rounded-xl bg-white shadow-2xl ring-1 ring-gray-950/10 dark:bg-gray-900 dark:ring-white/10">
                <div class="flex items-center justify-between gap-4 border-b border-gray-100 px-5 py-3 dark:border-white/10">
                    <div class="min-w-0">
                        <p class="text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">Mark where it goes wrong</p>
                        <p class="truncate text-base font-semibold text-gray-900 dark:text-white">
                            <span x-text="pending?.name"></span>
                            <span x-show="queue.length" class="text-sm font-normal text-gray-400" x-text="`· ${queue.length} more after this`"></span>
                        </p>
                    </div>
                    <div class="flex shrink-0 items-center gap-3">
                        <span class="hidden text-xs text-gray-400 sm:inline">Esc to close</span>
                        <button
                            type="button"
                            x-on:click="done()"
                            x-bind:disabled="saving"
                            class="rounded-lg bg-primary-600 px-4 py-2 text-sm font-semibold text-white shadow-sm transition hover:bg-primary-500 disabled:opacity-60"
                        >
                            <span x-text="saving ? 'Saving…' : 'Done'"></span>
                        </button>
                    </div>
                </div>

                <div class="flex flex-col gap-5 overflow-y-auto p-5 md:flex-row">
                    <div class="min-w-0 flex-1">
                        <div class="relative inline-block cursor-crosshair select-none" x-on:click="addMarker($event)">
                            <img
                                x-bind:src="pending?.url"
                                alt="Screenshot to mark"
                                draggable="false"
                                class="block max-h-[65vh] w-auto max-w-full rounded-lg border border-gray-200 dark:border-white/10"
                            />
                            <template x-for="(marker, index) in draft" :key="marker.id">
                                <span
                                    x-on:click.stop
                                    x-bind:style="`left: ${marker.x}%; top: ${marker.y}%`"
                                    x-text="index + 1"
                                    class="absolute flex h-7 w-7 -translate-x-1/2 -translate-y-1/2 cursor-default items-center justify-center rounded-full border-2 border-white bg-red-600 text-xs font-bold text-white shadow"
                                ></span>
                            </template>
                        </div>
                        <p class="mt-2 text-xs text-gray-500 dark:text-gray-400">Click anywhere on the screenshot to add a marker.</p>
                    </div>

                    <div class="shrink-0 md:w-72" x-ref="notes">
                        <p class="mb-2 text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">Where it goes wrong</p>
                        <p x-show="draft.length === 0" class="text-sm text-gray-400">No markers yet.</p>
                        <div class="space-y-2">
                            <template x-for="(marker, index) in draft" :key="marker.id">
                                <div class="flex items-start gap-2 rounded-lg border border-gray-200 p-2 dark:border-white/10">
                                    <span
                                        x-text="index + 1"
                                        class="mt-1 flex h-6 w-6 shrink-0 items-center justify-center rounded-full bg-red-600 text-xs font-bold text-white"
                                    ></span>
                                    <textarea
                                        x-model="marker.note"
                                        rows="2"
                                        maxlength="500"
                                        x-bind:aria-label="`Note for marker ${index + 1}`"
                                        placeholder="What's wrong here?"
                                        class="block w-full resize-y border-0 bg-transparent p-1 text-sm text-gray-900 outline-none placeholder:text-gray-400 focus:ring-0 dark:text-white"
                                    ></textarea>
                                    <button
                                        type="button"
                                        x-on:click="draft.splice(index, 1)"
                                        x-bind:aria-label="`Remove marker ${index + 1}`"
                                        class="mt-1 text-gray-400 hover:text-danger-600"
                                    >
                                        <x-heroicon-m-x-mark class="h-4 w-4" />
                                    </button>
                                </div>
                            </template>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </template>
</div>
