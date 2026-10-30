<?php

namespace Joelseneque\HelpRequests\Parley;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Str;

/**
 * One page of the help library: a markdown file, with optional front matter
 * saying what it is called, which pages it is about and who may read it.
 */
final class KnowledgeArticle
{
    /**
     * @param  list<string>  $pages  URL path patterns (`*` wildcards) the article is about.
     * @param  list<string>  $abilities  The reader needs any one of these; none means everyone.
     */
    public function __construct(
        public readonly string $slug,
        public readonly string $title,
        public readonly string $summary,
        public readonly string $body,
        public readonly array $pages = [],
        public readonly array $abilities = [],
    ) {}

    public function isReadableBy(Authenticatable $user): bool
    {
        if ($this->abilities === []) {
            return true;
        }

        if (! method_exists($user, 'can')) {
            return false;
        }

        foreach ($this->abilities as $ability) {
            if ($user->can($ability)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether the article is about the page at this URL path. Patterns are
     * matched against the path without its leading slash.
     */
    public function isAboutPath(string $path): bool
    {
        $path = trim($path, '/');

        foreach ($this->pages as $pattern) {
            if (Str::is(trim($pattern, '/'), $path)) {
                return true;
            }
        }

        return false;
    }

    public function toPrompt(): string
    {
        return "<article slug=\"{$this->slug}\" title=\"".e($this->title)."\">\n".trim($this->body)."\n</article>";
    }
}
