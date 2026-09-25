<div class="flex flex-wrap items-center gap-3">
    @if ($configured)
        @if ($record && $record->isGithubInstalled())
            <x-filament::button
                tag="a"
                :href="route('help-requests.github.connect')"
                :color="$record->githubNeedsReconnect() ? 'primary' : 'gray'"
                :outlined="! $record->githubNeedsReconnect()"
                size="sm"
            >
                {{ $record->githubNeedsReconnect() ? 'Reconnect to GitHub' : 'Configure repositories' }}
            </x-filament::button>

            <x-filament::button
                wire:click="disconnectGithub"
                color="danger"
                size="sm"
            >
                Disconnect
            </x-filament::button>
        @else
            <x-filament::button
                tag="a"
                :href="route('help-requests.github.connect')"
                size="sm"
            >
                Connect to GitHub
            </x-filament::button>
        @endif

        <p class="text-sm text-gray-600 dark:text-gray-400">
            @if ($record && $record->isGithubInstalled() && ! $record->githubNeedsReconnect())
                GitHub hosts the repository picker — use Configure repositories to change which repositories {{ $appName }} can raise issues in. Access tokens are issued automatically and never need renewing by hand.
            @else
                You'll be taken to GitHub to install the app and choose which repositories it can access.
            @endif
        </p>
    @else
        <p class="text-sm text-gray-600 dark:text-gray-400">
            Please add GITHUB_APP_ID, GITHUB_APP_SLUG and GITHUB_APP_PRIVATE_KEY to your .env file.
        </p>
    @endif
</div>
