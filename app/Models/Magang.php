<?php

namespace App\Models;

use App\Traits\BelongsToBidang;
use Illuminate\Database\Eloquent\Model;

class Magang extends Model
{
    use BelongsToBidang;

    protected $fillable = [
        'kebutuhan_kampus', 'pas_foto', 'foto_latar',
        'user_id', 'email_login', 'lampiran_pendaftaran', 'password_awal',
        'format_penilaian', 'kampus_perlu_input', 'kampus_sudah_input', 'pengingat_hari',
        'jurusan_sesuai', 'riwayat_perubahan',
        'nim', 'jurusan', 'email', 'telepon', 'alamat', 'memiliki_laptop',
        'status_seleksi', 'catatan_seleksi', 'mentor_id', 'diterima_at', 'nomor_nda',
        'nda_validated_at', 'nda_validated_by', 'penilaian', 'catatan_penilaian', 'dinilai_at',
        'nomor_sertifikat', 'sertifikat_file', 'disahkan_at', 'disahkan_by',
        'nama',
        'nama_kampus',
        'tgl_mulai_magang',
        'tgl_selesai_magang',
        'cv_magang',
        'nda_file',
        'sertifikat',
        'keterangan',
        'bidang_id',
    ];

    protected $hidden = ['password_awal'];

    protected $casts = [
        'password_awal' => 'encrypted',
        'kebutuhan_kampus' => 'array',
        'lampiran_pendaftaran' => 'array',
        'format_penilaian' => 'string',
        'kampus_perlu_input' => 'boolean',
        'kampus_sudah_input' => 'boolean',
        'pengingat_hari' => 'array',
        'jurusan_sesuai' => 'boolean',
        'riwayat_perubahan' => 'array',
        'memiliki_laptop' => 'boolean',
        'penilaian' => 'array',
        'diterima_at' => 'datetime',
        'nda_validated_at' => 'datetime',
        'dinilai_at' => 'datetime',
        'disahkan_at' => 'datetime',
        'tgl_mulai_magang' => 'date:Y-m-d',
        'tgl_selesai_magang' => 'date:Y-m-d',
    ];

    protected $appends = [
        'status_magang',
    ];

    protected static function booted(): void
    {
        static::addGlobalScope('peserta_scope', function ($query) {
            if (auth()->user()?->is_magang) {
                $query->where('magangs.user_id', auth()->id());
            }
        });
    }

    public function mentor()
    {
        return $this->belongsTo(User::class, 'mentor_id');
    }

    public function dokumens()
    {
        return $this->hasMany(MagangDokumen::class);
    }

    public function getStatusMagangAttribute()
    {
        if ($this->status_seleksi !== 'diterima') {
            return $this->status_seleksi === 'ditolak' ? 'Ditolak' : 'Menunggu seleksi';
        }

        $today = now('Asia/Jakarta')->format('Y-m-d');

        $start = $this->tgl_mulai_magang ? $this->tgl_mulai_magang->format('Y-m-d') : null;
        $end = $this->tgl_selesai_magang ? $this->tgl_selesai_magang->format('Y-m-d') : null;

        if (! $start || ! $end) {
            return 'Belum mulai';
        }

        if ($today < $start) {
            return 'Belum mulai';
        }

        if ($today > $end) {
            return 'Selesai magang';
        }

        return 'Sedang magang';
    }
}
