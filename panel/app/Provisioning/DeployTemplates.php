<?php

namespace App\Provisioning;

/**
 * Default deploy script for the "in-place" deploy mode. Editable per
 * application; this is only the starting point shown in the UI.
 */
class DeployTemplates
{
    public const DEFAULT_SCRIPT = <<<'SH'
        # $PHP_BIN is exported by the panel as the app's chosen PHP (e.g. php8.2),
        # so composer + artisan run under that version, not the server default.
        PHP_BIN="${PHP_BIN:-php}"

        if [ ! -d .git ]; then
            git init -q
            git remote add origin "$REPO_URL"
        else
            git remote set-url origin "$REPO_URL"
        fi
        git fetch --depth 1 origin "$BRANCH"
        git reset --hard "origin/$BRANCH"

        if [ -f composer.json ]; then
            "$PHP_BIN" "$(command -v composer)" install --no-dev --optimize-autoloader --no-interaction
        fi

        if [ -f package.json ]; then
            npm ci
            npm run build --if-present
        fi

        if [ -f artisan ]; then
            "$PHP_BIN" artisan migrate --force
            "$PHP_BIN" artisan config:cache
            "$PHP_BIN" artisan route:cache
            "$PHP_BIN" artisan view:cache
        fi
        SH;
}
