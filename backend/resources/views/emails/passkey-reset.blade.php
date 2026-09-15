<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>{{ __('emails.passkey_reset.subject', ['association' => $associationName]) }}</title>
</head>
<body style="margin:0; padding:0; background-color:#f5f7ff; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif;">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background-color:#f5f7ff; padding:32px 16px;">
  <tr>
    <td align="center">
      <table role="presentation" width="480" cellpadding="0" cellspacing="0" style="max-width:480px; width:100%; background-color:#ffffff; border-radius:16px; overflow:hidden; box-shadow:0 4px 16px rgba(15,23,42,0.06);">
        <tr>
          <td style="background-color:#2563eb; padding:28px 32px;">
            @if($associationLogoUrl ?? null)
              <img src="{{ $associationLogoUrl }}" alt="{{ $associationName }}" style="height:32px; display:block; margin-bottom:8px;">
            @endif
            <span style="color:#ffffff; font-size:20px; font-weight:700;">{{ $associationName }}</span>
          </td>
        </tr>
        <tr>
          <td style="padding:32px;">
            <h1 style="margin:0 0 16px; font-size:20px; color:#0f172a;">{{ __('emails.passkey_reset.hello', ['name' => $name]) }}</h1>
            <p style="margin:0 0 16px; font-size:15px; line-height:1.6; color:#334155;">
              {{ __('emails.passkey_reset.intro') }}
            </p>

            <p style="margin:0 0 8px; font-size:13px; font-weight:600; color:#64748b; text-transform:uppercase; letter-spacing:0.06em;">
              {{ __('emails.passkey_reset.new_passkey_label') }}
            </p>
            <div style="margin:0 0 20px; padding:16px; background-color:#f1f5f9; border-radius:12px; text-align:center;">
              <span style="font-size:32px; font-weight:700; letter-spacing:0.3em; color:#0f172a;">{{ $passkey }}</span>
            </div>

            <table role="presentation" cellpadding="0" cellspacing="0" style="margin:0 0 20px;">
              <tr>
                <td style="border-radius:10px; background-color:#2563eb;">
                  <a href="{{ $portalUrl }}" style="display:inline-block; padding:12px 24px; font-size:15px; font-weight:600; color:#ffffff; text-decoration:none;">
                    {{ __('emails.passkey_reset.open_portal') }}
                  </a>
                </td>
              </tr>
            </table>

            <p style="margin:0; font-size:14px; line-height:1.6; color:#64748b;">
              {{ __('emails.passkey_reset.not_requested', ['association' => $associationName]) }}
            </p>
          </td>
        </tr>
        <tr>
          <td style="padding:20px 32px; background-color:#f8fafc; border-top:1px solid #eef2ff;">
            <p style="margin:0 0 8px; font-size:12px; color:#94a3b8;">
              {{ __('emails.passkey_reset.footer_automated', ['association' => $associationName]) }}
            </p>
            <p style="margin:0; font-size:12px; color:#94a3b8;">
              {{ $associationAddress ?? '' }}
              @if($associationPhone ?? null) &middot; {{ $associationPhone }} @endif
              @if($associationEmail ?? null) &middot; {{ $associationEmail }} @endif
            </p>
          </td>
        </tr>
      </table>
    </td>
  </tr>
</table>
</body>
</html>
