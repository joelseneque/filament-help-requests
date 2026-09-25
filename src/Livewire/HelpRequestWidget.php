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

    public ?TemporaryUploadedFile $screenshot = null;

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
     * Optional reply screenshot keyed by help request id, matching $replyBody.
     *
     * @var array<int, TemporaryUploadedFile|null>
     */
    public array $replyScreenshot = [];

    /**
     * @return array<string, mixed>
     */
    protected function rules(): array
    {
        $rules = [
            'comment' => ['required', 'string', 'min:3', 'max:5000'],
            'screenshot' => ['nullable', 'image', 'max:'.config('help-requests.storage.max_size_kb')],
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
        $this->validate();

        $helpRequest = HelpRequest::create([
            'user_id' => auth()->id(),
            'page_url' => $this->pageUrl !== '' ? $this->pageUrl : null,
            'page_title' => $this->pageTitle !== '' ? $this->pageTitle : null,
            'category' => $this->category !== '' ? $this->category : null,
            'video_url' => HelpRequests::videoLinksEnabled() && trim($this->videoUrl) !== '' ? trim($this->videoUrl) : null,
            'comment' => $this->comment,
            'screenshot_path' => $this->storeUpload($this->screenshot),
            'status' => HelpRequestStatus::Open,
        ]);

        HelpRequestNotifier::newRequestForAdmins($helpRequest);

        if (HelpRequestSetting::query()->first()?->github_auto_create && app(GitHubIssueService::class)->isConfigured()) {
            CreateGitHubIssueForHelpRequest::dispatch($helpRequest);
        }

        $this->reset('comment', 'screenshot', 'category', 'videoUrl');
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

        $this->validate(
            [
                "replyBody.{$helpRequestId}" => ['required', 'string', 'min:1', 'max:5000'],
                "replyScreenshot.{$helpRequestId}" => ['nullable', 'image', 'max:'.config('help-requests.storage.max_size_kb')],
            ],
            attributes: [
                "replyBody.{$helpRequestId}" => 'reply',
                "replyScreenshot.{$helpRequestId}" => 'screenshot',
            ],
        );

        $reply = $helpRequest->replies()->create([
            'user_id' => auth()->id(),
            'body' => trim($this->replyBody[$helpRequestId]),
            'screenshot_path' => $this->storeUpload($this->replyScreenshot[$helpRequestId] ?? null),
        ]);

        if ($helpRequest->hasGithubIssue()) {
            app(GitHubIssueService::class)->postComment($reply);
        }

        HelpRequestNotifier::userReplyForAdmins($helpRequest, $reply);

        unset($this->replyBody[$helpRequestId], $this->replyScreenshot[$helpRequestId]);
        unset($this->myRequests);

        Notification::make()
            ->title('Reply sent')
            ->success()
            ->send();
    }

    protected function storeUpload(?TemporaryUploadedFile $file): ?string
    {
        return $file?->store(
            config('help-requests.storage.directory'),
            config('help-requests.storage.disk'),
        ) ?: null;
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
