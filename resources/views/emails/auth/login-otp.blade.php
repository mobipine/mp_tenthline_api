<!doctype html>
<html>
  <body style="font-family:Arial, sans-serif; color:#0f172a; line-height:1.5;">
    <h2 style="margin:0 0 12px;">Your LegalLine one-time code</h2>
    <p style="margin:0 0 16px;">Hi {{ $name ?: 'there' }}, use this code to continue:</p>
    <p style="margin:0 0 16px; font-size:28px; font-weight:700; letter-spacing:4px;">{{ $code }}</p>
    <p style="margin:0 0 12px;">This code expires in {{ $expiresInMinutes }} minutes.</p>
    <p style="margin:0; color:#475569; font-size:13px;">If you did not request this, you can ignore this email.</p>
  </body>
</html>
