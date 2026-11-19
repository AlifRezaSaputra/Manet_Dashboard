<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('qos_test_nodes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('qos_test_id')->constrained('qos_tests')->cascadeOnDelete();
            $table->string('node_id', 30);
            $table->string('node_role', 20);              // source, relay, destination
            $table->unsignedSmallInteger('sequence_order');
            $table->timestamps();

            $table->unique(['qos_test_id', 'node_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('qos_test_nodes');
    }
};