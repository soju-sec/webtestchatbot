<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\KbCategory;
use App\Models\KbSyncLog;
use App\Models\KbTemplate;
use App\Services\TemplateDeduplicationService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

class KbTemplateController extends Controller
{
    /**
     * Mendapatkan daftar template kasus & solusi (dengan filter & pagination).
     */
    public function index(Request $request)
    {
        $query = KbTemplate::with('category')->latest();

        // Filter status
        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        // Filter kategori
        if ($request->filled('category_id')) {
            $query->where('category_id', $request->category_id);
        }

        // Pencarian judul / keyword
        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('case_title', 'like', "%{$search}%")
                  ->orWhere('solution_steps', 'like', "%{$search}%")
                  ->orWhere('trigger_keywords', 'like', "%{$search}%");
            });
        }

        $templates = $query->get();

        $stats = [
            'total'     => KbTemplate::count(),
            'published' => KbTemplate::where('status', 'published')->count(),
            'draft'     => KbTemplate::where('status', 'draft')->count(),
            'archived'  => KbTemplate::where('status', 'archived')->count(),
        ];

        return response()->json([
            'status'    => 'success',
            'stats'     => $stats,
            'data'      => $templates,
        ]);
    }

    /**
     * Pengecekan real-time anti-duplikasi saat PM mengetik kasus/keyword di frontend.
     */
    public function checkDuplicate(Request $request)
    {
        $request->validate([
            'case_title'       => 'required|string',
            'trigger_keywords' => 'nullable|array',
            'exclude_id'       => 'nullable|integer',
        ]);

        $title = $request->input('case_title');
        $keywords = $request->input('trigger_keywords', []);
        $excludeId = $request->input('exclude_id');

        $result = TemplateDeduplicationService::checkDuplicate($title, $keywords, $excludeId);

        return response()->json([
            'status' => 'success',
            'data'   => $result,
        ]);
    }

    /**
     * Menyimpan template kasus & solusi baru.
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'case_title'        => 'required|string|max:255',
            'category_id'       => 'nullable|integer',
            'trigger_keywords'  => 'required|array|min:1',
            'solution_steps'    => 'required|string',
            'escalation_action' => 'nullable|string',
            'jira_issue_type'   => 'nullable|string',
            'jira_priority'     => 'nullable|string',
            'jira_component'    => 'nullable|string',
            'quick_chips'       => 'nullable|array',
            'status'            => 'nullable|string|in:published,draft,archived',
            'force_save'        => 'nullable|boolean',
        ]);

        // Cek anti-duplikasi terlebih dahulu
        $dedup = TemplateDeduplicationService::checkDuplicate(
            $validated['case_title'],
            $validated['trigger_keywords']
        );

        // Jika duplikat terdeteksi dan PM belum konfirmasi force_save
        if ($dedup['is_duplicate'] && empty($request->input('force_save'))) {
            return response()->json([
                'status'     => 'duplicate_detected',
                'message'    => 'Potensi duplikasi template terdeteksi! Template serupa sudah ada di sistem.',
                'dedup_info' => $dedup,
            ], 422);
        }

        // Generate slug unik
        $baseSlug = Str::slug($validated['case_title']);
        $slug = $baseSlug;
        $counter = 1;
        while (KbTemplate::where('slug', $slug)->exists()) {
            $slug = "{$baseSlug}-{$counter}";
            $counter++;
        }

        $template = KbTemplate::create([
            'category_id'       => $validated['category_id'] ?? null,
            'case_title'        => $validated['case_title'],
            'slug'              => $slug,
            'trigger_keywords'  => $validated['trigger_keywords'],
            'solution_steps'    => $validated['solution_steps'],
            'escalation_action' => $validated['escalation_action'] ?? null,
            'jira_issue_type'   => $validated['jira_issue_type'] ?? 'Service Request',
            'jira_priority'     => $validated['jira_priority'] ?? 'Medium',
            'jira_component'    => $validated['jira_component'] ?? null,
            'quick_chips'       => $validated['quick_chips'] ?? [],
            'status'            => $validated['status'] ?? 'published',
            'created_by'        => auth()->user()?->name ?? 'Project Manager',
            'version'           => 1,
        ]);

        // Log audit sinkronisasi
        KbSyncLog::create([
            'template_id'  => $template->id,
            'action'       => 'created',
            'performed_by' => auth()->user()?->name ?? 'Project Manager',
            'details'      => [
                'case_title' => $template->case_title,
                'status'     => $template->status,
                'version'    => 1,
            ],
        ]);

        // Invalidate manifest cache
        Cache::forget('chatbot_manifest_data');

        return response()->json([
            'status'  => 'success',
            'message' => 'Template kasus & solusi berhasil dibuat dan disinkronkan ke n8n Chatbot!',
            'data'    => $template->load('category'),
        ], 201);
    }

    /**
     * Menampilkan detail template.
     */
    public function show($id)
    {
        $template = KbTemplate::with(['category', 'syncLogs'])->findOrFail($id);

        return response()->json([
            'status' => 'success',
            'data'   => $template,
        ]);
    }

    /**
     * Memperbarui template kasus & solusi.
     */
    public function update(Request $request, $id)
    {
        $template = KbTemplate::findOrFail($id);

        $validated = $request->validate([
            'case_title'        => 'sometimes|required|string|max:255',
            'category_id'       => 'nullable|integer',
            'trigger_keywords'  => 'sometimes|required|array|min:1',
            'solution_steps'    => 'sometimes|required|string',
            'escalation_action' => 'nullable|string',
            'jira_issue_type'   => 'nullable|string',
            'jira_priority'     => 'nullable|string',
            'jira_component'    => 'nullable|string',
            'quick_chips'       => 'nullable|array',
            'status'            => 'nullable|string|in:published,draft,archived',
            'force_save'        => 'nullable|boolean',
        ]);

        // Cek anti-duplikasi terhadap template lain
        if (!empty($validated['case_title']) || !empty($validated['trigger_keywords'])) {
            $titleToCheck = $validated['case_title'] ?? $template->case_title;
            $keywordsToCheck = $validated['trigger_keywords'] ?? $template->trigger_keywords;

            $dedup = TemplateDeduplicationService::checkDuplicate($titleToCheck, $keywordsToCheck, $template->id);

            if ($dedup['is_duplicate'] && empty($request->input('force_save'))) {
                return response()->json([
                    'status'     => 'duplicate_detected',
                    'message'    => 'Peringatan: Perubahan ini mirip dengan template lain yang aktif!',
                    'dedup_info' => $dedup,
                ], 422);
            }
        }

        // Update fields
        $template->fill(array_filter([
            'category_id'       => $validated['category_id'] ?? $template->category_id,
            'case_title'        => $validated['case_title'] ?? $template->case_title,
            'trigger_keywords'  => $validated['trigger_keywords'] ?? $template->trigger_keywords,
            'solution_steps'    => $validated['solution_steps'] ?? $template->solution_steps,
            'escalation_action' => array_key_exists('escalation_action', $validated) ? $validated['escalation_action'] : $template->escalation_action,
            'jira_issue_type'   => $validated['jira_issue_type'] ?? $template->jira_issue_type,
            'jira_priority'     => $validated['jira_priority'] ?? $template->jira_priority,
            'jira_component'    => array_key_exists('jira_component', $validated) ? $validated['jira_component'] : $template->jira_component,
            'quick_chips'       => $validated['quick_chips'] ?? $template->quick_chips,
            'status'            => $validated['status'] ?? $template->status,
        ], fn($v) => !is_null($v)));

        $template->version = ($template->version ?? 1) + 1;
        $template->save();

        // Log audit
        KbSyncLog::create([
            'template_id'  => $template->id,
            'action'       => 'updated',
            'performed_by' => auth()->user()?->name ?? 'Project Manager',
            'details'      => [
                'case_title' => $template->case_title,
                'status'     => $template->status,
                'version'    => $template->version,
            ],
        ]);

        // Invalidate manifest cache
        Cache::forget('chatbot_manifest_data');

        return response()->json([
            'status'  => 'success',
            'message' => 'Template berhasil diperbarui & sinkronisasi otomatis n8n aktif!',
            'data'    => $template->load('category'),
        ]);
    }

    /**
     * Menghapus atau mengarsipkan template.
     */
    public function destroy($id)
    {
        $template = KbTemplate::findOrFail($id);

        KbSyncLog::create([
            'template_id'  => $template->id,
            'action'       => 'deleted',
            'performed_by' => auth()->user()?->name ?? 'Project Manager',
            'details'      => [
                'case_title' => $template->case_title,
            ],
        ]);

        $template->delete();

        Cache::forget('chatbot_manifest_data');

        return response()->json([
            'status'  => 'success',
            'message' => 'Template berhasil dihapus dari Knowledge Base!',
        ]);
    }

    /**
     * Memaksa sinkronisasi ulang seluruh template ke Chatbot n8n.
     */
    public function syncChatbot()
    {
        Cache::forget('chatbot_manifest_data');

        $activeTemplates = KbTemplate::where('status', 'published')->count();

        return response()->json([
            'status'            => 'success',
            'message'           => "Sinkronisasi berhasil! {$activeTemplates} template aktif siap digunakan oleh n8n Chatbot.",
            'active_templates'  => $activeTemplates,
            'synced_at'         => now()->toIso8601String(),
        ]);
    }
}
