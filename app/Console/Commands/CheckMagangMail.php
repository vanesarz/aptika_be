<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Mail;

class CheckMagangMail extends Command
{
    protected $signature = 'magang:email-check {--to= : Alamat tujuan email uji}';
    protected $description = 'Periksa SMTP pemberitahuan magang tanpa menampilkan kredensial';

    public function handle(): int
    {
        if (config('mail.default') !== 'smtp') {
            $this->error('Pengiriman SMTP belum aktif. Isi MAIL_MAILER=smtp dan konfigurasi SMTP pada .env.');
            return self::FAILURE;
        }
        $missing = [];
        foreach (['host', 'username', 'password'] as $key) {
            if (blank(config('mail.mailers.smtp.'.$key))) $missing[] = 'MAIL_'.strtoupper($key);
        }
        $from = config('mail.from.address');
        if (! filter_var($from, FILTER_VALIDATE_EMAIL) || $from === 'hello@example.com') $missing[] = 'MAIL_FROM_ADDRESS';
        if ($missing) {
            $this->error('Lengkapi: '.implode(', ', $missing));
            return self::FAILURE;
        }
        $this->info('Konfigurasi dasar SMTP terisi. Kredensial belum diverifikasi sampai email uji dikirim.');
        if (! $this->option('to')) {
            $this->line('Kirim email uji: php artisan magang:email-check --to=alamat-tujuan');
            return self::SUCCESS;
        }
        if (! filter_var($this->option('to'), FILTER_VALIDATE_EMAIL)) {
            $this->error('Alamat tujuan tidak valid.');
            return self::FAILURE;
        }
        try {
            Mail::raw('Ini email uji pengiriman pemberitahuan magang APTIKA Tools. Tidak ada akun peserta yang diubah.', function ($message) {
                $message->to($this->option('to'))->subject('Uji email magang APTIKA Tools');
            });
            $this->info('Server SMTP menerima email uji. Periksa inbox dan folder spam penerima.');
            return self::SUCCESS;
        } catch (\Throwable $e) {
            $this->error('SMTP gagal. Periksa host/port, kredensial aplikasi, izin SMTP, dan koneksi. Detail kredensial tidak ditampilkan.');
            return self::FAILURE;
        }
    }
}
