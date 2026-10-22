<?php

namespace Joelseneque\HelpRequests\Parley;

use Filament\Facades\Filament;
use Filament\Navigation\NavigationGroup;
use Filament\Navigation\NavigationItem;

/**
 * The screens the person asking can open, read off the panel's own
 * navigation — which has already been filtered by what they may access —
 * so Parley can say where something is and link to it without the app
 * describing its menus a second time.
 */
class PanelScreens
{
    /**
     * @return list<array{label: string, url: string}>
     */
    public static function list(): array
    {
        try {
            $navigation = Filament::getNavigation();
        } catch (\Throwable) {
            return [];
        }

        $screens = [];

        foreach ($navigation as $group) {
            if (! $group instanceof NavigationGroup) {
                continue;
            }

            foreach ($group->getItems() as $item) {
                static::collect($item, filled($group->getLabel()) ? [(string) $group->getLabel()] : [], $screens);
            }
        }

        return $screens;
    }

    /**
     * @param  list<string>  $trail
     * @param  list<array{label: string, url: string}>  $screens
     */
    protected static function collect(NavigationItem $item, array $trail, array &$screens): void
    {
        if ($item->isHidden()) {
            return;
        }

        $trail[] = $item->getLabel();

        if (filled($url = $item->getUrl())) {
            $screens[] = ['label' => implode(' › ', $trail), 'url' => $url];
        }

        foreach ($item->getChildItems() as $child) {
            static::collect($child, $trail, $screens);
        }
    }

    public static function toPrompt(): string
    {
        $screens = static::list();

        if ($screens === []) {
            return '';
        }

        return collect($screens)
            ->map(fn (array $screen): string => "- {$screen['label']}: {$screen['url']}")
            ->implode("\n");
    }
}
