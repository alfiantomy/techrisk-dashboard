<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Per-evidence encryption key. Extends EncryptionKey to inherit the base64
 * key mutators and $hidden — only the table and FK change.
 */
class ActionImprovementEvidenceKey extends EncryptionKey
{
    protected $table = 'action_improvement_evidence_keys';

    protected $fillable = [
        'action_improvement_evidence_id',
        'key',
        'salt',
        'method',
    ];

    public function evidence(): BelongsTo
    {
        return $this->belongsTo(ActionImprovementEvidence::class, 'action_improvement_evidence_id');
    }
}
