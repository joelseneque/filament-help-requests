<?php

namespace Joelseneque\HelpRequests\Models\Concerns;

use Illuminate\Support\Facades\Storage;

/**
 * The files a help request or one of its replies carries.
 *
 * Paths are a plain list on the `attachments` column, and the stored basename
 * is the display name: uploads keep their original name inside a unique
 * folder, so there is no separate name map to keep in step.
 *
 * Files sit on a public disk on purpose. The GitHub issue body embeds a bare
 * URL that GitHub fetches anonymously and keeps forever, and the emails link
 * files for recipients who have no session; a signed URL would expire and rot both.
 *
 * @property list<string>|null $attachments
 */
trait HasAttachments
{
    /**
     * Rendered as a picture; everything else is a download link.
     *
     * @var list<string>
     */
    protected static array $imageExtensions = ['jpg', 'jpeg', 'jpe', 'png', 'gif', 'webp', 'heic', 'heif', 'bmp', 'tif', 'tiff'];

    public function hasAttachments(): bool
    {
        return $this->attachmentPaths() !== [];
    }

    /**
     * @return list<string>
     */
    public function attachmentPaths(): array
    {
        return array_values(array_filter(
            $this->attachments ?? [],
            fn (mixed $path): bool => is_string($path) && $path !== '',
        ));
    }

    /**
     * @return list<string>
     */
    public function imageAttachmentPaths(): array
    {
        return array_values(array_filter($this->attachmentPaths(), $this->isImageAttachment(...)));
    }

    /**
     * @return list<string>
     */
    public function fileAttachmentPaths(): array
    {
        return array_values(array_filter($this->attachmentPaths(), fn (string $path): bool => ! $this->isImageAttachment($path)));
    }

    public function attachmentUrl(string $path): string
    {
        return Storage::disk(config('help-requests.storage.disk'))->url($path);
    }

    public function isImageAttachment(string $path): bool
    {
        return in_array(strtolower(pathinfo($path, PATHINFO_EXTENSION)), static::$imageExtensions, true);
    }

    public function attachmentName(string $path): string
    {
        return basename($path);
    }
}
