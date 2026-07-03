<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Reset your Inventory password</title>
<style>
  body { margin: 0; padding: 0; background: #0a141d; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif; color: #eaf6ff; }
  .wrap { max-width: 520px; margin: 40px auto; padding: 40px 32px; background: rgba(125,211,252,.06); border: 1px solid rgba(125,211,252,.18); border-radius: 16px; }
  h1 { font-size: 22px; font-weight: 700; color: #7dd3fc; margin: 0 0 16px; }
  p { font-size: 15px; line-height: 1.6; color: #b8d8f0; margin: 0 0 20px; }
  .btn { display: inline-block; padding: 14px 28px; background: #7dd3fc; color: #06283b; font-size: 15px; font-weight: 700; text-decoration: none; border-radius: 10px; }
  .url { margin-top: 24px; font-size: 12px; color: #7fa8c4; word-break: break-all; }
  .footer { margin-top: 32px; font-size: 12px; color: #4a7a9a; }
</style>
</head>
<body>
<div class="wrap">
  <h1>Reset your password</h1>
  <p>We received a request to reset the password for your Inventory account. Click the button below to choose a new password.</p>
  <a href="{{ $resetUrl }}" class="btn">Reset password</a>
  <p class="url">Or copy this link: {{ $resetUrl }}</p>
  <p class="footer">This link expires in 60 minutes. If you didn't request a password reset, you can safely ignore this email.</p>
</div>
</body>
</html>
