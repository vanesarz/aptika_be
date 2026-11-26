<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('notifications', function (Blueprint $table) {
            $table->foreignId('magang_id')
                ->nullable()
                ->after('task_id')
                ->constrained('magangs')
                ->nullOnDelete();
            $table->index(['user_id', 'type', 'magang_id'], 'notifications_user_type_magang_index');
        });
    }

    public function down(): void
    {
        Schema::table('notifications', function (Blueprint $table) {
            $table->dropIndex('notifications_user_type_magang_index');
            $table->dropConstrainedForeignId('magang_id');
        });
    }
};
