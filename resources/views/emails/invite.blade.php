<!DOCTYPE html>
<html>
<body style="font-family: Arial, Helvetica, sans-serif; color: #1f2937; background: #f9fafb; padding: 24px;">
  <div style="max-width: 460px; margin: 0 auto; background: #ffffff; border-radius: 12px; padding: 28px; border: 1px solid #e5e7eb;">
    <p style="margin-top: 0;">Hello,</p>
    <p>You've been invited to join <strong>{{ $companyName }}</strong> on Logon Attendance.</p>
    <p>Open the app, tap <strong>Have an Invite Link?</strong>, and paste this code:</p>
    <p style="font-size: 16px; font-weight: bold; word-break: break-all; background: #f3f4f6; padding: 12px; border-radius: 8px; text-align: center;">{{ $invite->token }}</p>
    <p>It expires on {{ $invite->expires_at->format('j M Y') }}.</p>
    <p style="color: #6b7280; font-size: 13px;">If you weren't expecting this invite, you can ignore this email.</p>
  </div>
</body>
</html>