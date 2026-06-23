<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Welcome to TenthLine</title>
</head>
<body style="margin:0;padding:0;background:#f4f7fb;font-family:Arial,sans-serif;color:#0f172a;">
<table role="presentation" cellpadding="0" cellspacing="0" width="100%" style="padding:24px 0;">
    <tr>
        <td align="center">
            <table role="presentation" cellpadding="0" cellspacing="0" width="600" style="max-width:600px;background:#ffffff;border-radius:14px;overflow:hidden;border:1px solid #e2e8f0;">
                <tr>
                    <td style="padding:26px 28px;background:linear-gradient(135deg,#0f172a,#1e293b);color:#fff;">
                        <h1 style="margin:0;font-size:22px;line-height:1.2;">Welcome to TenthLine</h1>
                        <p style="margin:8px 0 0 0;font-size:14px;opacity:.9;">Tenth lining for legal PDFs</p>
                    </td>
                </tr>
                <tr>
                    <td style="padding:28px;">
                        <p style="margin:0 0 14px 0;font-size:15px;line-height:1.6;">Hi {{ $name }},</p>
                        <p style="margin:0 0 16px 0;font-size:15px;line-height:1.6;">
                            Your TenthLine account is ready. You can process legal PDFs, track progress, and view your previous jobs from your dashboard.
                        </p>
                        <p style="margin:0 0 24px 0;">
                            <a href="{{ $frontendUrl }}"
                               style="display:inline-block;padding:12px 20px;background:#2563eb;color:#fff;text-decoration:none;border-radius:10px;font-weight:700;">
                                Open TenthLine
                            </a>
                        </p>
                        <p style="margin:0;font-size:13px;color:#64748b;line-height:1.6;">
                            If you did not create this account, you can ignore this email.
                        </p>
                    </td>
                </tr>
            </table>
        </td>
    </tr>
</table>
</body>
</html>
