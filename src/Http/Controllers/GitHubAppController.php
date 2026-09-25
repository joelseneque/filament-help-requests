<?php

namespace Joelseneque\HelpRequests\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Joelseneque\HelpRequests\Filament\Pages\HelpRequestSettings;
use Joelseneque\HelpRequests\HelpRequests;
use Joelseneque\HelpRequests\Models\HelpRequestSetting;
use Joelseneque\HelpRequests\Services\GitHubAppTokenService;
use Joelseneque\HelpRequests\Services\GitHubIssueService;

/**
 * Drives the GitHub App install flow.
 *
 * Unlike a classic OAuth handshake there is no code-for-token exchange — GitHub
 * hosts the repository picker itself and hands back an installation id, which is
 * all the app needs to mint tokens from its private key thereafter.
 */
class GitHubAppController extends Controller
{
    public function connect(GitHubAppTokenService $tokens): RedirectResponse
    {
        if (! $tokens->isConfigured() || blank(config('help-requests.github.app_slug'))) {
            return redirect($this->settingsUrl())
                ->with('error', 'Please configure GITHUB_APP_ID, GITHUB_APP_SLUG and GITHUB_APP_PRIVATE_KEY in your .env file.');
        }

        $state = Str::random(40);

        session(['github_install_state' => $state]);

        return redirect()->away(sprintf(
            'https://github.com/apps/%s/installations/new?state=%s',
            config('help-requests.github.app_slug'),
            urlencode($state),
        ));
    }

    public function callback(Request $request, GitHubAppTokenService $tokens): RedirectResponse
    {
        $expectedState = session()->pull('github_install_state');

        if (blank($request->query('state')) || $request->query('state') !== $expectedState) {
            // Almost always a host mismatch: the App's Setup URL points at a
            // different hostname than the one the flow started on, so the
            // session cookie never reaches this request.
            Log::warning('GitHub install callback rejected: state mismatch', [
                'callback_host' => $request->getHost(),
                'expected_host' => parse_url((string) config('app.url'), PHP_URL_HOST),
                'state_present' => filled($request->query('state')),
                'session_state_present' => filled($expectedState),
            ]);

            return redirect($this->settingsUrl())
                ->with('error', 'Invalid state parameter. Check the GitHub App\'s Setup URL points at '.route('help-requests.github.callback').' and start the connection from the settings page.');
        }

        // The org owner has to approve the install before it exists.
        if ($request->query('setup_action') === 'request') {
            return redirect($this->settingsUrl())
                ->with('warning', 'Your installation request was sent to the organisation owner. Connect again once it has been approved.');
        }

        $installationId = (int) $request->query('installation_id');

        if ($installationId <= 0) {
            return redirect($this->settingsUrl())
                ->with('error', 'GitHub did not return an installation. Please try connecting again.');
        }

        $installation = $tokens->fetchInstallation($installationId);

        if ($installation === null) {
            return redirect($this->settingsUrl())
                ->with('error', 'Could not read the GitHub installation. Check the app credentials and try again.');
        }

        $settings = HelpRequestSetting::current();

        $settings->update([
            'github_installation_id' => $installationId,
            'github_account_login' => $installation['account']['login'] ?? null,
            // Any token minted for a previous installation is now meaningless.
            'github_installation_token' => null,
            'github_installation_token_expires_at' => null,
            'github_installation_failed_at' => null,
            'github_enabled' => true,
        ]);

        // A repository chosen under an earlier installation may no longer be
        // included in the new selection, so only keep it if it still resolves.
        if (filled($settings->github_repository)) {
            $available = app(GitHubIssueService::class)->availableRepositories();

            if (! isset($available[$settings->githubRepository()])) {
                $settings->update(['github_repository' => null]);
            }
        }

        $account = $settings->github_account_login ? " to {$settings->github_account_login}" : '';

        return redirect($this->settingsUrl())
            ->with('success', "GitHub connected{$account}. Choose the repository help requests should be raised in.");
    }

    /**
     * The settings page lives in a Filament panel, but these routes run outside
     * one — so the panel is named explicitly.
     */
    protected function settingsUrl(): string
    {
        return HelpRequestSettings::getUrl(panel: HelpRequests::panelId());
    }
}
