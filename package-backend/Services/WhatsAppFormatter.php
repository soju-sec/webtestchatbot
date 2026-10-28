<?php

namespace App\Services;

class WhatsAppFormatter
{
    /**
     * Mengonversi teks yang berisi tag HTML menjadi format WhatsApp Markdown.
     *
     * WhatsApp Formatting Rules:
     * - Tebal (Bold)      : *teks*
     * - Miring (Italic)   : _teks_
     * - Coret (Strikethrough) : ~teks~
     * - Monospace (Code)  : `teks` atau ```blok kode```
     * - Bullet List       : • item
     *
     * @param string|null $text
     * @return string
     */
    public static function format(?string $text): string
    {
        if (empty($text)) {
            return '';
        }

        $formatted = $text;

        // 1. Ganti baris baru dan paragraf
        $formatted = preg_replace('/<br\s*\/?>/i', "\n", $formatted);
        $formatted = preg_replace('/<\/p>\s*<p>/i', "\n\n", $formatted);
        $formatted = preg_replace('/<\/?p>/i', "\n", $formatted);

        // 2. Format Bold (<b> atau <strong>)
        $formatted = preg_replace('/<(?:b|strong)>(.*?)<\/(?:b|strong)>/is', '*$1*', $formatted);

        // 3. Format Italic (<i> atau <em>)
        $formatted = preg_replace('/<(?:i|em)>(.*?)<\/(?:i|em)>/is', '_$1_', $formatted);

        // 4. Format Strikethrough (<del> atau <s> atau <strike>)
        $formatted = preg_replace('/<(?:del|s|strike)>(.*?)<\/(?:del|s|strike)>/is', '~$1~', $formatted);

        // 5. Format Monospace Block (<pre><code>)
        $formatted = preg_replace('/<pre><code(?:\s+class="[^"]*")?>(.*?)<\/code><\/pre>/is', "```\n$1\n```", $formatted);

        // 6. Format Inline Code (<code>)
        $formatted = preg_replace('/<code>(.*?)<\/code>/is', '`$1`', $formatted);

        // 7. Format List Items (<li>)
        $formatted = preg_replace('/<li[^>]*>(.*?)<\/li>/is', "• $1\n", $formatted);
        $formatted = preg_replace('/<\/?(?:ul|ol)>/i', "\n", $formatted);

        // 8. Format Headers (<h1> - <h6>)
        $formatted = preg_replace('/<h[1-3][^>]*>(.*?)<\/h[1-3]>/is', "\n*$1*\n", $formatted);
        $formatted = preg_replace('/<h[4-6][^>]*>(.*?)<\/h[4-6]>/is', "\n_$1_\n", $formatted);

        // 9. Bersihkan sisa tag HTML lainnya (misal: <div>, <span>, <small>, <font>, dll.)
        $formatted = strip_tags($formatted);

        // 10. Decode entity HTML (&nbsp;, &amp;, &lt;, &gt;, &quot;)
        $formatted = html_entity_decode($formatted, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $formatted = str_replace("\xc2\xa0", ' ', $formatted); // Non-breaking space

        // 11. Normalisasi spasi dan baris kosong berlebihan (maksimal 2 baris berturut-turut)
        $formatted = preg_replace("/\n{3,}/", "\n\n", $formatted);

        return trim($formatted);
    }

    /**
     * Format ringkasan eksekutif (Executive Briefing Header/Footer).
     *
     * @param string $executiveName Nama eksekutif / Direksi
     * @param string $content Isi konten briefing
     * @return string
     */
    public static function wrapExecutiveResponse(string $executiveName, string $content): string
    {
        $cleanContent = self::format($content);
        $timeStr = date('H:i') . ' WIB';

        $header = "👔 *EXECUTIVE BRIEFING*\n";
        $header .= "Yth. *{$executiveName}* ({$timeStr})\n";
        $header .= "━━━━━━━━━━━━━━━━━━━━━━\n\n";

        $footer = "\n\n━━━━━━━━━━━━━━━━━━━━━━\n";
        $footer .= "_Asisten AI Eksekutif • Terhubung ke Core BI & Jira_";

        return $header . $cleanContent . $footer;
    }
}
