<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('battery_status', function (Blueprint $table) {
            $table->id();
            $table->foreignId('node_id')
                ->constrained('node')
                ->restrictOnDelete();

            $table->decimal('voltage', 5, 2);
            $table->unsignedTinyInteger('battery_percent')->nullable();
            $table->timestamp('recorded_at');
            $table->timestamps();

            $table->index(['node_id', 'recorded_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('battery_status');
    }
};