<?php

namespace Joelseneque\HelpRequests\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Joelseneque\HelpRequests\Models\HelpRequestSetting;
use Joelseneque\HelpRequests\Services\GitHubAppTokenService;
use Joelseneque\HelpRequests\Services\GitHubIssueService;

/**
 * Reports why the GitHub help desk integration is or is not working.
 *
 * The integration depends on environment credentials, per-database settings and
 * a running queue worker, so a failure on one server and not another is common.
 * This checks all three in one place rather than requiring a tinker session.
 */
class GitHubIntegrationStatusCommand extends Command
{
    protected $signature = 'help-requests:github-status';

    protected $description = 'Diagnose the GitHub help request integration on this environment';

    public function handle(GitHubAppTokenService $tokens, GitHubIssueService $github): int
    {
        $ok = true;

        $this->components->info('Environment credentials');
        $ok = $this->check('GITHUB_APP_ID', filled(config('help-requests.github.app_id'))) && $ok;
        $ok = $this->check('GITHUB_APP_SLUG', filled(config('help-requests.github.app_slug'))) && $ok;
        $ok = $this->check('GITHUB_APP_WEBHOOK_SECRET', filled(config('help-requests.github.webhook_secret'))) && $ok;

        $keyReadable = $tokens->isConfigured();
        $ok = $this->check(
            'GITHUB_APP_PRIVATE_KEY readable',
            $keyReadable,
            'Set, but unreadable — if it is a file path, the file must exist on THIS server. Paste the base64 of the .pem instead.'
        ) && $ok;

        if ($keyReadable) {
            try {
                $tokens->appJwt();
                $this->check('Private key signs a JWT', true);
            } catch (\Throwable $e) {
                $ok = $this->check('Private key signs a JWT', false, $e->getMessage()) && $ok;
            }
        }

        $this->newLine();
        $this->components->info('Settings in this database');

        $settings = HelpRequestSetting::query()->first();

        if (! $settings) {
            $this->check('Help request settings record', false, 'No help_request_settings row exists — open the settings page once.');

            return Command::FAILURE;
        }

        $ok = $this->check(
            'GitHub App installed',
            $settings->isGithubInstalled(),
            'Connect the app from the Help Request settings page. This is per-database, so connecting locally does not connect production.'
        ) && $ok;
        $ok = $this->check('Repository selected', filled($settings->github_repository), 'No repository chosen.') && $ok;
        $ok = $this->check('Integration enabled', (bool) $settings->github_enabled) && $ok;
        $ok = $this->check(
            'Auto-create enabled',
            (bool) $settings->github_auto_create,
            'Issues will only be created by the button on a help request.'
        ) && $ok;
        $ok = $this->check(
            'Installation healthy',
            ! $settings->githubNeedsReconnect(),
            'The installation was removed or suspended in GitHub — reconnect.'
        ) && $ok;

        if ($settings->isGithubInstalled()) {
            $this->line("  installation: {$settings->github_installation_id} ({$settings->github_account_login})");
            $this->line('  repository:   '.($settings->github_repository ?? '—'));
        }

        $this->newLine();
        $this->components->info('Queue');

        $this->line('  connection: '.config('queue.default'));

        if (config('queue.default') === 'database') {
            $pending = DB::table('jobs')->count();
            $this->line("  pending jobs: {$pending}");

            if ($pending > 20) {
                $this->warn('  A large backlog suggests no worker is processing the queue.');
            }
        }

        $this->newLine();
        $this->components->info('Live GitHub check');

        if (! $github->isConfigured()) {
            $this->check('Connection', false, 'Skipped — resolve the failures above first.');

            return Command::FAILURE;
        }

        $result = $github->testConnection();
        $ok = $this->check('Connection', $result['ok'], $result['message']) && $ok;

        if ($result['ok']) {
            $this->line('  '.$result['message']);
        }

        $this->newLine();

        if ($ok) {
            $this->components->info('GitHub integration is fully configured on this environment.');

            return Command::SUCCESS;
        }

        $this->components->error('The GitHub integration is not fully configured — see the failures above.');

        return Command::FAILURE;
    }

    protected function check(string $label, bool $passed, ?string $hint = null): bool
    {
        $this->line(sprintf('  %s %s', $passed ? '<fg=green>✔</>' : '<fg=red>✘</>', $label));

        if (! $passed && $hint) {
            $this->line("      <fg=yellow>{$hint}</>");
        }

        return $passed;
    }
}
