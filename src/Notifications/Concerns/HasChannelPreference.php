<?php

namespace Joelseneque\HelpRequests\Notifications\Concerns;

/**
 * Lets a custom delivery hook send the same notification down one channel at
 * a time — e.g. always in-app, but mail only when the user wants it.
 */
trait HasChannelPreference
{
    /** @var array<string>|null */
    protected ?array $forcedChannels = null;

    /**
     * @param  array<string>  $channels
     */
    public function onlyVia(array $channels): static
    {
        $this->forcedChannels = $channels;

        return $this;
    }

    /**
     * @return array<string>
     */
    protected function resolveChannels(): array
    {
        return $this->forcedChannels ?? config('help-requests.notifications.channels', ['database', 'mail']);
    }
}
