<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sos_record', function (Blueprint $table) {
            $table->id();
            $table->foreignId('node_id')
                ->constrained('node')
                ->restrictOnDelete();

            $table->string('event_type', 30);
            $table->timestamp('event_time');
            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();
            $table->json('payload')->nullable();
            $table->timestamps();

            $table->index(['node_id', 'event_time']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sos_record');
    }
};