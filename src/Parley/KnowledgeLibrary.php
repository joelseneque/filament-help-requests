<?php

namespace Joelseneque\HelpRequests\Parley;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Symfony\Component\Finder\Finder;

/**
 * The help library Parley answers from: markdown files in the app's own
 * repository (by default `resources/help`), written alongside the code they
 * describe and deployed with it.
 *
 * Files whose name starts with an underscore (`_about.md`, `_glossary.md`)
 * are the primer — what the app is, who uses it, the words it uses — and are
 * read on every question. Every other `.md` file is an article.
 */
class KnowledgeLibrary
{
    /**
     * @var Collection<int, KnowledgeArticle>|null
     */
    protected ?Collection $articles = null;

    protected ?string $primer = null;

    /**
     * @param  list<string>  $paths
     */
    public function __construct(protected array $paths) {}

    public static function make(): static
    {
        return new static(Parley::knowledgePaths());
    }

    /**
     * Every article, in a stable order so the prompt built from them stays
     * the same from one question to the next and can be cached.
     *
     * @return Collection<int, KnowledgeArticle>
     */
    public function articles(): Collection
    {
        if ($this->articles === null) {
            $this->load();
        }

        return $this->articles;
    }

    /**
     * @return Collection<int, KnowledgeArticle>
     */
    public function readableBy(Authenticatable $user): Collection
    {
        return $this->articles()->filter(fn (KnowledgeArticle $article): bool => $article->isReadableBy($user))->values();
    }

    public function find(string $slug, Authenticatable $user): ?KnowledgeArticle
    {
        return $this->readableBy($user)->first(fn (KnowledgeArticle $article): bool => $article->slug === trim($slug, '/'));
    }

    /**
     * @return Collection<int, KnowledgeArticle>
     */
    public function aboutPath(string $path, Authenticatable $user): Collection
    {
        return $this->readableBy($user)->filter(fn (KnowledgeArticle $article): bool => $article->isAboutPath($path))->values();
    }

    public function primer(): string
    {
        if ($this->primer === null) {
            $this->load();
        }

        return $this->primer;
    }

    public function isEmpty(): bool
    {
        return $this->articles()->isEmpty() && $this->primer() === '';
    }

    protected function load(): void
    {
        $articles = [];
        $primer = [];

        foreach ($this->paths as $path) {
            if (! is_dir($path)) {
                continue;
            }

            $files = Finder::create()->files()->in($path)->name('*.md')->sortByName();

            foreach ($files as $file) {
                $relative = str_replace('\\', '/', $file->getRelativePathname());
                $contents = (string) file_get_contents($file->getPathname());

                if (str_starts_with($file->getFilename(), '_')) {
                    $primer[] = trim(static::parse($contents)['body']);

                    continue;
                }

                $articles[] = static::article(Str::beforeLast($relative, '.md'), $contents);
            }
        }

        $this->articles = collect($articles);
        $this->primer = trim(implode("\n\n", array_filter($primer)));
    }

    public static function article(string $slug, string $contents): KnowledgeArticle
    {
        ['meta' => $meta, 'body' => $body] = static::parse($contents);

        $title = $meta['title'] ?? null;

        if (blank($title) && preg_match('/^#\s+(.+)$/m', $body, $heading)) {
            $title = trim($heading[1]);
        }

        $summary = $meta['summary'] ?? null;

        if (blank($summary)) {
            $paragraph = collect(preg_split('/\n\s*\n/', $body))
                ->map(fn (string $block): string => trim($block))
                ->first(fn (string $block): bool => $block !== '' && ! str_starts_with($block, '#'));

            $summary = Str::limit(preg_replace('/\s+/', ' ', (string) $paragraph), 160);
        }

        return new KnowledgeArticle(
            slug: $slug,
            title: filled($title) ? (string) $title : Str::headline(Str::afterLast($slug, '/')),
            summary: (string) $summary,
            body: $body,
            pages: static::listOf($meta['pages'] ?? []),
            abilities: static::listOf($meta['can'] ?? []),
        );
    }

    /**
     * Split front matter from the body. The front matter is a small subset of
     * YAML — `key: value`, `key: [a, b]`, or a key followed by `- item` lines —
     * which is all an article needs, without a YAML dependency.
     *
     * @return array{meta: array<string, string|list<string>>, body: string}
     */
    public static function parse(string $contents): array
    {
        $contents = str_replace("\r\n", "\n", $contents);

        if (! preg_match('/\A---\n(.*?)\n---\n?(.*)\z/s', $contents, $matches)) {
            return ['meta' => [], 'body' => $contents];
        }

        $meta = [];
        $key = null;

        foreach (explode("\n", $matches[1]) as $line) {
            if (preg_match('/^\s*-\s+(.*)$/', $line, $item) && $key !== null) {
                $meta[$key] = [...(array) ($meta[$key] ?? []), static::scalar($item[1])];

                continue;
            }

            if (! preg_match('/^([A-Za-z_][\w-]*)\s*:\s*(.*)$/', $line, $pair)) {
                continue;
            }

            $key = $pair[1];
            $value = trim($pair[2]);

            if ($value === '') {
                $meta[$key] = [];
            } elseif (str_starts_with($value, '[') && str_ends_with($value, ']')) {
                $meta[$key] = array_values(array_filter(array_map(
                    static::scalar(...),
                    explode(',', substr($value, 1, -1)),
                ), fn (string $part): bool => $part !== ''));
            } else {
                $meta[$key] = static::scalar($value);
            }
        }

        return ['meta' => $meta, 'body' => $matches[2]];
    }

    protected static function scalar(string $value): string
    {
        return trim(trim($value), '"\'');
    }

    /**
     * @param  string|list<string>  $value
     * @return list<string>
     */
    protected static function listOf(string|array $value): array
    {
        return array_values(array_filter((array) $value, fn (string $item): bool => $item !== ''));
    }
}
