# Modul magang berdasarkan wawancara Pak Ipul

Modul menghubungkan pendaftaran publik, screening mentor, administrasi surat dan NDA, evaluasi, serta pencatatan dokumen akhir. Penyesuaian 11 November 2026 mengacu pada rangkuman rekaman 48 menit 40 detik. Aturan yang belum final tidak diperlakukan sebagai ketentuan dinas yang sudah disahkan.

## Cara menggunakan

1. Mahasiswa mengisi formulir magang di halaman utama atau langsung melalui /daftar-magang, termasuk nama akun login `nama@magang.com`, email kontak pribadi, CV PDF maksimal 2 MB, surat pengantar sekolah/kampus PDF maksimal 5 MB, dan berkas pendukung opsional. Dokumen opsional awal: surat rekomendasi instansi, proposal, portofolio, dan dokumen tambahan; masing-masing PDF maksimal 5 MB. Daftar serta status wajib dapat diatur melalui `dokumen_pendaftaran` di `config/magang.php`. Persyaratan awal ini dapat disesuaikan dinas; bukan klaim SOP universal. Berkas tambahan disimpan di disk privat dan hanya dapat diunduh lewat API berautentikasi. Jurusan diisi sesuai sekolah/kampus; ketersediaan laptop dapat dinyatakan secara jujur. Pendaftaran tetap berstatus menunggu screening, bukan otomatis diterima. Form ini tidak menggantikan jalur pengajuan DPMPTSP/administrasi yang berlaku.
2. Staf membuka `/magang/dashboard`, lalu ikon mata **Lihat Detail dan Proses Magang**. Pada screening, pilih mentor aktif dari bidang tujuan, tinjau CV, jurusan, laptop, dan periode, kemudian konfirmasikan kesesuaian jurusan sebelum menerima. Untuk bidang berkode APTIKA, laptop diperiksa saat penerimaan. Data yang tidak memenuhi kebutuhan dapat ditolak dengan catatan.
3. Saat mentor menyetujui penerimaan, transaksi yang sama membuat akun peserta dengan email login terdaftar, password awal acak, role pengguna biasa, dan penanda akun magang. Sebelum approval atau setelah penolakan, tidak ada akun login yang dibuat. Nama login direservasi dan tidak dapat memakai akun staf yang sudah ada. Peserta lama yang sudah diterima tetapi belum punya akun dapat diaktifkan oleh mentor/admin melalui tombol pada detail, tanpa mengulang seleksi.

   Setelah penerimaan, data peserta mengisi draf NDA tanpa perlu mengetik ulang. Referensi draf disimpan di database, bukan bergantung pada urutan tabel. Nama/NIP pejabat tidak ditetapkan dari nama lama yang belum diverifikasi. Draf harus dicocokkan dengan template resmi dinas. Unggah NDA yang telah ditandatangani dan catat validasinya.
4. Buka bagian **Administrasi surat** untuk memantau surat penerimaan, surat selesai, dan lembar nilai. Status dapat dicatat sebagai sedang diproses. Untuk mencatat sudah disahkan, admin wajib mengisi nomor, pejabat pengesah sesuai dokumen, dan mengunggah PDF resmi. Surat penerimaan dibuatkan catatan diproses otomatis saat peserta diterima. Surat selesai dibuatkan catatan diproses saat nilai disimpan. Draf sederhana surat penerimaan/selesai dapat dicetak sebagai bahan penyusunan template resmi.
5. Isi nama format penilaian, aspek, nilai, dan skala/keterangan sesuai kebutuhan dinas atau kampus. Tombol **Gunakan referensi Telkom University** mengisi delapan indikator dan bobot dari contoh penilaian pembimbing lapangan KPPM yang diberikan pengguna, tanpa menyalin nilai, nama, NIM, atau tanda tangan peserta pada PDF. Total bobot referensi adalah 45%; nilai terbobot dihitung sebagai nilai × bobot / 100, tidak dinormalisasi menjadi nilai akhir 100%. Nilai pada aspek berbobot harus berupa angka 0–100. Preset merupakan acuan Telkom, bukan rubrik resmi seluruh Diskominfo. Aspek dapat ditambah/dihapus, maksimal 30. Nilai dapat berupa angka atau predikat; tidak ada empat aspek, skala 0–100, bobot, rata-rata, atau batas kelulusan yang dipaksakan. Penilaian dapat disimpan setelah periode selesai.
6. Tandai apakah kampus membutuhkan input/dokumen tambahan dan apakah kewajiban itu sudah selesai. Status kampus dapat diperbarui setelah sertifikat dicatat tanpa mengubah nilai yang telah dikunci. Lembar nilai internal tidak otomatis menggantikan sistem kampus.
7. Cetak lembar nilai dan draf sertifikat bila diperlukan. Admin mencatat nomor resmi dan mengunggah sertifikat yang telah disahkan Kepala Dinas. Pencatatan oleh admin tidak menjalankan atau menggantikan TTE pejabat. Surat lain mencatat pejabat sesuai dokumen, tanpa menganggap seluruhnya harus ditandatangani orang yang sama.
8. Sesuaikan hari pengingat pada detail peserta, misalnya `14, 7` atau `3, 2`. Pengingat tampil sejak ambang hari terbesar sampai akhir periode, lalu tetap menampilkan pekerjaan akhir yang belum selesai. Tampilan tidak mengirim pesan terjadwal pada setiap angka tersebut. Nilai yang sudah terisi tidak menghilangkan pengingat jika sertifikat, surat selesai, atau kewajiban kampus masih tertunda.

## Kebijakan yang dapat dikonfigurasi

`config/magang.php` memuat:

- `kuota_per_mentor`: daftar batas peserta bersamaan menurut ID pengguna mentor. Default kosong; tidak ada batas otomatis 22 untuk semua bidang. Contoh konfigurasi setelah kapasitas mentor dikonfirmasi: `[17 => 22]`. Jika belum ada konfigurasi, antarmuka meminta staf memastikan kapasitas sebelum menerima peserta. Batas yang dikonfigurasi dihitung sebagai puncak peserta diterima dengan periode beririsan; tanggal mulai/akhir inklusif, mentor berbeda tidak memakai kuota yang sama. Penetapan mentor dan pemeriksaan kuota dilakukan dengan transaksi serta penguncian pengguna mentor.
- `jurusan_prioritas`: SI/TI sebagai informasi awal dari wawancara, bukan daftar penolakan otomatis. RPL/TKJ tidak dianggap otomatis memenuhi syarat. Mentor mengonfirmasi kesesuaian penugasan.
- `pengingat_hari`: usulan awal `[14, 7]`, bisa ditimpa per peserta. Angka ini bukan SLA resmi. Daftar kosong menonaktifkan pengingat sebelum selesai; pengingat hari akhir/keterlambatan tetap ada.
- `nda_wajib_sebelum_penilaian`: default `false` karena rekaman tidak menetapkan mandatory gating. Jika kebijakan dinas kemudian mengharuskan, nilai `true` mengaktifkan prasyarat NDA tervalidasi pada penilaian dan pencatatan sertifikat. Tidak ada perubahan autentikasi atau akses proyek pada modul lain.

Format, aspek, dan skala penilaian disimpan per peserta. Data penilaian lama dengan empat key tetap dapat dibaca, dikonversi pada respons API menjadi baris aspek tanpa menghapus nilai lama. Penyimpanan berikutnya memakai format fleksibel yang telah dipilih pengguna.

## Koreksi dan konsistensi dokumen

Data peserta tidak dikunci semata-mata karena penerimaan. Mentor terkait atau admin dapat memperbaiki data. Koreksi identitas/periode setelah seleksi memerlukan alasan, direkam bersama waktu, pengguna, dan kolom yang diubah. Perubahan tersebut membatalkan validasi NDA dan menandai surat yang telah dibuat sebagai perlu revisi. Perubahan periode diperiksa kembali terhadap kuota mentor yang telah dikonfigurasi. Bidang peserta tidak dapat dipindahkan setelah seleksi.

Identitas dan periode pada penilaian yang telah disimpan tidak dapat diubah melalui koreksi biasa agar hasil penilaian tidak berganti makna. Setelah sertifikat disahkan, koreksi data melalui form ini dikunci. Penilaian yang telah disahkan melalui sertifikat juga tidak dapat ditimpa. Mengubah penilaian sebelum sertifikat disahkan menandai lembar nilai yang sebelumnya dicatat sebagai perlu revisi. Dokumen disahkan tidak dapat ditimpa langsung; koreksi mengikuti proses peninjauan yang terpisah.

Status peserta aktif/selesai dihitung dari penerimaan dan periode, terpisah dari kelengkapan NDA. Mengunggah NDA pengganti membatalkan validasi NDA sebelumnya, namun tidak menghapus nilai.

## Urutan dashboard dan tampilan peserta

Daftar awal diurutkan menurut `created_at` pendaftaran terbaru, dengan ID sebagai penentu urutan jika waktu sama. Perubahan data tidak mengubah tanggal pendaftaran. Pilihan sortir menyediakan terbaru, terlama, nama A–Z/Z–A, serta kampus A–Z/Z–A. Filter status sertifikat dihapus; filter bidang dan status magang tetap tersedia.

Detail layar penuh dibagi menjadi Ringkasan, Proses magang, Dokumen, dan Penilaian. Foto, identitas, periode, mentor, akun, dan waktu pendaftaran tampil pada ringkasan. Form pendaftaran publik dan form tambah peserta internal menggunakan komponen yang sama agar persyaratan dan tampilannya konsisten.

## Pas foto peserta

Pendaftar dapat menambahkan pas foto JPG/PNG maksimal 2 MB dengan petunjuk latar merah/biru tanpa pilihan warna pada form. Antarmuka menampilkan pratinjau; berkas asli tidak diedit atau diubah warnanya. Sistem memvalidasi tipe/ukuran file, bukan otomatis menilai pakaian formal atau warna sebenarnya pada gambar; mentor memeriksa kesesuaian foto.

Foto pendaftaran disimpan pada disk privat dan hanya dapat dibaca oleh pengguna berwenang melalui API magang. Ketika akun dibuat setelah approval atau melalui aktivasi peserta lama, foto disalin menjadi avatar akun pada penyimpanan profil yang sudah digunakan aplikasi. Nilai `avatar` dan `avatar_url` mengikuti mekanisme profil yang tersedia. Akun/pendaftaran lama tanpa foto tetap dapat dibaca. Saat peserta dihapus, foto pendaftaran privat ikut dibersihkan; avatar akun juga dibersihkan.

## Penghapusan peserta

Mentor terkait atau admin yang berwenang dapat menghapus peserta berstatus menunggu, ditolak, maupun diterima melalui dialog konfirmasi. Penghapusan membersihkan data dan berkas magang terkait. Jika peserta mempunyai akun magang, akun tersebut ikut dihapus dan seluruh token loginnya dicabut. Relasi data akun mengikuti aturan foreign key database yang tersedia. Peserta magang tidak dapat menghapus data peserta.

## Hak akses

Autentikasi, role, isolasi bidang, dan layanan memakai sistem yang sudah ada. Mentor terkait dan admin bidang dapat melakukan tindakan pada peserta bidangnya; Admin Aptika mengikuti akses lintas bidang yang sudah tersedia. Staf dapat memilih mentor aktif pada bidang peserta saat screening. Admin bertugas mencatat PDF yang telah disahkan pejabat, bukan otomatis bertindak sebagai Kabid/Kadis.

Keputusan pengguna selanjutnya menetapkan akun peserta dibuat setelah approval mentor, dengan akses fitur hanya MAGANG dan MANAJEMEN_TUGAS. Pembatasan diterapkan pada middleware API dan navigasi frontend, tidak hanya menyembunyikan menu. Endpoint magang peserta hanya menampilkan miliknya; tindakan seleksi, nilai, pengesahan, pengaturan, dan administrasi tidak dapat dijalankan peserta. Peserta dapat membaca berkas/hasil penilaian miliknya dan mengunggah NDA bertanda tangan. Profil pribadi dan logout tetap tersedia sebagai fungsi akun. Role baru tidak ditambahkan; penanda `is_magang` tidak mengubah akun staf. Hak akses tugas mengikuti peran board yang sudah tersedia, dengan pembatasan bidang pada board untuk akun magang. Akun sekretariat khusus dan matriks kewenangan Kabid/Kadis masih mengikuti konfirmasi dinas.

## Lokasi pengaturan akses peserta

Batas layanan peserta saat ini ditetapkan dalam kode, belum ada pengaturan layanan per peserta pada antarmuka admin:

- `app/Http/Middleware/RestrictMagangAccount.php`: pembatasan endpoint API akun `is_magang`, termasuk tindakan yang diizinkan pada modul magang.
- `routes/api.php`, endpoint `GET /me`: daftar layanan peserta hanya `MAGANG` dan `MANAJEMEN_TUGAS`, sekaligus mengikuti status layanan bidang.
- Frontend `src/components/magang/MagangAccessGuard.tsx`, `src/components/layout/Sidebar.tsx`, dan `Header.tsx`: pembatasan halaman serta menu peserta.

Menu admin **Layanan Bidang** (`/admin/bidang-services`) mengatur layanan untuk bidang secara umum. Mengaktifkan layanan lain untuk bidang tidak menambah akses peserta melewati batas dua layanan di atas. `config/magang.php` mengatur persyaratan, kuota, rubrik, dan pengingat; belum menjadi tempat konfigurasi daftar layanan peserta. Jika batas layanan diubah kemudian, aturan API dan navigasi perlu disesuaikan bersama.

## Manajemen akun pada admin

Halaman `/admin/users` memisahkan tab **Staf/Karyawan** dan **Peserta Magang** berdasarkan `users.is_magang`, bukan domain email atau role. Setiap tab memiliki hitungan total/aktif/nonaktif, pencarian, filter bidang, serta tabelnya sendiri. Hitungan mengikuti cakupan bidang admin dan tidak berubah karena filter pencarian. Filter dibersihkan ketika berganti tab. Tab Sesi Aktif tetap tersedia bagi Admin Aptika.

Tambah akun manual tersedia pada tab staf. Tab peserta menyediakan tautan ke dashboard magang karena akun peserta dibuat melalui approval mentor. Pendaftar menunggu/ditolak yang belum memiliki akun tidak tampil di manajemen pengguna. Akun magang yang telah dinonaktifkan tetap berada pada tab peserta. Avatar akun ditampilkan jika tersedia.

## Batas implementasi

- Pengingat tersedia di dashboard. WhatsApp dan Telegram belum dihubungkan; tidak ada vendor, biaya, atau kredensial yang diasumsikan.
- Template resmi dinas, pola penomoran surat, dan integrasi TTE belum dikonfirmasi. Draf tidak diberi tanda tangan/nomor resmi palsu.
- Sertifikat dan lembar nilai dapat dicetak/disimpan sebagai PDF melalui dialog browser. Penggabungan menjadi satu file dua halaman belum diwajibkan karena pengemasan dokumen belum final.
- Status sertifikat lama `Sudah menerima` dipakai untuk kompatibilitas pencatatan dokumen tersedia, bukan bukti mahasiswa telah mengunduhnya.
- Angka 80% dan 7–10 langkah dari wawancara merupakan perkiraan narasumber, bukan metrik keberhasilan yang sudah dibuktikan aplikasi.
- Migrasi lanjutan menambah kolom dan tabel khusus magang tanpa mengubah seeder, logging, autentikasi, atau tabel modul lain. Data lama dipertahankan. Detail peserta dibuka dalam modal layar penuh dengan tombol perkecil/perbesar. Tabel utama hanya memuat nomor, nama, kampus, bidang, periode, status, dan aksi; NDA, sertifikat, penilaian, serta berkas berada pada detail.

## Instalasi dan verifikasi

Dari root backend, jalankan hanya migrasi modul yang belum diterapkan:

```sh
php artisan migrate --path=database/migrations/2026_11_10_000001_add_workflow_to_magangs_table.php
php artisan migrate --path=database/migrations/2026_11_11_000001_refine_magang_interview_workflow.php
php artisan migrate --path=database/migrations/2026_11_11_000002_add_magang_accounts_and_registration_files.php
php artisan migrate --path=database/migrations/2026_11_11_000003_add_pas_foto_to_magangs_table.php
php artisan test --filter=MagangWorkflowTest
```

Pengujian memakai SQLite dalam memori serta migrasi nyata yang relevan. Migrasi lama modul lain yang mengandung SQL khusus MySQL tidak dijalankan suite ini. Database lokal/produksi tidak dipakai oleh pengujian.

Dari root frontend:

```sh
npx tsc --noEmit
npx eslint src/components/magang src/services/magangWorkflow.ts
node src/components/magang/sortMagang.test.mjs
npm run build
```

## Pemberitahuan penerimaan dan kebutuhan kampus

Approval mentor mengirim pemberitahuan ke email pribadi pendaftaran, berisi email login `@magang.com`, password awal, tautan login dari `FRONTEND_URL`, dan arahan mengurus NDA pada tab Dokumen. Surat penerimaan resmi dapat diakses setelah diunggah pengelola. Email tidak dianggap surat resmi. Gunakan mailer SMTP/layanan email terkonfigurasi (`MAIL_MAILER`, `MAIL_HOST`, `MAIL_PORT`, `MAIL_USERNAME`, `MAIL_PASSWORD`, `MAIL_FROM_ADDRESS`). Mailer log/array tidak mengirim ke peserta; API mengembalikan peringatan, demikian pula jika transport gagal. Pengelola dapat mengirim ulang dari Proses magang. Persetujuan dan akun tidak dibatalkan karena kegagalan email. Pengiriman ulang menyertakan password awal selama masih berlaku. Jika sudah diganti, email mengarahkan peserta menggunakan password saat ini.

Pengingat periode hanya tampil pada dashboard/detail, tidak mengirim pesan terjadwal. Format penilaian dapat dipilih melalui dropdown Telkom atau format kampus/lainnya; aspek juga menawarkan indikator referensi dan input aspek khusus. Kebutuhan kampus disimpan terpisah dari nilai sehingga dapat dicatat sebelum periode selesai: tautan HTTP(S), panduan hingga 10.000 karakter, dan dokumen PDF/DOC/DOCX maksimal 10 MB pada penyimpanan privat. Pengelola menyimpan kebutuhan ini; peserta dapat membacanya. Unggahan pengganti mengganti dokumen pedoman sebelumnya.

Migrasi tambahan: `php artisan migrate --path=database/migrations/2026_11_11_000004_add_campus_requirements_to_magangs.php`.

### Mengaktifkan SMTP

Isi `.env` lokal dengan kredensial layanan pengirim yang dipilih. Jangan memasukkan password ke Git atau chat. Variabel yang diperlukan: `MAIL_MAILER=smtp`, `MAIL_SCHEME` sesuai layanan (`smtp` untuk STARTTLS atau `smtps` untuk TLS langsung), `MAIL_HOST`, `MAIL_PORT`, `MAIL_USERNAME`, `MAIL_PASSWORD`, `MAIL_FROM_ADDRESS`, `MAIL_FROM_NAME`, dan `FRONTEND_URL` alamat frontend yang bisa diakses penerima. Host, port, dan kredensial mengikuti penyedia email, bukan alamat login peserta `@magang.com`.

Setelah pengisian, jalankan `php artisan config:clear`, lalu `php artisan magang:email-check`. Untuk menguji pengiriman nyata, gunakan `php artisan magang:email-check --to=alamat-tujuan`. Perintah tidak menampilkan kredensial dan tidak mengubah akun peserta. Keberhasilan SMTP berarti server menerima pesan; penerima tetap perlu memeriksa inbox/spam. Setelah email uji diterima, gunakan tombol kirim ulang pada peserta terkait. Tidak perlu mengulang approval atau membuat ulang akun.

### Email penolakan

Keputusan `ditolak` mengirim email hasil seleksi ke email pribadi pendaftar setelah transaksi disimpan. Email menggunakan pemberitahuan sopan tanpa alasan yang diasumsikan, tanpa catatan seleksi internal, dan tanpa kredensial akun. Keputusan tetap tersimpan jika SMTP gagal; API mengembalikan peringatan. Tab Proses magang menyediakan kirim ulang khusus pendaftar ditolak untuk pengelola yang berwenang. Pengiriman ulang tidak membuat akun.

### Bukti pendaftaran PDF

Ekspor menggunakan header biru tua, referensi pendaftaran, label menunggu verifikasi, kelompok identitas/rencana magang/berkas/langkah berikutnya, serta footer nomor halaman. Teks panjang membungkus dan tabel berlanjut ke halaman berikutnya. Bukti bukan surat penerimaan. PDF dibuat lokal di browser dari ringkasan pendaftaran berhasil, tanpa mengirim data ke layanan ekspor eksternal.
