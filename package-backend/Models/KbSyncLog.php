<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class KbSyncLog extends Model
{
    use HasFactory;

    protected $table = 'chatbot_kb_sync_logs';

    protected $fillable = [
        'template_id',
        'action',
        'performed_by',
        'details'
    ];

    protected $casts = [
        'details' => 'array'
    ];

    public function getConnectionName()
    {
        return config('chatbot.database_connection', env('DB_CHATBOT_CONNECTION', null));
    }

    public function template()
    {
        return $this->belongsTo(KbTemplate::class, 'template_id');
    }
}
