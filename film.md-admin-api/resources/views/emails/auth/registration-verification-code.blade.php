<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{{ __('emails.registration.subject') }}</title>
</head>
<body style="margin:0;padding:16px;background:#09090b;color:#f4f4f5;font-family:Arial,sans-serif;-webkit-text-size-adjust:100%;">
    <div style="box-sizing:border-box;max-width:560px;margin:0 auto;background:#111114;border:1px solid #27272a;border-radius:20px;padding:clamp(20px,6vw,32px);">
        <p style="margin:0 0 12px;font-size:14px;letter-spacing:0.12em;text-transform:uppercase;color:#a1a1aa;">filmoteca.md</p>
        <h1 style="margin:0 0 16px;font-size:28px;line-height:1.2;">{{ __('emails.registration.heading') }}</h1>
        <p style="margin:0 0 24px;font-size:16px;line-height:1.7;color:#d4d4d8;">
            {{ __('emails.registration.intro', ['name' => $userName]) }}
        </p>
        <div style="margin:0 0 24px;padding:20px;border-radius:16px;border:1px solid #3f3f46;background:#18181b;text-align:center;">
            <div style="display:inline-block;white-space:nowrap;word-break:keep-all;overflow-wrap:normal;font-family:Arial,sans-serif;font-size:clamp(28px,9vw,34px);line-height:1.2;letter-spacing:0.18em;font-weight:700;color:#ffffff;">{{ $code }}</div>
        </div>
        <div style="margin:0 0 24px;text-align:center;">
            <a href="{{ $verificationUrl }}" style="display:inline-block;box-sizing:border-box;max-width:100%;padding:14px 22px;border-radius:10px;background:#dc2626;color:#ffffff;font-size:16px;font-weight:700;text-decoration:none;">
                {{ __('emails.registration.button') }}
            </a>
        </div>
        <p style="margin:0 0 8px;font-size:14px;line-height:1.6;color:#a1a1aa;">
            {{ trans_choice('emails.registration.expires', $expiresInMinutes, ['minutes' => $expiresInMinutes]) }}
        </p>
        <p style="margin:0;font-size:14px;line-height:1.6;color:#a1a1aa;">
            {{ __('emails.registration.ignore') }}
        </p>
    </div>
</body>
</html>
