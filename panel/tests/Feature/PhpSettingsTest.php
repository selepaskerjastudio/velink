<?php

use App\Models\Application;
use App\Provisioning\AppTemplates;
use App\Provisioning\PhpSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('defaults match the values that were previously hardcoded in the pool template', function () {
    $defaults = PhpSettings::defaults();

    // These are the exact literals that used to live in AppTemplates::PHP_FPM_POOL.
    expect($defaults)->toMatchArray([
        'pm' => 'dynamic',
        'pm_max_children' => '5',
        'pm_start_servers' => '2',
        'pm_min_spare_servers' => '1',
        'pm_max_spare_servers' => '3',
    ]);
});

test('every settings default key is templated in the PHP_FPM_POOL config', function () {
    $template = AppTemplates::PHP_FPM_POOL;

    foreach (array_keys(PhpSettings::defaults()) as $key) {
        expect($template)->toContain("{{.{$key}}}");
    }
});

test('forApp returns the full defaults when no overrides are stored', function () {
    $app = Application::factory()->create(['php_settings' => null]);

    expect(PhpSettings::forApp($app))->toBe(PhpSettings::defaults());
});

test('forApp merges stored overrides over the defaults', function () {
    $app = Application::factory()->create([
        'php_settings' => ['pm_max_children' => '42', 'memory_limit' => '512M'],
    ]);

    $settings = PhpSettings::forApp($app);

    // Overridden values win.
    expect($settings['pm_max_children'])->toBe('42');
    expect($settings['memory_limit'])->toBe('512M');
    // Untouched keys fall back to defaults.
    expect($settings['pm'])->toBe('dynamic');
    expect($settings['pm_start_servers'])->toBe('2');
});

test('forApp ignores null override values so they fall back to defaults', function () {
    $app = Application::factory()->create([
        'php_settings' => ['pm_max_children' => null],
    ]);

    $settings = PhpSettings::forApp($app);
    expect($settings['pm_max_children'])->toBe('5');
});

test('the Application accessor exposes the merged settings', function () {
    $app = Application::factory()->create([
        'php_settings' => ['pm' => 'static', 'pm_max_children' => '8'],
    ]);

    expect($app->php_settings['pm'])->toBe('static');
    expect($app->php_settings['pm_max_children'])->toBe('8');
    // Defaults still fill the gaps.
    expect($app->php_settings['memory_limit'])->toBe('128M');
});

test('vars emits every settings key as a string', function () {
    $app = Application::factory()->create([
        'app_slug' => 'demo',
        'php_settings' => ['pm_max_children' => 9, 'memory_limit' => '256M'],
    ]);

    $vars = AppTemplates::vars($app);

    expect($vars['pm_max_children'])->toBe('9');
    expect($vars['memory_limit'])->toBe('256M');
    expect($vars['pm'])->toBe('dynamic');
    expect($vars['pm_process_idle_timeout'])->toBe('10s');
});

test('validation rules reject an invalid pm mode', function () {
    $validator = validator(
        ['pm' => 'bogus', 'pm_max_children' => 5, 'pm_start_servers' => 2, 'pm_min_spare_servers' => 1, 'pm_max_spare_servers' => 3, 'pm_max_requests' => 0, 'pm_process_idle_timeout' => '10s', 'memory_limit' => '128M', 'max_execution_time' => 30, 'max_input_time' => 60, 'upload_max_filesize' => '2M', 'post_max_size' => '8M'],
        PhpSettings::validationRules()
    );

    expect($validator->fails())->toBeTrue();
    expect($validator->errors()->has('pm'))->toBeTrue();
});

test('validation rules reject negative child counts', function () {
    $invalid = array_merge(PhpSettings::defaults(), ['pm_max_children' => '0']);

    $validator = validator($invalid, PhpSettings::validationRules());

    expect($validator->fails())->toBeTrue();
    expect($validator->errors()->has('pm_max_children'))->toBeTrue();
});

test('validation rules reject a malformed memory limit', function () {
    $invalid = array_merge(PhpSettings::defaults(), ['memory_limit' => 'lots']);

    $validator = validator($invalid, PhpSettings::validationRules());

    expect($validator->fails())->toBeTrue();
    expect($validator->errors()->has('memory_limit'))->toBeTrue();
});

test('validation rules accept the -1 unlimited memory limit', function () {
    $valid = array_merge(PhpSettings::defaults(), ['memory_limit' => '-1']);

    $validator = validator($valid, PhpSettings::validationRules());

    expect($validator->fails())->toBeFalse();
});

test('presets ship low medium and high profiles', function () {
    $presets = PhpSettings::presets();

    expect($presets)->toHaveKeys(['low', 'medium', 'high']);
    expect($presets['low']['pm_max_children'])->toBeLessThan($presets['medium']['pm_max_children']);
    expect($presets['medium']['pm_max_children'])->toBeLessThan($presets['high']['pm_max_children']);
    // Presets only tune pm.* — ini limits keep defaults.
    expect($presets['high']['memory_limit'])->toBe('128M');
});
