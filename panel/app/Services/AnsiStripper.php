<?php

namespace App\Services;

/**
 * Convert ANSI escape sequences in captured command output (composer, npm,
 * artisan, …) into either plain text or HTML spans for a terminal-style view.
 *
 * Handles SGR colour codes incl. 256-colour and true-colour sequences, plus
 * common CSI control sequences (cursor moves, erase, private `?` sequences)
 * that tools emit.
 */
class AnsiStripper
{
    /** Map of SGR codes to CSS classes for the 16-colour palette. */
    private const COLOR_MAP = [
        '0;30' => 'ansi-fg-black', '0;31' => 'ansi-fg-red', '0;32' => 'ansi-fg-green',
        '0;33' => 'ansi-fg-yellow', '0;34' => 'ansi-fg-blue', '0;35' => 'ansi-fg-magenta',
        '0;36' => 'ansi-fg-cyan', '0;37' => 'ansi-fg-white',
        '1;30' => 'ansi-fg-bold ansi-fg-black', '1;31' => 'ansi-fg-bold ansi-fg-red',
        '1;32' => 'ansi-fg-bold ansi-fg-green', '1;33' => 'ansi-fg-bold ansi-fg-yellow',
        '1;34' => 'ansi-fg-bold ansi-fg-blue', '1;35' => 'ansi-fg-bold ansi-fg-magenta',
        '1;36' => 'ansi-fg-bold ansi-fg-cyan', '1;37' => 'ansi-fg-bold ansi-fg-white',
        '30' => 'ansi-fg-black', '31' => 'ansi-fg-red', '32' => 'ansi-fg-green',
        '33' => 'ansi-fg-yellow', '34' => 'ansi-fg-blue', '35' => 'ansi-fg-magenta',
        '36' => 'ansi-fg-cyan', '37' => 'ansi-fg-white',
        '1' => 'ansi-fg-bold', '2' => 'ansi-fg-dim',
    ];

    /** Matches any CSI sequence (SGR `m`, cursor moves, private `?` modes, …). */
    private const CSI_REGEX = '/\e\[[0-9;?=]*[A-Za-z]/';

    /** Matches SGR sequences only (the `m` terminator). */
    private const SGR_REGEX = '/\e\[([0-9;]*)m/';

    /**
     * Render captured output. When $colorize is false all escape sequences are
     * stripped to plain text; when true, known SGR codes become HTML spans and
     * the remaining escapes are removed.
     */
    public static function toHtml(string $input, bool $colorize = false): string
    {
        if ($input === '') {
            return '';
        }

        if (! $colorize) {
            // Plain mode: strip every CSI sequence.
            return preg_replace(self::CSI_REGEX, '', $input) ?? $input;
        }

        // Colourize mode: convert SGR markers to HTML spans first…
        $html = preg_replace_callback(
            self::SGR_REGEX,
            function ($matches) {
                $code = $matches[1];
                if ($code === '0' || $code === '') {
                    return '</span>';
                }
                // 256-colour / true-colour (38;…, 48;…) fall back to neutral.
                if (str_starts_with($code, '38;') || str_starts_with($code, '48;')) {
                    return '<span class="ansi-fg-white">';
                }

                return '<span class="'.(self::COLOR_MAP[$code] ?? 'ansi-fg-white').'">';
            },
            $input,
        );

        // …then strip any remaining non-SGR escapes (cursor moves, etc.).
        return preg_replace(self::CSI_REGEX, '', $html) ?? $html;
    }
}
