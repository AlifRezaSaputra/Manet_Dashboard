<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class QosResult extends Model
{
    protected $fillable = [
        'qos_test_id', 'metric_key', 'metric_value', 'unit',
        'sample_count', 'metadata', 'calculated_at',
    ];

    protected $casts = [
        'metric_value'  => 'float',   // null tetap null (= N/A), bukan 0
        'metadata'      => 'array',
        'calculated_at' => 'datetime',
    ];

    public function test(): BelongsTo
    {
        return $this->belongsTo(QosTest::class, 'qos_test_id');
    }
}