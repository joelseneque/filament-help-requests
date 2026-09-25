<?php

namespace Joelseneque\HelpRequests\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Singleton holding this database's GitHub connection.
 *
 * @property bool $github_enabled
 * @property string|null $github_repository
 * @property int|null $github_installation_id
 * @property string|null $github_account_login
 * @property string|null $github_installation_token
 * @property Carbon|null $github_installation_token_expires_at
 * @property Carbon|null $github_installation_failed_at
 * @property array<int, string>|null $github_default_labels
 * @property bool $github_auto_create
 * @property Carbon|null $github_last_synced_at
 */
class HelpRequestSetting extends Model
{
    protected $fillable = [
        'github_enabled',
        'github_repository',
        'github_installation_id',
        'github_account_login',
        'github_installation_token',
        'github_installation_token_expires_at',
        'github_installation_failed_at',
        'github_default_labels',
        'github_auto_create',
        'github_last_synced_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'github_enabled' => 'boolean',
            'github_auto_create' => 'boolean',
            'github_installation_id' => 'integer',
            'github_installation_token' => 'encrypted',
            'github_installation_token_expires_at' => 'datetime',
            'github_installation_failed_at' => 'datetime',
            'github_default_labels' => 'array',
            'github_last_synced_at' => 'datetime',
        ];
    }

    /**
     * The settings row, created on first use.
     */
    public static function current(): static
    {
        return static::query()->firstOrCreate([]);
    }

    /**
     * Whether the GitHub App is installed, switched on and pointed at a
     * repository. Kept separate from {@see isGithubInstalled()} — the settings
     * page needs to tell "installed, pick a repository" apart from "ready".
     */
    public function isGithubConnected(): bool
    {
        return $this->isGithubInstalled()
            && $this->github_enabled
            && filled($this->github_repository);
    }

    public function isGithubInstalled(): bool
    {
        return $this->github_installation_id !== null;
    }

    /**
     * The installation was removed or suspended in GitHub.
     */
    public function githubNeedsReconnect(): bool
    {
        return $this->isGithubInstalled() && filled($this->github_installation_failed_at);
    }

    /**
     * Treat a token as expired two minutes early so a call never starts with
     * a token that dies mid-request.
     */
    public function isGithubInstallationTokenExpired(): bool
    {
        if (blank($this->github_installation_token) || ! $this->github_installation_token_expires_at) {
            return true;
        }

        return now()->addMinutes(2)->gte($this->github_installation_token_expires_at);
    }

    /**
     * The repository in `owner/name` form, normalised from a pasted GitHub URL.
     */
    public function githubRepository(): ?string
    {
        return static::normaliseGithubRepository($this->github_repository);
    }

    public static function normaliseGithubRepository(?string $repository): ?string
    {
        if (blank($repository)) {
            return null;
        }

        $repository = trim($repository);
        $repository = preg_replace('#^(https?://)?(www\.)?github\.com/#i', '', $repository) ?? $repository;
        $repository = preg_replace('#\.git$#i', '', $repository) ?? $repository;

        $repository = trim($repository, '/');

        return $repository !== '' ? $repository : null;
    }
}
