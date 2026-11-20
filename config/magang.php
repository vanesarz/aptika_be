<?php

return [
    'frontend_url' => env('FRONTEND_URL', 'http://localhost:3000'),
    // The interview mentions 22 as Pak Ipul's capacity, not a dinas-wide rule.
    // Configure confirmed limits by mentor user ID, for example: 17 => 22.
    'kuota_per_mentor' => [],
    'dokumen_pendaftaran' => [
        'surat_pengantar' => ['label' => 'Surat pengantar sekolah/kampus', 'wajib' => true],
        'surat_rekomendasi' => ['label' => 'Surat rekomendasi instansi', 'wajib' => false],
        'proposal' => ['label' => 'Proposal magang', 'wajib' => false],
        'portofolio' => ['label' => 'Portofolio', 'wajib' => false],
        'dokumen_tambahan' => ['label' => 'Dokumen pendukung lainnya', 'wajib' => false],
    ],
    'rubrik_telkom' => [
        ['aspek' => 'Kehadiran tepat waktu', 'bobot' => 5],
        ['aspek' => 'Kedisiplinan (kesesuaian dengan aturan)', 'bobot' => 5],
        ['aspek' => 'Komitmen terhadap tugas/pekerjaan', 'bobot' => 5],
        ['aspek' => 'Mahasiswa mampu merencanakan penyelesaian tugas atau pekerjaan, bekerja efektif, dan mandiri selama KP', 'bobot' => 5],
        ['aspek' => 'Mahasiswa mampu bekerjasama di dalam tim organisasi/perusahaan selama KP', 'bobot' => 10],
        ['aspek' => 'Frekuensi bimbingan dengan pembimbing lapangan', 'bobot' => 5],
        ['aspek' => 'Kualitas Laporan KP', 'bobot' => 5],
        ['aspek' => 'Identifikasi dan Formulasi Masalah', 'bobot' => 5],
    ],
    'jurusan_prioritas' => ['SI', 'TI'],
    // Proposed reminders, editable for each participant; not an official SLA.
    'pengingat_hari' => [14, 7],
    // The interview does not establish mandatory account/project/grade blocking.
    'nda_wajib_sebelum_penilaian' => false,
];
