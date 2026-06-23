<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reset Password</title>
</head>
<body style="margin:0;padding:0;background:#f4f7fb;font-family:Arial,sans-serif;color:#0f172a;">
<table role="presentation" cellpadding="0" cellspacing="0" width="100%" style="padding:24px 0;">
    <tr>
        <td align="center">
            <table role="presentation" cellpadding="0" cellspacing="0" width="600" style="max-width:600px;background:#ffffff;border-radius:14px;overflow:hidden;border:1px solid #e2e8f0;">
                <tr>
                    <td style="padding:26px 28px;background:linear-gradient(135deg,#1d4ed8,#2563eb);color:#fff;">
                        <h1 style="margin:0;font-size:22px;line-height:1.2;">TenthLine</h1>
                        <p style="margin:8px 0 0 0;font-size:14px;opacity:.9;">Set or reset your account password</p>
                    </td>
                </tr>
                <tr>
                    <td style="padding:28px;">
                        <p style="margin:0 0 14px 0;font-size:15px;line-height:1.6;">Hi {{ $name }},</p>
                        <p style="margin:0 0 14px 0;font-size:15px;line-height:1.6;">
                            Click the button below to set a new password for your TenthLine account.
                        </p>
                        <p style="margin:0 0 22px 0;font-size:14px;color:#475569;line-height:1.6;">
                            This link will expire in {{ $expireMinutes }} minutes.
                        </p>
                        <p style="margin:0 0 24px 0;">
                            <a href="{{ $resetUrl }}"
                               style="display:inline-block;padding:12px 20px;background:#2563eb;color:#fff;text-decoration:none;border-radius:10px;font-weight:700;">
                                Set Password
                            </a>
                        </p>
                        <p style="margin:0;font-size:13px;color:#64748b;line-height:1.6;word-break:break-word;">
                            If the button does not work, copy and paste this link:<br>
                            <a href="{{ $resetUrl }}" style="color:#2563eb;">{{ $resetUrl }}</a>
                        </p>
                    </td>
                </tr>
            </table>
        </td>
    </tr>
</table>
</body>
</html>
