@php($embedUrl = $getRecord()->videoEmbedUrl())

<div class="space-y-2">
    @if ($embedUrl)
        <div class="relative w-full overflow-hidden rounded-lg ring-1 ring-gray-950/10 dark:ring-white/10" style="padding-top: 56.25%;">
            <iframe
                src="{{ $embedUrl }}"
                class="absolute inset-0 h-full w-full"
                frameborder="0"
                allow="fullscreen; picture-in-picture"
                allowfullscreen
                loading="lazy"
                title="Screen recording"
            ></iframe>
        </div>
    @endif

    <x-filament::link :href="$getRecord()->video_url" target="_blank" rel="noopener" icon="heroicon-m-arrow-top-right-on-square" icon-position="after">
        Open in {{ $getRecord()->isLoomVideo() ? 'Loom' : 'a new tab' }}
    </x-filament::link>
</div>
