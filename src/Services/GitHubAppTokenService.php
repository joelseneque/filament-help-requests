<?php

namespace Joelseneque\HelpRequests\Services;

use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Joelseneque\HelpRequests\Models\HelpRequestSetting;

/**
 * Authenticates as the configured GitHub App.
 *
 * There are two credentials in play. The *app JWT* is signed locally with the
 * App's private key and only proves "I am this app" — it is used to read the
 * installation and to mint the second credential. The *installation token* is
 * what actually reads and writes issues; it lives for an hour and is minted
 * fresh from the private key, so unlike a classic OAuth refresh token there is
 * no rotating secret that can be lost or invalidated by a concurrent refresh.
 */
class GitHubAppTokenService
{
    protected const LOCK_KEY = 'github-installation-token';

    public function isConfigured(): bool
    {
        return filled(config('help-requests.github.app_id')) && filled($this->privateKey());
    }

    /**
     * A short-lived JWT proving this is the configured GitHub App.
     *
     * GitHub rejects tokens issued in the future, so `iat` is backdated by a
     * minute to absorb clock drift between this server and GitHub.
     */
    public function appJwt(): string
    {
        $privateKey = $this->privateKey();

        if (blank($privateKey) || blank(config('help-requests.github.app_id'))) {
            throw new \RuntimeException('GitHub App credentials are not configured.');
        }

        $header = $this->base64UrlEncode((string) json_encode(['alg' => 'RS256', 'typ' => 'JWT']));
        $payload = $this->base64UrlEncode((string) json_encode([
            'iat' => now()->subMinute()->timestamp,
            'exp' => now()->addMinutes(9)->timestamp,
            'iss' => (string) config('help-requests.github.app_id'),
        ]));

        $key = openssl_pkey_get_private($privateKey);

        if ($key === false) {
            throw new \RuntimeException('The GitHub App private key could not be read. Check GITHUB_APP_PRIVATE_KEY.');
        }

        if (! openssl_sign("{$header}.{$payload}", $signature, $key, OPENSSL_ALGO_SHA256)) {
            throw new \RuntimeException('Could not sign the GitHub App token.');
        }

        return "{$header}.{$payload}.".$this->base64UrlEncode($signature);
    }

    /**
     * A valid installation token, minted only when the cached one has expired.
     */
    public function installationToken(HelpRequestSetting $settings): string
    {
        if (! $settings->isGithubInstalled()) {
            throw new \RuntimeException('The GitHub App is not installed.');
        }

        if (! $settings->isGithubInstallationTokenExpired()) {
            return $settings->github_installation_token;
        }

        $lock = Cache::lock(self::LOCK_KEY, 30);

        try {
            $lock->block(15);
        } catch (LockTimeoutException) {
            $settings->refresh();

            if (! $settings->isGithubInstallationTokenExpired()) {
                return $settings->github_installation_token;
            }

            throw new \RuntimeException('Timed out waiting for another GitHub token refresh to finish.');
        }

        try {
            // Another process may have minted a token while we waited.
            $settings->refresh();

            if (! $settings->isGithubInstallationTokenExpired()) {
                return $settings->github_installation_token;
            }

            return $this->mintInstallationToken($settings);
        } finally {
            $lock->release();
        }
    }

    /**
     * Read the installation itself, which is how the account name and repository
     * selection are discovered after the user completes the install flow.
     *
     * @return array<string, mixed>|null
     */
    public function fetchInstallation(int $installationId): ?array
    {
        $response = $this->appClient()->get("/app/installations/{$installationId}");

        if ($response->failed()) {
            Log::error('Could not read GitHub App installation', [
                'installation_id' => $installationId,
                'status' => $response->status(),
                'body' => $response->json(),
            ]);

            return null;
        }

        return $response->json();
    }

    public function markInstallationFailed(HelpRequestSetting $settings): void
    {
        $settings->update([
            'github_installation_failed_at' => now(),
            'github_installation_token' => null,
            'github_installation_token_expires_at' => null,
        ]);
    }

    protected function mintInstallationToken(HelpRequestSetting $settings): string
    {
        $response = $this->appClient()
            ->post("/app/installations/{$settings->github_installation_id}/access_tokens");

        if ($response->failed()) {
            Log::error('GitHub installation token request failed', [
                'installation_id' => $settings->github_installation_id,
                'status' => $response->status(),
                'body' => $response->json(),
            ]);

            // A 401/404 here means the installation was removed or suspended in
            // GitHub — no amount of retrying will fix it, so flag a reconnect.
            if (in_array($response->status(), [401, 404], true)) {
                $this->markInstallationFailed($settings);
            }

            throw new \RuntimeException('Could not obtain a GitHub installation token: '.($response->json('message') ?? $response->status()));
        }

        $token = $response->json('token');

        if (blank($token)) {
            Log::error('GitHub returned an installation token response with no token', [
                'installation_id' => $settings->github_installation_id,
            ]);

            throw new \RuntimeException('GitHub returned no installation token.');
        }

        $settings->update([
            'github_installation_token' => $token,
            'github_installation_token_expires_at' => $response->json('expires_at'),
            'github_installation_failed_at' => null,
        ]);

        return $token;
    }

    protected function appClient(): PendingRequest
    {
        return Http::withToken($this->appJwt())
            ->baseUrl(rtrim((string) config('help-requests.github.api_url'), '/'))
            ->withHeaders([
                'Accept' => 'application/vnd.github+json',
                'X-GitHub-Api-Version' => '2022-11-28',
            ])
            ->timeout(20);
    }

    /**
     * The private key, accepted as raw PEM, a base64-encoded PEM, or a path to a
     * .pem file — multi-line values are awkward in a .env, so all three are
     * allowed and the caller need not care which was used.
     */
    protected function privateKey(): ?string
    {
        $key = config('help-requests.github.private_key');

        if (blank($key)) {
            return null;
        }

        $key = trim((string) $key);

        if (str_contains($key, 'BEGIN')) {
            return str_replace('\n', "\n", $key);
        }

        // A relative path must be resolved against the project root — the web
        // server's working directory is `public/`, so `is_file()` alone would
        // find the key from the CLI but not from a request.
        $path = str_starts_with($key, '/') ? $key : base_path($key);

        if (is_file($path) && is_readable($path)) {
            return (string) file_get_contents($path);
        }

        $decoded = base64_decode($key, true);

        return $decoded !== false && str_contains($decoded, 'BEGIN') ? $decoded : null;
    }

    protected function base64UrlEncode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }
}
