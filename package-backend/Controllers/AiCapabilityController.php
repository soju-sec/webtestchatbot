<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\KbTemplate;
use Illuminate\Support\Facades\Cache;

class AiCapabilityController extends Controller
{
    /**
     * Mengembalikan manifest kapabilitas AI (Intent & Dynamic Knowledge Base Templates).
     * Endpoint ini menyajikan definisi aksi/intent untuk AI Intent Parser di n8n.
     */
    public function manifest()
    {
        // Cache manifest selama 10 menit untuk efisiensi n8n, di-bust seketika saat ada template baru di CMS
        $data = Cache::remember('chatbot_manifest_data', 600, function () {
            $endpoints = config('ai_registry.endpoints', []);

            $cleanEndpoints = array_map(function ($endpoint) {
                return [
                    'action'       => $endpoint['action'] ?? '',
                    'method'       => $endpoint['method'] ?? 'GET',
                    'path'         => $endpoint['path'] ?? '',
                    'description'  => $endpoint['description'] ?? '',
                    'params'       => $endpoint['params'] ?? [],
                    'current_data' => $endpoint['current_data'] ?? null,
                ];
            }, $endpoints);

            // Ambil template aktif dari CMS Knowledge Base
            $dbTemplates = [];
            try {
                if (class_exists(KbTemplate::class)) {
                    $templates = KbTemplate::with('category')
                        ->where('status', 'published')
                        ->get();

                    foreach ($templates as $tpl) {
                        $key = str_replace('-', '_', $tpl->slug);
                        $stepsArray = array_values(array_filter(array_map('trim', explode("\n", $tpl->solution_steps))));

                        $dbTemplates[$key] = [
                            'id'              => $tpl->id,
                            'topic'           => $tpl->case_title,
                            'category'        => $tpl->category?->name ?? 'Umum',
                            'keywords'        => $tpl->trigger_keywords ?? [],
                            'steps'           => $stepsArray,
                            'raw_solution'    => $tpl->solution_steps,
                            'escalation'      => $tpl->escalation_action,
                            'jira_issue_type' => $tpl->jira_issue_type ?? 'Service Request',
                            'jira_priority'   => $tpl->jira_priority ?? 'Medium',
                            'jira_component'  => $tpl->jira_component,
                            'quick_chips'     => $tpl->quick_chips ?? [],
                            'version'         => $tpl->version,
                        ];
                    }
                }
            } catch (\Throwable $e) {
                // Fallback jika migrasi belum jalan
            }

            // Gabungkan dengan template static jika belum tercover
            $staticTemplates = config('ai_registry.support_templates', []);
            $mergedSupportTemplates = !empty($dbTemplates) ? $dbTemplates : $staticTemplates;

            return [
                'status'                  => 'success',
                'endpoints'               => $cleanEndpoints,
                'support_templates'       => $mergedSupportTemplates,
                'dynamic_templates_count' => count($dbTemplates),
                'synced_at'               => now()->toIso8601String(),
            ];
        });

        return response()->json($data);
    }
}
