<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('qos_results', function (Blueprint $table) {
            $table->id();
            $table->foreignId('qos_test_id')->constrained('qos_tests')->cascadeOnDelete();
            $table->string('metric_key', 60);
            $table->decimal('metric_value', 18, 6)->nullable(); // NULL = N/A, berbeda dari 0
            $table->string('unit', 20)->nullable();
            $table->unsignedBigInteger('sample_count')->default(0);
            $table->json('metadata')->nullable();               // versi rumus, asumsi, batasan
            $table->dateTime('calculated_at');
            $table->timestamps();

            $table->unique(['qos_test_id', 'metric_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('qos_results');
    }
};