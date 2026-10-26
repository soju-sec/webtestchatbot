<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class KbTemplate extends Model
{
    use HasFactory;

    protected $table = 'chatbot_kb_templates';

    protected $fillable = [
        'category_id',
        'case_title',
        'slug',
        'trigger_keywords',
        'solution_steps',
        'escalation_action',
        'jira_issue_type',
        'jira_priority',
        'jira_component',
        'quick_chips',
        'status',
        'version',
        'usage_count',
        'resolved_count',
        'created_by'
    ];

    protected $casts = [
        'trigger_keywords' => 'array',
        'quick_chips'      => 'array',
        'version'          => 'integer',
        'usage_count'      => 'integer',
        'resolved_count'   => 'integer',
    ];

    public function getConnectionName()
    {
        return config('chatbot.database_connection', env('DB_CHATBOT_CONNECTION', null));
    }

    public function category()
    {
        return $this->belongsTo(KbCategory::class, 'category_id');
    }

    public function syncLogs()
    {
        return $this->hasMany(KbSyncLog::class, 'template_id');
    }
}
