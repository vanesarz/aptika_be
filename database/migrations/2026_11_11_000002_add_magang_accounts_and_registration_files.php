<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('is_magang')->default(false);
        });
        Schema::table('magangs', function (Blueprint $table) {
            $table->foreignId('user_id')->nullable()->unique()->constrained('users')->nullOnDelete();
            $table->string('email_login')->nullable()->unique();
            $table->json('lampiran_pendaftaran')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('magangs', function (Blueprint $table) {
            $table->dropConstrainedForeignId('user_id');
            $table->dropColumn(['email_login', 'lampiran_pendaftaran']);
        });
        Schema::table('users', fn (Blueprint $table) => $table->dropColumn('is_magang'));
    }
};
