<?php

namespace App\Services;

use App\Models\KbTemplate;
use Illuminate\Support\Str;

class TemplateDeduplicationService
{
    /**
     * Memeriksa potensi duplikasi template baru atau hasil update
     * berdasarkan kemiripan judul kasus dan tabrakan kata kunci (keywords).
     *
     * @param string $caseTitle Judul kasus baru
     * @param array $triggerKeywords Daftar kata kunci pemicu
     * @param int|null $excludeId ID template yang sedang di-edit (jika update)
     * @return array
     */
    public static function checkDuplicate(string $caseTitle, array $triggerKeywords = [], ?int $excludeId = null): array
    {
        $caseTitle = trim($caseTitle);
        $normalizedTitle = self::normalizeText($caseTitle);

        // Ambil semua template yang aktif (bukan yang sedang di-edit)
        $query = KbTemplate::query();
        if ($excludeId) {
            $query->where('id', '!=', $excludeId);
        }
        $existingTemplates = $query->get();

        $conflicts = [];
        $maxSimilarity = 0;

        $inputKeywordsClean = array_map([self::class, 'normalizeText'], $triggerKeywords);
        $inputKeywordsClean = array_filter($inputKeywordsClean);

        foreach ($existingTemplates as $tpl) {
            $existingTitleClean = self::normalizeText($tpl->case_title);

            // 1. Hitung kemiripan judul kasus (similar_text & Levenshtein)
            similar_text($normalizedTitle, $existingTitleClean, $titleSimilarityPercent);

            // 2. Hitung tabrakan kata kunci (Keyword overlap)
            $existingKeywords = is_array($tpl->trigger_keywords) ? $tpl->trigger_keywords : [];
            $existingKeywordsClean = array_map([self::class, 'normalizeText'], $existingKeywords);
            $existingKeywordsClean = array_filter($existingKeywordsClean);

            $matchedKeywords = array_intersect($inputKeywordsClean, $existingKeywordsClean);
            $keywordCollisionCount = count($matchedKeywords);

            // Jaccard similarity untuk kata kunci
            $unionKeywords = array_unique(array_merge($inputKeywordsClean, $existingKeywordsClean));
            $keywordJaccard = count($unionKeywords) > 0 ? ($keywordCollisionCount / count($unionKeywords)) * 100 : 0;

            // 3. Cek kemiripan kata di dalam judul (Token overlap)
            $inputWords = explode(' ', $normalizedTitle);
            $existingWords = explode(' ', $existingTitleClean);
            $commonWords = array_intersect($inputWords, $existingWords);
            // Abaikan stop words umum
            $commonWords = array_diff($commonWords, ['dan', 'atau', 'ke', 'di', 'dari', 'yang', 'untuk', 'pada', 'bisa', 'tidak']);
            $wordOverlapScore = count($commonWords) >= 2 ? 60 : (count($commonWords) == 1 ? 30 : 0);

            // Skor gabungan terbobot
            // 40% kemiripan judul, 40% tabrakan kata kunci, 20% token overlap
            $compositeScore = round(
                ($titleSimilarityPercent * 0.4) +
                ($keywordJaccard * 0.4) +
                ($wordOverlapScore * 0.2)
            );

            // Jika kemiripan judul sangat tinggi (>= 75%), atau ada tabrakan kata kunci >= 2
            if ($titleSimilarityPercent >= 75) {
                $compositeScore = max($compositeScore, (int)$titleSimilarityPercent);
            }
            if ($keywordCollisionCount >= 2) {
                $compositeScore = max($compositeScore, 80);
            }

            if ($compositeScore >= 40 || $keywordCollisionCount >= 1 || $titleSimilarityPercent >= 60) {
                $maxSimilarity = max($maxSimilarity, $compositeScore);

                $conflicts[] = [
                    'template_id'           => $tpl->id,
                    'case_title'            => $tpl->case_title,
                    'slug'                  => $tpl->slug,
                    'status'                => $tpl->status,
                    'similarity_percent'    => min(100, $compositeScore),
                    'title_similarity'      => round($titleSimilarityPercent, 1),
                    'matched_keywords'      => array_values($matchedKeywords),
                    'matched_keyword_count' => $keywordCollisionCount,
                    'overlapping_words'     => array_values($commonWords),
                ];
            }
        }

        // Urutkan konflik dari yang paling mirip
        usort($conflicts, function ($a, $b) {
            return $b['similarity_percent'] <=> $a['similarity_percent'];
        });

        $hasDuplicate = $maxSimilarity >= 70;
        $hasWarning   = $maxSimilarity >= 40 && $maxSimilarity < 70;

        $suggestion = null;
        if ($hasDuplicate) {
            $topConflict = $conflicts[0];
            $suggestion = "Kasus ini memiliki kemiripan {$topConflict['similarity_percent']}% dengan template yang sudah ada: \"{$topConflict['case_title']}\" (ID #{$topConflict['template_id']}). Sebaiknya perbarui template tersebut agar AI chatbot tidak menghasilkan jawaban ganda/rancu.";
        } elseif ($hasWarning) {
            $topConflict = $conflicts[0];
            $suggestion = "Terdapat beberapa kata kunci yang mirip dengan template: \"{$topConflict['case_title']}\". Pastikan kata kunci pemicu spesifik dan tidak tumpang tindih.";
        }

        return [
            'is_duplicate'       => $hasDuplicate,
            'has_warning'        => $hasWarning,
            'similarity_score'   => $maxSimilarity,
            'conflicts_count'    => count($conflicts),
            'conflicts'          => array_slice($conflicts, 0, 5), // Ambil 5 teratas
            'suggestion'         => $suggestion,
        ];
    }

    /**
     * Normalisasi teks: lowercase, hapus tanda baca, dan trim spasi berlebih
     */
    protected static function normalizeText(string $text): string
    {
        $text = mb_strtolower($text, 'UTF-8');
        // Hapus karakter selain huruf dan angka
        $text = preg_replace('/[^\p{L}\p{N}\s]/u', ' ', $text);
        // Hapus spasi berlebih
        $text = preg_replace('/\s+/', ' ', $text);
        return trim($text);
    }
}
