<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>New Help Request</title>
</head>
<body style="font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif; line-height: 1.6; color: #333333; max-width: 600px; margin: 0 auto; padding: 20px; background-color: #f5f5f5;">
    <div style="background-color: #ffffff; border-radius: 8px; padding: 40px; box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);">

        <h1 style="color: #1a1a1a; font-size: 22px; margin-bottom: 20px;">New Help Request</h1>

        <table style="width: 100%; border-collapse: collapse; margin-bottom: 24px;">
            <tr>
                <td style="padding: 8px 12px; border-bottom: 1px solid #eeeeee; font-weight: bold; color: #555555; width: 130px;">From</td>
                <td style="padding: 8px 12px; border-bottom: 1px solid #eeeeee;">
                    {{ \Joelseneque\HelpRequests\HelpRequests::userName($helpRequest->user) }}
                    @if($helpRequest->user?->email)
                        &lt;{{ $helpRequest->user->email }}&gt;
                    @endif
                </td>
            </tr>
            @if ($helpRequest->category)
                <tr>
                    <td style="padding: 8px 12px; border-bottom: 1px solid #eeeeee; font-weight: bold; color: #555555;">Type</td>
                    <td style="padding: 8px 12px; border-bottom: 1px solid #eeeeee;">{{ $helpRequest->categoryLabel() }}</td>
                </tr>
            @endif
            <tr>
                <td style="padding: 8px 12px; border-bottom: 1px solid #eeeeee; font-weight: bold; color: #555555;">Page</td>
                <td style="padding: 8px 12px; border-bottom: 1px solid #eeeeee;">{{ $helpRequest->page_title ?: '—' }}</td>
            </tr>
            <tr>
                <td style="padding: 8px 12px; border-bottom: 1px solid #eeeeee; font-weight: bold; color: #555555;">URL</td>
                <td style="padding: 8px 12px; border-bottom: 1px solid #eeeeee; word-break: break-all;">{{ $helpRequest->page_url ?: '—' }}</td>
            </tr>
            <tr>
                <td style="padding: 8px 12px; border-bottom: 1px solid #eeeeee; font-weight: bold; color: #555555;">Submitted</td>
                <td style="padding: 8px 12px; border-bottom: 1px solid #eeeeee;">{{ $helpRequest->created_at->format('d M Y, g:i A') }}</td>
            </tr>
        </table>

        <h2 style="color: #1a1a1a; font-size: 16px; margin-bottom: 8px;">Comment</h2>
        <div style="background-color: #f9f9f9; border-radius: 6px; padding: 16px; margin-bottom: 24px; white-space: pre-wrap;">{{ $helpRequest->comment }}</div>

        @if ($helpRequest->hasVideo())
            <p style="margin-bottom: 8px;"><strong>Video:</strong></p>
            <p style="margin-bottom: 24px;"><a href="{{ $helpRequest->video_url }}">Watch the recording{{ $helpRequest->isLoomVideo() ? ' in Loom' : '' }}</a></p>
        @endif

        @if($helpRequest->getScreenshotUrl())
            <p style="margin-bottom: 8px;"><strong>Screenshot:</strong></p>
            <p style="margin-bottom: 24px;"><a href="{{ $helpRequest->getScreenshotUrl() }}">View attached screenshot</a></p>
        @endif

        <div style="text-align: center; margin-bottom: 8px;">
            <a href="{{ $viewUrl }}" style="display: inline-block; background-color: #1a1a1a; color: #ffffff; text-decoration: none; padding: 10px 24px; border-radius: 6px; font-weight: 600; font-size: 14px;">View &amp; Reply</a>
        </div>

        <hr style="border: none; border-top: 1px solid #eeeeee; margin: 30px 0;">

        <p style="color: #999999; font-size: 12px; text-align: center; margin-bottom: 0;">
            This is an automated message from {{ \Joelseneque\HelpRequests\HelpRequests::appName() }}.
        </p>
    </div>
</body>
</html>
