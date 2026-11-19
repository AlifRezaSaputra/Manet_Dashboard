<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('qos_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('qos_test_id')->constrained('qos_tests')->restrictOnDelete();
            $table->string('event_uuid', 36)->unique();   // dedup saat ingestion
            $table->string('node_id', 30);
            $table->string('event_type', 40);             // PACKET_TX, PACKET_RX, ...
            $table->string('packet_type', 15);            // DATA, ACK, HELLO, RREQ, RREP, RERR
            $table->string('source_id', 30)->nullable();
            $table->string('destination_id', 30)->nullable();
            $table->unsignedBigInteger('boot_id')->nullable();
            $table->unsignedBigInteger('sequence_number')->nullable();
            $table->unsignedBigInteger('origin_timestamp')->nullable(); // millis() node asal
            $table->unsignedBigInteger('event_timestamp')->nullable();  // millis() lokal, BUKAN waktu global
            $table->unsignedSmallInteger('hop_count')->nullable();
            $table->string('next_hop', 30)->nullable();
            $table->unsignedSmallInteger('payload_length')->nullable();
            $table->boolean('has_radio_metrics')->default(false);
            $table->float('rssi')->nullable();
            $table->float('snr')->nullable();
            $table->string('event_status', 20)->default('unknown'); // success, failed, attempt, unknown
            $table->string('drop_reason', 40)->nullable();
            $table->text('raw_event');                    // baris serial asli
            $table->dateTime('collected_at');
            $table->timestamp('created_at')->nullable();  // sengaja tanpa updated_at: event tidak boleh diubah

            $table->index(['qos_test_id', 'event_type']);
            $table->index(['qos_test_id', 'node_id']);
            $table->index(['source_id', 'boot_id', 'sequence_number'], 'qos_events_packet_identity_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('qos_events');
    }
};