<?php

namespace Joelseneque\HelpRequests\Console\Commands;

use Illuminate\Console\Command;
use Joelseneque\HelpRequests\Enums\HelpRequestStatus;
use Joelseneque\HelpRequests\HelpRequests;
use Joelseneque\HelpRequests\Models\HelpRequest;
use Joelseneque\HelpRequests\Parley\KnowledgeArticle;
use Joelseneque\HelpRequests\Parley\KnowledgeLibrary;
use Joelseneque\HelpRequests\Parley\Parley;

/**
 * What Parley answers from, and what it has been asked that it could not:
 * the help library as the model will read it, and the open gaps waiting for
 * an article.
 */
class ParleyLibraryCommand extends Command
{
    protected $signature = 'help-requests:parley';

    protected $description = 'List the Parley help library and the questions it could not answer';

    public function handle(): int
    {
        $library = KnowledgeLibrary::make();

        $this->components->twoColumnDetail('Chat', Parley::isEnabled() ? '<fg=green>on</> ('.config('help-requests.parley.model').')' : '<fg=yellow>off</> (switched off, or no API key)');

        foreach (Parley::knowledgePaths() as $path) {
            $this->components->twoColumnDetail('Library', $path.(is_dir($path) ? '' : ' <fg=red>(missing)</>'));
        }

        $this->components->twoColumnDetail('Primer', $library->primer() === '' ? '<fg=yellow>none — add an _about.md</>' : number_format(strlen($library->primer())).' characters');

        $articles = $library->articles();
        $size = $articles->sum(fn (KnowledgeArticle $article): int => strlen($article->toPrompt()));

        $this->components->twoColumnDetail('Articles', $articles->count().' ('.number_format($size / 1024, 1).' KB, '.config('help-requests.parley.inline_kb').' KB inline)');

        if ($articles->isNotEmpty()) {
            $this->newLine();
            $this->table(
                ['Slug', 'Title', 'Pages', 'Who can read'],
                $articles->map(fn (KnowledgeArticle $article): array => [
                    $article->slug,
                    $article->title,
                    implode(', ', $article->pages) ?: '—',
                    implode(', ', $article->abilities) ?: 'everyone',
                ])->all(),
            );
        }

        $gaps = HelpRequest::query()
            ->where('is_internal', true)
            ->where('category', HelpRequests::HELP_ARTICLE_CATEGORY)
            ->whereIn('status', [HelpRequestStatus::Open, HelpRequestStatus::InProgress])
            ->latest()
            ->get();

        $this->newLine();
        $this->components->info($gaps->count().' unanswered '.str('question')->plural($gaps->count()).' waiting for an article.');

        foreach ($gaps->take(20) as $gap) {
            $this->line('  #'.$gap->id.' '.str($gap->comment)->after('Question: ')->before("\n")->limit(100));
        }

        return self::SUCCESS;
    }
}
