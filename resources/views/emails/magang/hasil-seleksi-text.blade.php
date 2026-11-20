APTIKA Tools | Hasil seleksi magang
Pendaftaran #{{ $nomor }}

Halo {{ $nama }},

@if ($diterima)
Selamat, pendaftaran magang Anda telah diterima.

Email login: {{ $emailLogin }}
@if ($passwordAwal)
Password awal: {{ $passwordAwal }}
Silakan ganti password setelah masuk.
@else
Gunakan password akun Anda saat ini. Jika tidak bisa masuk, hubungi pengelola magang.
@endif
Masuk ke APTIKA Tools: {{ $login }}

Setelah masuk, buka Magang → Dokumen untuk mengurus NDA. Surat penerimaan tersedia di tab yang sama setelah berkas resmi diunggah.
@else
Terima kasih atas minat Anda mengikuti program magang melalui APTIKA Tools. Setelah peninjauan, pendaftaran magang Anda belum dapat kami terima.

Kami menghargai waktu dan upaya Anda dalam mengajukan pendaftaran. Semoga Anda memperoleh kesempatan magang yang sesuai.
@endif

Tim Pengelola Magang APTIKA Tools
Diskominfo Provinsi Jawa Barat
