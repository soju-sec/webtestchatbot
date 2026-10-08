<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ConversationMemoryController extends Controller
{
    public function getMessages(Request $request, string $conversationId)
    {
        $limit = min((int) $request->query('limit', 12), 30);
        $messages = DB::table('conversation_messages')
            ->where('conversation_id', $conversationId)
            ->orderBy('id', 'desc')
            ->limit($limit)
            ->get(['role', 'message', 'created_at'])
            ->reverse()
            ->values();

        return response()->json(['messages' => $messages]);
    }

    public function storeMessage(Request $request, string $conversationId)
    {
        $data = $request->validate([
            'user_id'  => 'required|string',
            'role'     => 'required|in:user,assistant',
            'message'  => 'required|string',
            'channel'  => 'nullable|string',
            'metadata' => 'nullable|array',
        ]);

        $id = DB::table('conversation_messages')->insertGetId([
            'conversation_id' => $conversationId,
            'user_id'         => $data['user_id'],
            'role'            => $data['role'],
            'message'         => $data['message'],
            'channel'         => $data['channel'] ?? 'web',
            'metadata'        => isset($data['metadata']) ? json_encode($data['metadata']) : null,
            'created_at'      => now(),
        ]);

        return response()->json(['status' => 'success', 'id' => $id], 201);
    }
}
