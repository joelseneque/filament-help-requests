<?php

namespace Joelseneque\HelpRequests\Console\Commands;

use Illuminate\Console\Command;
use Joelseneque\HelpRequests\Models\HelpRequest;
use Joelseneque\HelpRequests\Models\HelpRequestSetting;
use Joelseneque\HelpRequests\Services\GitHubIssueService;

/**
 * Safety net for the GitHub webhook — polls every linked issue so status changes
 * are still picked up if a webhook delivery is missed or was never configured.
 */
class SyncGitHubIssuesCommand extends Command
{
    protected $signature = 'help-requests:sync-github {--all : Also re-check requests already resolved or closed}';

    protected $description = 'Sync help request statuses with their linked GitHub issues';

    public function handle(GitHubIssueService $github): int
    {
        $settings = HelpRequestSetting::query()->first();

        if (! $settings?->isGithubConnected()) {
            $this->info('GitHub is not configured — nothing to sync.');

            return Command::SUCCESS;
        }

        $requests = HelpRequest::query()
            ->whereNotNull('github_issue_number')
            ->unless($this->option('all'), fn ($query) => $query->whereIn('status', ['open', 'in_progress']))
            ->get();

        if ($requests->isEmpty()) {
            $this->info('No linked help requests to sync.');

            return Command::SUCCESS;
        }

        $synced = 0;
        $failed = 0;

        foreach ($requests as $request) {
            $result = $github->syncFromGithub($request);

            if ($result['ok']) {
                $synced++;

                continue;
            }

            $failed++;
            $this->warn("Help request #{$request->id}: {$result['message']}");
        }

        $settings->update(['github_last_synced_at' => now()]);

        $this->info("Synced {$synced} help request(s)".($failed > 0 ? ", {$failed} failed." : '.'));

        return $failed > 0 ? Command::FAILURE : Command::SUCCESS;
    }
}
