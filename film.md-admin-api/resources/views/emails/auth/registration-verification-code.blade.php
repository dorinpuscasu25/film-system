<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Confirm your film.md account</title>
</head>
<body style="margin:0;padding:32px;background:#09090b;color:#f4f4f5;font-family:Arial,sans-serif;">
    <div style="max-width:560px;margin:0 auto;background:#111114;border:1px solid #27272a;border-radius:20px;padding:32px;">
        <p style="margin:0 0 12px;font-size:14px;letter-spacing:0.12em;text-transform:uppercase;color:#a1a1aa;">film.md</p>
        <h1 style="margin:0 0 16px;font-size:28px;line-height:1.2;">Confirm your account</h1>
        <p style="margin:0 0 24px;font-size:16px;line-height:1.7;color:#d4d4d8;">
            Hi {{ $userName }}, use the verification code below to finish creating your account.
        </p>
        <div style="margin:0 0 24px;padding:20px;border-radius:16px;border:1px solid #3f3f46;background:#18181b;text-align:center;">
            <div style="font-size:34px;letter-spacing:0.32em;font-weight:700;color:#ffffff;">{{ $code }}</div>
        </div>
        <p style="margin:0 0 8px;font-size:14px;color:#a1a1aa;">
            This code expires at {{ $expiresAtLabel }}.
        </p>
        <p style="margin:0;font-size:14px;color:#a1a1aa;">
            If you did not request this account, you can ignore this message.
        </p>
    </div>
</body>
</html>
