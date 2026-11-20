<!doctype html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Hasil seleksi magang | APTIKA Tools</title>
</head>
<body style="margin:0;padding:0;background-color:#f1f5f9;color:#1e293b;font-family:Arial,Helvetica,sans-serif;">
<table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="background-color:#f1f5f9;">
<tr><td align="center" style="padding:24px 12px;">
    <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="max-width:600px;background-color:#ffffff;border:1px solid #e2e8f0;border-radius:16px;">
        <tr>
            <td bgcolor="#142656" style="padding:30px 28px;background-color:#142656;background-image:linear-gradient(125deg,#0f172a,#1e3a8a);border-radius:15px 15px 0 0;">
                <p style="margin:0 0 10px;color:#bfdbfe;font-size:11px;font-weight:bold;letter-spacing:2px;">APTIKA TOOLS &middot; PROGRAM MAGANG</p>
                <h1 style="margin:0;color:#ffffff;font-size:26px;line-height:1.25;">Hasil seleksi magang</h1>
                <p style="margin:10px 0 0;color:#dbeafe;font-size:13px;">Pendaftaran #{{ $nomor }}</p>
            </td>
        </tr>
        <tr><td style="padding:26px 28px 28px;font-size:14px;line-height:1.65;">
            <span style="display:inline-block;padding:4px 10px;border-radius:16px;font-size:12px;font-weight:bold;{{ $diterima ? 'background-color:#dcfce7;color:#166534;' : 'background-color:#f1f5f9;color:#475569;' }}">
                {{ $diterima ? 'Diterima' : 'Belum diterima' }}
            </span>
            <p style="margin:16px 0 8px;">Halo {{ $nama }},</p>
            @if ($diterima)
                <p style="margin:0 0 18px;">Selamat, pendaftaran magang Anda telah diterima.</p>
                <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="background-color:#eff6ff;border:1px solid #bfdbfe;border-radius:12px;">
                    <tr><td style="padding:16px 18px;color:#1e293b;font-size:14px;line-height:1.55;">
                        <h2 style="margin:0 0 10px;color:#1e3a8a;font-size:16px;">Akun APTIKA Tools</h2>
                        <p style="margin:0;"><strong>Email login</strong><br>{{ $emailLogin }}</p>
                        @if ($passwordAwal)
                            <p style="margin:10px 0 0;"><strong>Password awal</strong><br><span style="font-family:monospace;word-break:break-all;">{{ $passwordAwal }}</span></p>
                            <p style="margin:10px 0 0;color:#475569;font-size:13px;">Silakan ganti password setelah masuk.</p>
                        @else
                            <p style="margin:10px 0 0;color:#475569;font-size:13px;">Gunakan password akun Anda saat ini. Jika tidak bisa masuk, hubungi pengelola magang.</p>
                        @endif
                    </td></tr>
                </table>
                <table role="presentation" cellspacing="0" cellpadding="0" border="0" style="margin:18px 0;">
                    <tr><td bgcolor="#1d4ed8" style="border-radius:9px;">
                        <a href="{{ $login }}" style="display:inline-block;padding:11px 18px;color:#ffffff;font-size:14px;font-weight:bold;text-decoration:none;border:1px solid #1d4ed8;border-radius:9px;">Masuk ke APTIKA Tools</a>
                    </td></tr>
                </table>
                <p style="margin:0 0 16px;">Setelah masuk, buka <strong>Magang &rarr; Dokumen</strong> untuk mengurus NDA. Surat penerimaan tersedia di tab yang sama setelah berkas resmi diunggah.</p>
            @else
                <p style="margin:0 0 12px;">Terima kasih atas minat Anda mengikuti program magang melalui APTIKA Tools. Setelah peninjauan, pendaftaran magang Anda belum dapat kami terima.</p>
                <p style="margin:0;">Kami menghargai waktu dan upaya Anda dalam mengajukan pendaftaran. Semoga Anda memperoleh kesempatan magang yang sesuai.</p>
            @endif
        </td></tr>
        <tr><td style="padding:16px 28px;background-color:#f8fafc;border-top:1px solid #e2e8f0;border-radius:0 0 15px 15px;color:#475569;font-size:12px;line-height:1.5;">
            Tim Pengelola Magang APTIKA Tools<br>Diskominfo Provinsi Jawa Barat
        </td></tr>
    </table>
</td></tr>
</table>
</body>
</html>
