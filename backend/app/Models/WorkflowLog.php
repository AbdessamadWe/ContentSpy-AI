<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WorkflowLog extends Model
{
    public $timestamps = false;
    const CREATED_AT = 'created_at';

    protected $fillable = [
        'site_id', 'workspace_id', 'trigger', 'step', 'status',
        'input', 'output', 'credits_consumed', 'duration_ms', 'error_message',
    ];

    protected $casts = [
        'input'            => 'array',
        'output'           => 'array',
        'credits_consumed' => 'integer',
        'duration_ms'      => 'integer',
        'created_at'       => 'datetime',
    ];

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }
}
