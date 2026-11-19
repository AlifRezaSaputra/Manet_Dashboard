<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('qos_tests', function (Blueprint $table) {
            $table->id();
            $table->string('test_code', 50)->unique();
            $table->string('name', 150);
            $table->text('description')->nullable();
            $table->string('topology_type', 30);          // direct, one_relay, two_relays
            $table->string('source_node_id', 30);
            $table->string('destination_node_id', 30)->default('BASE');
            $table->json('topology_config');              // jalur yang direncanakan
            $table->json('radio_config')->nullable();     // SF, BW, CR, preamble, dst.
            $table->string('status', 20)->default('pending'); // pending, recording, completed, failed
            $table->dateTime('started_at')->nullable();
            $table->dateTime('ended_at')->nullable();
            $table->unsignedBigInteger('duration_seconds')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('qos_tests');
    }
};