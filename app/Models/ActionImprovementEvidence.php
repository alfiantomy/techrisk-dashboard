<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * A file proving an action improvement happened (photo/text/pdf, encrypted
 * at rest). NOT Auditable on purpose: file_uploaded/file_deleted/file_
 * downloaded events are audited on the parent Incident (supporting-doc
 * precedent), so auditing here too would double-count.
 */
class ActionImprovementEvidence extends Model
{
    protected $fillable = [
        'action_improvement_id',
        'file_path',
        'original_filename',
    ];

    public function actionImprovement(): BelongsTo
    {
        return $this->belongsTo(ActionImprovement::class);
    }

    public function evidenceKey(): HasOne
    {
        return $this->hasOne(ActionImprovementEvidenceKey::class);
    }
}
