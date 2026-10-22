<?php

namespace Joelseneque\HelpRequests\Parley;

use Closure;
use Illuminate\Contracts\Auth\Authenticatable;
use Joelseneque\HelpRequests\HelpRequests;
use Joelseneque\HelpRequests\Parley\Contracts\ParleyTool;

/**
 * App-wide settings for Parley, the help chat in the widget.
 *
 * Static for the same reason as {@see HelpRequests}: the plugin writes
 * these when the panel boots, and the Livewire requests that ask Parley a
 * question read them back.
 */
class Parley
{
    protected static ?bool $enabled = null;

    protected static ?string $name = null;

    /**
     * @var list<string>|null
     */
    protected static ?array $knowledgePaths = null;

    protected static ?Closure $describeUserUsing = null;

    protected static string|Closure|null $timezone = null;

    /**
     * @var list<class-string<ParleyTool>|ParleyTool>
     */
    protected static array $tools = [];

    public static function enable(?bool $enabled): void
    {
        static::$enabled = $enabled;
    }

    /**
     * Switched on, and able to answer: without an API key the chat tab is not
     * drawn at all, rather than drawn and failing on the first question.
     */
    public static function isEnabled(): bool
    {
        return (static::$enabled ?? (bool) config('help-requests.parley.enabled', true))
            && filled(config('help-requests.parley.api_key'));
    }

    public static function useName(?string $name): void
    {
        static::$name = $name;
    }

    public static function name(): string
    {
        return static::$name ?? (string) config('help-requests.parley.name', 'Parley');
    }

    /**
     * @param  list<string>|null  $paths
     */
    public static function useKnowledgePaths(?array $paths): void
    {
        static::$knowledgePaths = $paths === null ? null : array_values($paths);
    }

    /**
     * The folders the help library is read from.
     *
     * @return list<string>
     */
    public static function knowledgePaths(): array
    {
        return static::$knowledgePaths ?? array_values(array_filter((array) config('help-requests.parley.knowledge_paths', [])));
    }

    /**
     * @param  Closure(Authenticatable $user): string|null  $callback
     */
    public static function describeUserUsing(?Closure $callback): void
    {
        static::$describeUserUsing = $callback;
    }

    /**
     * Who is asking, in a line or two the model reads before the question: an
     * answer for an approver is not the answer for a technician.
     */
    public static function describeUser(Authenticatable $user): string
    {
        if (static::$describeUserUsing) {
            return (string) (static::$describeUserUsing)($user);
        }

        $description = 'Name: '.HelpRequests::userName($user);

        if (method_exists($user, 'getRoleNames')) {
            $roles = collect($user->getRoleNames())->map(fn (string $role): string => str_replace('_', ' ', $role));

            $description .= "\nRoles: ".($roles->isEmpty() ? 'none' : $roles->implode(', '));
        }

        return $description;
    }

    /**
     * @param  string|Closure(): string|null  $timezone
     */
    public static function useTimezone(string|Closure|null $timezone): void
    {
        static::$timezone = $timezone;
    }

    /**
     * The clock that decides when a day's chat ends and how the list of chats
     * is grouped: the plugin's, then the config's, then the app's.
     */
    public static function timezone(): string
    {
        $timezone = static::$timezone instanceof Closure ? (static::$timezone)() : static::$timezone;

        return (string) ($timezone ?: config('help-requests.parley.timezone') ?: config('app.timezone', 'UTC'));
    }

    /**
     * @param  list<class-string<ParleyTool>|ParleyTool>  $tools
     */
    public static function useTools(array $tools): void
    {
        static::$tools = array_values($tools);
    }

    /**
     * @return list<ParleyTool>
     */
    public static function tools(): array
    {
        return array_map(
            fn (string|ParleyTool $tool): ParleyTool => is_string($tool) ? app($tool) : $tool,
            static::$tools,
        );
    }

    /**
     * Restore defaults. Used between tests.
     */
    public static function flush(): void
    {
        static::$enabled = null;
        static::$name = null;
        static::$knowledgePaths = null;
        static::$describeUserUsing = null;
        static::$timezone = null;
        static::$tools = [];
    }
}
