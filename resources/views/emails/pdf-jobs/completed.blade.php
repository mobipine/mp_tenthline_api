<!doctype html>
<html>
  <body style="font-family:Arial, sans-serif; color:#0f172a; line-height:1.5;">
    <h2 style="margin:0 0 12px;">Your numbered document is ready</h2>
    <p style="margin:0 0 8px;">Hi {{ $name ?: 'there' }},</p>
    <p style="margin:0 0 16px;">Your file <strong>{{ $filename }}</strong> has finished processing.</p>
    <p style="margin:0 0 16px;">
      <a href="{{ $downloadUrl }}" style="display:inline-block; background:#8b5e3c; color:#fff; text-decoration:none; padding:10px 16px; border-radius:8px; font-weight:600;">
        Download your PDF
      </a>
    </p>
    <p style="margin:0 0 12px; color:#334155;">Important: this file will be deleted after {{ $expiresInHours }} hours.</p>
    <p style="margin:0; color:#475569; font-size:13px;">Please download and store it safely.</p>
  </body>
</html>
