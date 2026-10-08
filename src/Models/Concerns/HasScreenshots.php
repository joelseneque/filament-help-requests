<?php

namespace Joelseneque\HelpRequests\Models\Concerns;

use Illuminate\Support\Facades\Storage;

/**
 * Screenshots live in `screenshot_paths`; `screenshot_path` is read too so rows
 * from before multiple screenshots still show theirs.
 *
 * @property string|null $screenshot_path
 * @property list<string>|null $screenshot_paths
 */
trait HasScreenshots
{
    /**
     * @return list<string>
     */
    public function screenshotPaths(): array
    {
        return array_values(array_filter([$this->screenshot_path, ...($this->screenshot_paths ?? [])]));
    }

    /**
     * @return list<string>
     */
    public function screenshotUrls(): array
    {
        $disk = Storage::disk(config('help-requests.storage.disk'));

        return array_map(fn (string $path): string => $disk->url($path), $this->screenshotPaths());
    }

    public function hasScreenshot(): bool
    {
        return $this->screenshotPaths() !== [];
    }

    /**
     * The first screenshot, for callers that only show one.
     */
    public function getScreenshotUrl(): ?string
    {
        return $this->screenshotUrls()[0] ?? null;
    }
}
