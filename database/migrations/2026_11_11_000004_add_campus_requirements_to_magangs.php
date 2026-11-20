<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
    public function up(): void { Schema::table('magangs', function (Blueprint $table) { $table->json('kebutuhan_kampus')->nullable(); }); }
    public function down(): void { Schema::table('magangs', function (Blueprint $table) { $table->dropColumn('kebutuhan_kampus'); }); }
};
