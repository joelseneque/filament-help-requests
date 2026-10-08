<?php

namespace Joelseneque\HelpRequests\Livewire;

use Closure;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Validation\Rule;
use Joelseneque\HelpRequests\Enums\HelpRequestStatus;
use Joelseneque\HelpRequests\HelpRequests;
use Joelseneque\HelpRequests\Jobs\CreateGitHubIssueForHelpRequest;
use Joelseneque\HelpRequests\Models\HelpRequest;
use Joelseneque\HelpRequests\Models\HelpRequestSetting;
use Joelseneque\HelpRequests\Services\GitHubIssueService;
use Joelseneque\HelpRequests\Support\HelpRequestNotifier;
use Livewire\Attributes\Computed;
use Livewire\Attributes\On;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;

/**
 * The floating "?" button on every panel page: raise a request, and read and
 * reply to your own.
 */
class HelpRequestWidget extends Component
{
    use WithFileUploads;

    public bool $open = false;

    public string $tab = 'new';

    public string $comment = '';

    /**
     * Screenshots keyed by slot. A removed one is set to null rather than
     * unset, so slots never shift away from their notes in $screenshotNotes.
     *
     * @var array<int, TemporaryUploadedFile|null>
     */
    public array $screenshots = [];

    public string $pageUrl = '';

    public string $pageTitle = '';

    public string $category = '';

    public string $videoUrl = '';

    /**
     * The panel's brand name, stripped from `document.title` so the widget can
     * say which page the request is about.
     */
    public string $brandName = '';

    /**
     * The request a notification's "View request" button asked us to open, so
     * the thread can be picked out of the list. Null on an ordinary page load.
     */
    public ?int $highlightRequestId = null;

    /**
     * Reply body keyed by help request id, used in the "My Requests" thread.
     *
     * @var array<int, string>
     */
    public array $replyBody = [];

    /**
     * Reply screenshots keyed by help request id, then slot, matching $replyBody.
     *
     * @var array<int, array<int, TemporaryUploadedFile|null>>
     */
    public array $replyScreenshots = [];

    /**
     * Notes for the numbered markers drawn on each screenshot, keyed by the
     * screenshot's slot, in marker order. The markers themselves are burned
     * into the image in the browser; the notes are appended to the comment so
     * they reach every channel.
     *
     * @var array<int, array<int, string|null>>
     */
    public array $screenshotNotes = [];

    /**
     * Marker notes keyed by help request id, matching $replyScreenshots.
     *
     * @var array<int, array<int, array<int, string|null>>>
     */
    public array $replyScreenshotNotes = [];

    /**
     * @return array<string, mixed>
     */
    protected function rules(): array
    {
        $rules = [
            'comment' => ['required', 'string', 'min:3', 'max:5000'],
            ...$this->screenshotRules('screenshots', 'screenshotNotes'),
            'category' => ['nullable', 'string', Rule::in(array_keys(HelpRequests::categories()))],
            'videoUrl' => [
                'nullable',
                'url:https',
                'max:2048',
                function (string $attribute, mixed $value, Closure $fail): void {
                    if (! HelpRequests::isAllowedVideoUrl((string) $value)) {
                        $fail('Paste a share link from '.implode(' or ', config('help-requests.video.allowed_hosts')).'.');
                    }
                },
            ],
        ];

        if (! HelpRequests::videoLinksEnabled()) {
            unset($rules['videoUrl']);
        }

        return $rules;
    }

    /**
     * @return array<string, mixed>
     */
    protected function screenshotRules(string $files, string $notes): array
    {
        return [
            $files => ['nullable', 'array', 'max:'.config('help-requests.storage.max_files', 5)],
            "{$files}.*" => ['nullable', 'image', 'max:'.config('help-requests.storage.max_size_kb')],
            $notes => ['nullable', 'array'],
            "{$notes}.*" => ['nullable', 'array', 'max:20'],
            "{$notes}.*.*" => ['nullable', 'string', 'max:500'],
        ];
    }

    /**
     * @return array<string, string>
     */
    protected function validationAttributes(): array
    {
        return ['videoUrl' => 'video link'];
    }

    /**
     * Open straight onto a thread when a notification linked here with
     * `?help_request={id}`.
     *
     * This widget renders on every panel page, so `mount()` runs on every
     * request and has to stay cheap and quiet: a missing, unparseable or
     * someone else's id is ignored rather than reported.
     */
    public function mount(): void
    {
        $this->brandName = (string) (Filament::getCurrentPanel()?->getBrandName() ?? '');

        $id = (int) request()->query('help_request');

        if ($id <= 0) {
            return;
        }

        $isOwn = HelpRequest::query()
            ->whereKey($id)
            ->where('user_id', auth()->id())
            ->exists();

        if (! $isOwn) {
            return;
        }

        $this->highlightRequestId = $id;
        $this->open = true;
        $this->tab = 'mine';
    }

    #[On('open-help-requests')]
    public function openMyRequests(): void
    {
        $this->open = true;
        $this->tab = 'mine';

        unset($this->myRequests);
    }

    public function submit(): void
    {
        // Removed screenshots are nulls; drop them so the count rule sees real ones.
        $this->screenshots = array_filter($this->screenshots);

        $this->validate();

        $helpRequest = HelpRequest::create([
            'user_id' => auth()->id(),
            'page_url' => $this->pageUrl !== '' ? $this->pageUrl : null,
            'page_title' => $this->pageTitle !== '' ? $this->pageTitle : null,
            'category' => $this->category !== '' ? $this->category : null,
            'video_url' => HelpRequests::videoLinksEnabled() && trim($this->videoUrl) !== '' ? trim($this->videoUrl) : null,
            'comment' => $this->withMarkerNotes($this->comment, $this->screenshots, $this->screenshotNotes),
            'screenshot_paths' => $this->storeUploads($this->screenshots),
            'status' => HelpRequestStatus::Open,
        ]);

        HelpRequestNotifier::newRequestForAdmins($helpRequest);

        if (HelpRequestSetting::query()->first()?->github_auto_create && app(GitHubIssueService::class)->isConfigured()) {
            CreateGitHubIssueForHelpRequest::dispatch($helpRequest);
        }

        $this->reset('comment', 'screenshots', 'screenshotNotes', 'category', 'videoUrl');
        $this->tab = 'mine';

        unset($this->myRequests);

        Notification::make()
            ->title('Help request sent')
            ->body('Thanks — we\'ll get back to you shortly.')
            ->success()
            ->send();
    }

    public function replyTo(int $helpRequestId): void
    {
        $helpRequest = HelpRequest::query()
            ->where('user_id', auth()->id())
            ->findOrFail($helpRequestId);

        $this->replyScreenshots[$helpRequestId] = array_filter($this->replyScreenshots[$helpRequestId] ?? []);

        $this->validate(
            [
                "replyBody.{$helpRequestId}" => ['required', 'string', 'min:1', 'max:5000'],
                ...$this->screenshotRules("replyScreenshots.{$helpRequestId}", "replyScreenshotNotes.{$helpRequestId}"),
            ],
            attributes: [
                "replyBody.{$helpRequestId}" => 'reply',
                "replyScreenshots.{$helpRequestId}" => 'screenshots',
                "replyScreenshots.{$helpRequestId}.*" => 'screenshot',
            ],
        );

        $screenshots = $this->replyScreenshots[$helpRequestId];

        $reply = $helpRequest->replies()->create([
            'user_id' => auth()->id(),
            'body' => $this->withMarkerNotes(
                trim($this->replyBody[$helpRequestId]),
                $screenshots,
                $this->replyScreenshotNotes[$helpRequestId] ?? [],
            ),
            'screenshot_paths' => $this->storeUploads($screenshots),
        ]);

        if ($helpRequest->hasGithubIssue()) {
            app(GitHubIssueService::class)->postComment($reply);
        }

        HelpRequestNotifier::userReplyForAdmins($helpRequest, $reply);

        unset($this->replyBody[$helpRequestId], $this->replyScreenshots[$helpRequestId], $this->replyScreenshotNotes[$helpRequestId]);
        unset($this->myRequests);

        Notification::make()
            ->title('Reply sent')
            ->success()
            ->send();
    }

    /**
     * Notes keep their marker's number, so a blank note in the middle does not
     * shift the numbers away from the dots burned into the image. Screenshots
     * are numbered in the order they are stored, and only when there are
     * several. Notes for a removed screenshot are dropped with it.
     *
     * @param  array<int, TemporaryUploadedFile|null>  $screenshots
     * @param  array<int, array<int, string|null>>  $notes
     */
    protected function withMarkerNotes(string $text, array $screenshots, array $notes): string
    {
        $screenshots = array_filter($screenshots);
        $number = 0;

        foreach (array_keys($screenshots) as $slot) {
            $number++;
            $lines = [];

            foreach (array_values($notes[$slot] ?? []) as $index => $note) {
                if (trim((string) $note) !== '') {
                    $lines[] = ($index + 1).'. '.trim($note);
                }
            }

            if ($lines !== []) {
                $label = count($screenshots) > 1 ? "Marked on screenshot {$number}:" : 'Marked on screenshot:';
                $text .= "\n\n{$label}\n".implode("\n", $lines);
            }
        }

        return $text;
    }

    /**
     * @param  array<int, TemporaryUploadedFile|null>  $files
     * @return list<string>|null
     */
    protected function storeUploads(array $files): ?array
    {
        $paths = array_values(array_filter(array_map(
            fn (TemporaryUploadedFile $file): string|false => $file->store(
                config('help-requests.storage.directory'),
                config('help-requests.storage.disk'),
            ),
            array_filter($files),
        )));

        return $paths === [] ? null : $paths;
    }

    /**
     * @return Collection<int, HelpRequest>
     */
    #[Computed]
    public function myRequests(): Collection
    {
        $requests = HelpRequest::query()
            ->where('user_id', auth()->id())
            ->with(['replies.user'])
            ->latest()
            ->limit(20)
            ->get();

        /*
         * A notification can link to a request older than the twenty most
         * recent, and a highlight pointing at a row that was never rendered is
         * just another dead link. It goes to the top: it is what the user
         * clicked through to see.
         */
        if ($this->highlightRequestId && ! $requests->contains('id', $this->highlightRequestId)) {
            $linked = HelpRequest::query()
                ->where('user_id', auth()->id())
                ->with(['replies.user'])
                ->find($this->highlightRequestId);

            if ($linked) {
                $requests->prepend($linked);
            }
        }

        return $requests;
    }

    /**
     * @return array<string, string>
     */
    #[Computed]
    public function categories(): array
    {
        return HelpRequests::categories();
    }

    public function render(): View
    {
        return view('help-requests::livewire.help-request-widget');
    }
}
