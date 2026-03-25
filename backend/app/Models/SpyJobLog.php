<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SpyJobLog extends Model
{
    public $timestamps = false;
    const CREATED_AT = 'created_at';

    protected $fillable = [
        'competitor_id', 'workspace_id', 'method', 'status',
        'new_detections', 'credits_consumed', 'duration_ms', 'error_message',
    ];

    protected $casts = [
        'new_detections'   => 'integer',
        'credits_consumed' => 'integer',
        'duration_ms'      => 'integer',
        'created_at'       => 'datetime',
    ];

    public function competitor(): BelongsTo
    {
        return $this->belongsTo(Competitor::class);
    }
}
