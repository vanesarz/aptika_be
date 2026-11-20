<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('magangs', function (Blueprint $table) {
            // Nullable identity fields preserve existing registrations for staff review.
            $table->string('nim')->nullable();
            $table->string('jurusan')->nullable();
            $table->string('email')->nullable();
            $table->string('telepon', 30)->nullable();
            $table->text('alamat')->nullable();
            $table->boolean('memiliki_laptop')->default(false);
            $table->string('status_seleksi')->default('menunggu');
            $table->text('catatan_seleksi')->nullable();
            $table->foreignId('mentor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('diterima_at')->nullable();
            $table->string('nomor_nda')->nullable()->unique();
            $table->timestamp('nda_validated_at')->nullable();
            $table->foreignId('nda_validated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->json('penilaian')->nullable();
            $table->text('catatan_penilaian')->nullable();
            $table->timestamp('dinilai_at')->nullable();
            $table->string('nomor_sertifikat')->nullable()->unique();
            $table->string('sertifikat_file')->nullable();
            $table->timestamp('disahkan_at')->nullable();
            $table->foreignId('disahkan_by')->nullable()->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('magangs', function (Blueprint $table) {
            $table->dropConstrainedForeignId('mentor_id');
            $table->dropConstrainedForeignId('nda_validated_by');
            $table->dropConstrainedForeignId('disahkan_by');
            $table->dropColumn(['nim', 'jurusan', 'email', 'telepon', 'alamat', 'memiliki_laptop',
                'status_seleksi', 'catatan_seleksi', 'diterima_at', 'nomor_nda', 'nda_validated_at',
                'penilaian', 'catatan_penilaian', 'dinilai_at', 'nomor_sertifikat', 'sertifikat_file', 'disahkan_at']);
        });
    }
};
