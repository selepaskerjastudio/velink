<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BackupSetting extends Model
{
    public const SCHEDULES = ['off', 'daily', 'weekly', 'monthly'];

    /** Maps schedule setting → cron expression (5-field). */
    public const SCHEDULE_CRON = [
        'daily' => '0 2 * * *',
        'weekly' => '0 2 * * 0',
        'monthly' => '0 2 1 * *',
    ];

    protected $fillable = [
        'application_id',
        'schedule',
        'retention_count',
        'include_database',
        'include_files',
        'storage_local',
        'storage_s3',
    ];

    /** @var array<string, mixed> */
    protected $attributes = [
        'schedule' => 'off',
        'retention_count' => 7,
        'include_database' => true,
        'include_files' => true,
        'storage_local' => true,
        'storage_s3' => false,
    ];

    protected $casts = [
        'retention_count' => 'integer',
        'include_database' => 'boolean',
        'include_files' => 'boolean',
        'storage_local' => 'boolean',
        'storage_s3' => 'boolean',
    ];

    /**
     * @return BelongsTo<Application, self>
     */
    public function application(): BelongsTo
    {
        return $this->belongsTo(Application::class);
    }
}
