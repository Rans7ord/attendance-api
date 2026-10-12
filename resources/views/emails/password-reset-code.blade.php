<!DOCTYPE html>
<html>
<body style="font-family: Arial, Helvetica, sans-serif; color: #1f2937; background: #f9fafb; padding: 24px;">
  <div style="max-width: 460px; margin: 0 auto; background: #ffffff; border-radius: 12px; padding: 28px; border: 1px solid #e5e7eb;">
    <p style="margin-top: 0;">Hi {{ $name }},</p>
    <p>Use this code in the app to reset your password:</p>
    <p style="font-size: 32px; letter-spacing: 8px; font-weight: bold; text-align: center; margin: 24px 0;">{{ $code }}</p>
    <p>It expires in {{ $minutes }} minutes and works once.</p>
    <p style="color: #6b7280; font-size: 13px;">If you didn't ask for this, you can ignore this email — your password stays the same.</p>
  </div>
</body>
</html>