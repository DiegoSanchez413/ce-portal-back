{{-- Email del código de ingreso al portal: el texto del CEO por párrafos y el código resaltado. Igual en ce-portal-back y en el Soft (vista previa). --}}
@php($paragraphs = array_values(array_filter(array_map('trim', preg_split("/\R{2,}/", $body)))))
@php($chip = '<span style="display:inline-block;padding:2px 12px;border-radius:8px;background:#FFF1E8;color:#C2410C;font-size:22px;font-weight:bold;letter-spacing:4px;">'.e($code).'</span>')
<!doctype html>
<html lang="{{ $es ? 'es' : 'en' }}">
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>{{ $subject }}</title></head>
<body style="margin:0;padding:0;background:#F3F4F6;font-family:Arial,Helvetica,sans-serif;color:#1F2A44;">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#F3F4F6;">
<tr><td align="center" style="padding:32px 16px;">
  <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:600px;background:#FFFFFF;border-radius:16px;overflow:hidden;">
    <tr><td style="padding:24px 32px;border-bottom:1px solid #F1F2F4;">
      <table role="presentation" cellpadding="0" cellspacing="0"><tr>
        <td style="padding-right:14px;"><img src="{{ $logoUrl }}" width="64" height="64" alt="" style="display:block;border:0;"></td>
        <td>
          <div style="font-size:22px;line-height:1.2;font-weight:bold;color:#1F2A44;white-space:nowrap;">Credit Experts</div>
          <div style="font-size:13px;color:#6B7280;padding-top:2px;">{{ $es ? 'Portal de clientes' : 'Client portal' }}</div>
        </td>
      </tr></table>
    </td></tr>
    <tr><td style="padding:30px 40px 18px;font-size:15px;line-height:1.65;">
      @foreach ($paragraphs as $i => $paragraph)
        <p style="margin:0 0 16px;{{ $i === 0 ? 'font-weight:bold;color:#1F2A44;' : 'color:#4B5563;' }}">{!! str_replace(e($code), $chip, nl2br(e($paragraph))) !!}</p>
      @endforeach
    </td></tr>
    <tr><td align="center" style="padding:18px 40px;background:#F9FAFB;border-top:1px solid #E5E7EB;font-size:12px;line-height:1.7;color:#6B7280;">
      Credit Experts · {{ $supportPhone }} · customercare@creditexperts.us<br>{{ $es ? 'Si no reconoce este mensaje, ignórelo.' : 'If you do not recognize this message, please ignore it.' }}
    </td></tr>
  </table>
</td></tr>
</table>
</body>
</html>
