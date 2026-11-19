<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class QosTest extends Model
{
    protected $fillable = [
        'test_code', 'name', 'description', 'topology_type',
        'source_node_id', 'destination_node_id',
        'topology_config', 'radio_config',
        'status', 'started_at', 'ended_at', 'duration_seconds', 'notes',
    ];

    protected $casts = [
        'topology_config' => 'array',
        'radio_config'    => 'array',
        'started_at'      => 'datetime',
        'ended_at'        => 'datetime',
    ];

    public function nodes(): HasMany
    {
        return $this->hasMany(QosTestNode::class)->orderBy('sequence_order');
    }

    public function events(): HasMany
    {
        return $this->hasMany(QosEvent::class);
    }

    public function results(): HasMany
    {
        return $this->hasMany(QosResult::class);
    }
}