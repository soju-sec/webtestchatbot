<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class KbCategory extends Model
{
    use HasFactory;

    protected $table = 'chatbot_kb_categories';

    protected $fillable = [
        'name',
        'slug',
        'icon',
        'description',
        'sort_order'
    ];

    public function getConnectionName()
    {
        return config('chatbot.database_connection', env('DB_CHATBOT_CONNECTION', null));
    }

    public function templates()
    {
        return $this->hasMany(KbTemplate::class, 'category_id');
    }
}
