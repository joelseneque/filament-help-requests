<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Your Help Request Has Been {{ $helpRequest->status->getLabel() }}</title>
</head>
<body style="font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif; line-height: 1.6; color: #333333; max-width: 600px; margin: 0 auto; padding: 20px; background-color: #f5f5f5;">
    <div style="background-color: #ffffff; border-radius: 8px; padding: 40px; box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);">

        @if (filled(config('help-requests.branding.logo_url')))
            <div style="text-align: center; margin-bottom: 24px;">
                <img src="{{ config('help-requests.branding.logo_url') }}" alt="{{ \Joelseneque\HelpRequests\HelpRequests::appName() }}" style="max-width: 180px; height: auto;">
            </div>
        @endif

        <h1 style="color: #1a1a1a; font-size: 22px; margin-bottom: 20px;">
            Your help request has been {{ strtolower($helpRequest->status->getLabel()) }}
        </h1>

        <p style="margin-bottom: 20px;">Hi {{ \Joelseneque\HelpRequests\HelpRequests::userFirstName($helpRequest->user) }},</p>

        <p style="margin-bottom: 8px;">Your request:</p>
        <div style="background-color: #f4f4f4; border-radius: 6px; padding: 12px 16px; margin-bottom: 24px; color: #666666; font-size: 14px; white-space: pre-wrap;">{{ \Illuminate\Support\Str::limit($helpRequest->comment, 300) }}</div>

        @if ($comment)
            <h2 style="color: #1a1a1a; font-size: 16px; margin-bottom: 8px;">Closing note</h2>
            <div style="background-color: #f9f9f9; border-radius: 6px; padding: 16px; margin-bottom: 24px; white-space: pre-wrap;">{{ $comment->body }}</div>

            @if ($comment->hasScreenshot())
                <div style="margin-bottom: 24px;">
                    <a href="{{ $comment->getScreenshotUrl() }}" style="display: inline-block;">
                        <img src="{{ $comment->getScreenshotUrl() }}" alt="Screenshot" style="max-width: 100%; height: auto; border-radius: 6px; border: 1px solid #eeeeee;">
                    </a>
                </div>
            @endif
        @endif

        <p style="margin-bottom: 20px;">
            Status: <strong>{{ $helpRequest->status->getLabel() }}</strong>
        </p>

        <p style="margin-bottom: 20px; color: #666666; font-size: 14px;">
            If this isn't sorted, reply to your request in the help panel and we'll pick it back up.
        </p>

        <hr style="border: none; border-top: 1px solid #eeeeee; margin: 30px 0;">

        <p style="color: #999999; font-size: 12px; text-align: center; margin-bottom: 0;">
            This is an automated message from {{ \Joelseneque\HelpRequests\HelpRequests::appName() }}.
        </p>
    </div>
</body>
</html>
