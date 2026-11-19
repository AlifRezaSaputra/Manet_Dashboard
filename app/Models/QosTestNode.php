<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class QosTestNode extends Model
{
    protected $fillable = ['qos_test_id', 'node_id', 'node_role', 'sequence_order'];

    public function test(): BelongsTo
    {
        return $this->belongsTo(QosTest::class, 'qos_test_id');
    }
}