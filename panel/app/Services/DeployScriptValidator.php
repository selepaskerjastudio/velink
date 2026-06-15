<?php

namespace App\Services;

class DeployScriptValidator
{
    /**
     * Returns a list of human-readable warnings for dangerous patterns found
     * in the script. Empty array = no issues found.
     *
     * @return string[]
     */
    public static function check(string $script): array
    {
        $warnings = [];

        $patterns = [
            '/rm\s+-[rf]{1,2}\s+\/\s*$|rm\s+-[rf]{1,2}\s+\/\*/im' => "Destructive command detected: 'rm -rf /' or 'rm -rf /*' would delete the entire filesystem.",
            '/dd\s+.*of=\/dev\/[sh]d/i' => "Potentially destructive: writing directly to a block device with 'dd'.",
            '/mkfs/i' => "Potentially destructive: 'mkfs' formats a filesystem.",
            '/>\s*\/dev\/sda/' => 'Writing directly to a block device.',
            '/:\(\)\s*\{.*:\|.*:&.*\}/' => 'Fork bomb pattern detected.',
        ];

        foreach ($patterns as $pattern => $message) {
            if (preg_match($pattern, $script)) {
                $warnings[] = $message;
            }
        }

        return $warnings;
    }
}
