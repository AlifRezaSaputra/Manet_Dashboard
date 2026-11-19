<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

class QosEvent extends Model
{
    public const UPDATED_AT = null; // tabel hanya punya created_at

    protected $fillable = [
        'qos_test_id', 'event_uuid', 'node_id', 'event_type', 'packet_type',
        'source_id', 'destination_id', 'boot_id', 'sequence_number',
        'origin_timestamp', 'event_timestamp', 'hop_count', 'next_hop',
        'payload_length', 'has_radio_metrics', 'rssi', 'snr',
        'event_status', 'drop_reason', 'raw_event', 'collected_at',
    ];

    protected $casts = [
        'has_radio_metrics' => 'boolean',
        'rssi'              => 'float',
        'snr'               => 'float',
        'collected_at'      => 'datetime',
    ];

    protected static function booted(): void
    {
        // Event mentah immutable
        static::updating(fn () => throw new LogicException('QoS event bersifat immutable.'));
        static::deleting(fn () => throw new LogicException('QoS event tidak boleh dihapus.'));
    }

    public function test(): BelongsTo
    {
        return $this->belongsTo(QosTest::class, 'qos_test_id');
    }
}