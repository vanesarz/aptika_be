<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('magangs', function (Blueprint $table) {
            $table->string('format_penilaian')->nullable();
            $table->boolean('kampus_perlu_input')->default(false);
            $table->boolean('kampus_sudah_input')->default(false);
            $table->json('pengingat_hari')->nullable();
            $table->boolean('jurusan_sesuai')->nullable();
            $table->json('riwayat_perubahan')->nullable();
        });
        Schema::create('magang_dokumens', function (Blueprint $table) {
            $table->id();
            $table->foreignId('magang_id')->constrained('magangs')->cascadeOnDelete();
            $table->string('jenis', 30);
            $table->string('status', 30)->default('diproses');
            $table->string('nomor')->nullable();
            $table->string('pengesah')->nullable();
            $table->string('file')->nullable();
            $table->text('catatan')->nullable();
            $table->foreignId('dicatat_oleh')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['magang_id', 'jenis']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('magang_dokumens');
        Schema::table('magangs', function (Blueprint $table) {
            $table->dropColumn(['format_penilaian', 'kampus_perlu_input', 'kampus_sudah_input',
                'pengingat_hari', 'jurusan_sesuai', 'riwayat_perubahan']);
        });
    }
};
