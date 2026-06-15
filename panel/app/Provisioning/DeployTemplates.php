<?php

namespace App\Provisioning;

/**
 * Default deploy script for the "in-place" deploy mode. Editable per
 * application; this is only the starting point shown in the UI.
 */
class DeployTemplates
{
    public const DEFAULT_SCRIPT = <<<'SH'
        if [ ! -d .git ]; then
            git init -q
            git remote add origin "$REPO_URL"
        else
            git remote set-url origin "$REPO_URL"
        fi
        git fetch --depth 1 origin "$BRANCH"
        git reset --hard "origin/$BRANCH"

        if [ -f composer.json ]; then
            composer install --no-dev --optimize-autoloader --no-interaction
        fi

        if [ -f package.json ]; then
            npm ci
            npm run build --if-present
        fi

        if [ -f artisan ]; then
            php artisan migrate --force
            php artisan config:cache
            php artisan route:cache
            php artisan view:cache
        fi
        SH;
}
