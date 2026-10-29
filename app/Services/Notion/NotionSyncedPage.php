<?php

declare(strict_types=1);

namespace App\Services\Notion;

use Illuminate\Database\Eloquent\Model;

class NotionSyncedPage extends Model
{
    protected $fillable = ['page_id', 'incident_id'];
}
