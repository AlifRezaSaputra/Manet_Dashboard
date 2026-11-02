<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('node', function (Blueprint $table) {
            $table->id();
            $table->string('node_id', 32)->unique();
            $table->string('node_name', 100);
            $table->string('node_type', 20)->default('pendaki');
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('node');
    }
};