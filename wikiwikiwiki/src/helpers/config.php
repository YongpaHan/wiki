<?php

declare(strict_types=1);

function allowed_edit_permissions(): array
{
    return ['private', 'public', 'fully_public'];
}

function required_php_extensions(): array
{
    return ['json', 'mbstring', 'session', 'hash', 'pcre'];
}

function config_editable_fields(): array
{
    return [
        'WIKI_TITLE',
        'WIKI_DESCRIPTION',
        'BASE_URL',
        'THEME',
        'LANGUAGE',
        'TIMEZONE',
        'HOME_PAGE',
    ];
}

function config_field_translation_key(string $field): string
{
    return 'settings.field.' . strtolower($field);
}

function allowed_languages(): array
{
    $catalog = language_catalog();
    $languages = array_keys($catalog);
    if ($languages === []) {
        return ['ko'];
    }
    return $languages;
}

function language_catalog(): array
{
    static $cache = null;
    if ($cache !== null) {
        return $cache;
    }

    $catalog = [];
    $directories = i18n_directories();
    $codes = function_exists('i18n_language_codes')
        ? i18n_language_codes($directories)
        : [];
    if ($codes === []) {
        foreach ($directories as $directory) {
            foreach (glob($directory . '/i18n/*.json') ?: [] as $file) {
                $name = pathinfo($file, PATHINFO_FILENAME);
                if (preg_match('/^[a-z]{2,12}$/', $name) === 1) {
                    $codes[] = $name;
                }
            }
        }
        $codes = array_values(array_unique($codes));
        sort($codes, SORT_STRING);
    }

    foreach ($codes as $code) {
        $catalog[$code] = ['name' => $code];
        foreach ($directories as $directory) {
            $file = $directory . '/i18n/' . $code . '.json';
            if (!is_file($file)) {
                continue;
            }
            $raw = @file_get_contents($file);
            if (!is_string($raw)) {
                continue;
            }

            $data = json_decode($raw, true);
            if (!is_array($data)) {
                continue;
            }

            $displayName = trim((string) ($data['language.name'] ?? ''));
            if ($displayName !== '') {
                $catalog[$code]['name'] = $displayName;
            }
        }
    }

    if ($catalog === []) {
        $catalog = ['ko' => ['name' => 'ko']];
    }
    ksort($catalog, SORT_STRING);

    $cache = $catalog;
    return $cache;
}

function available_themes(): array
{
    static $cache = null;
    if ($cache !== null) {
        return $cache;
    }
    if (!is_dir(THEMES_DIR)) {
        $cache = [];
        return $cache;
    }
    $themes = [];
    foreach (scandir(THEMES_DIR) ?: [] as $entry) {
        if ($entry === '.' || $entry === '..') {
            continue;
        }
        $path = THEMES_DIR . '/' . $entry;
        if (!is_dir($path)) {
            continue;
        }
        if (!theme_name_is_valid($entry)) {
            continue;
        }
        $themes[] = $entry;
    }
    sort($themes);
    $cache = $themes;
    return $cache;
}

function theme_health(string $themeName): array
{
    $name = trim($themeName);

    if ($name === '' || !theme_name_is_valid($name)) {
        return [
            'name' => $name,
            'exists' => false,
        ];
    }

    return [
        'name' => $name,
        'exists' => is_dir(THEMES_DIR . '/' . $name),
    ];
}

function language_display_name(string $language): string
{
    $code = trim($language);
    if ($code === '' || !preg_match('/^[a-z]{2,12}$/', $code)) {
        return $language;
    }

    $catalog = language_catalog();
    $name = trim((string) ($catalog[$code]['name'] ?? ''));
    return $name !== '' ? $name : $code;
}

function validate_config_values(array $values): array
{
    $result = [];

    $title = trim((string) ($values['WIKI_TITLE'] ?? ''));
    if ($title === '' || mb_strlen($title) > 120) {
        return ['ok' => false, 'values' => [], 'error' => 'flash.config.invalid'];
    }
    $result['WIKI_TITLE'] = $title;

    $description = trim((string) ($values['WIKI_DESCRIPTION'] ?? ''));
    if ($description === '' || mb_strlen($description) > 300) {
        return ['ok' => false, 'values' => [], 'error' => 'flash.config.invalid'];
    }
    $result['WIKI_DESCRIPTION'] = $description;

    $language = trim((string) ($values['LANGUAGE'] ?? ''));
    if (!in_array($language, allowed_languages(), true)) {
        return ['ok' => false, 'values' => [], 'error' => 'flash.config.invalid'];
    }
    $result['LANGUAGE'] = $language;

    $timezone = trim((string) ($values['TIMEZONE'] ?? ''));
    if ($timezone !== '') {
        static $timezones = null;
        if ($timezones === null) {
            $timezones = array_fill_keys(timezone_identifiers_list(), true);
        }
        if (!isset($timezones[$timezone])) {
            return ['ok' => false, 'values' => [], 'error' => 'flash.config.invalid'];
        }
    }
    $result['TIMEZONE'] = $timezone;

    $homePage = sanitize_page_title(trim((string) ($values['HOME_PAGE'] ?? '')));
    if ($homePage === '') {
        return ['ok' => false, 'values' => [], 'error' => 'flash.config.invalid'];
    }
    if (page_title_uses_reserved_route_suffix($homePage)) {
        return ['ok' => false, 'values' => [], 'error' => 'flash.page.title_reserved'];
    }
    if (!page_title_fits_filename_limit($homePage)) {
        return ['ok' => false, 'values' => [], 'error' => 'flash.page.title_too_long_filename'];
    }
    $result['HOME_PAGE'] = $homePage;

    $baseUrl = trim((string) ($values['BASE_URL'] ?? ''));
    if ($baseUrl !== '') {
        $urlValid = filter_var($baseUrl, FILTER_VALIDATE_URL) !== false;
        $schemeValid = (bool) preg_match('/^https?:\/\//i', $baseUrl);
        if (!$urlValid || !$schemeValid) {
            return ['ok' => false, 'values' => [], 'error' => 'flash.config.invalid'];
        }
        $baseUrl = rtrim($baseUrl, '/');
    }
    $result['BASE_URL'] = $baseUrl;

    $theme = trim((string) ($values['THEME'] ?? ''));
    if ($theme !== '' && !in_array($theme, available_themes(), true)) {
        return ['ok' => false, 'values' => [], 'error' => 'flash.config.invalid'];
    }
    $result['THEME'] = $theme;

    $editPermission = trim((string) ($values['EDIT_PERMISSION'] ?? EDIT_PERMISSION));
    if (!in_array($editPermission, allowed_edit_permissions(), true)) {
        return ['ok' => false, 'values' => [], 'error' => 'flash.config.invalid'];
    }
    $result['EDIT_PERMISSION'] = $editPermission;

    return ['ok' => true, 'values' => $result, 'error' => ''];
}

function save_config(array $values): bool
{
    $configPath = BASE_DIR . '/config/config.php';
    $configDir = dirname($configPath);
    if (!is_dir($configDir) && !@mkdir($configDir, 0755, true)) {
        wiki_log('config.mkdir_failed', ['dir' => $configDir], 'error');
        return false;
    }

    $fields = config_editable_fields();
    $all = [];
    foreach ($fields as $field) {
        $all[$field] = $values[$field] ?? (defined($field) ? constant($field) : '');
    }
    $defaultPermission = EDIT_PERMISSION;
    $editPermission = trim((string) ($values['EDIT_PERMISSION'] ?? $defaultPermission));
    if (!in_array($editPermission, allowed_edit_permissions(), true)) {
        $editPermission = $defaultPermission;
    }
    $all['EDIT_PERMISSION'] = $editPermission;

    $lines = ["<?php\n", "declare(strict_types=1);\n"];

    foreach ($all as $key => $val) {
        $exported = var_export($val, true);
        $lines[] = "define('{$key}', {$exported});";
    }

    $content = implode("\n", $lines) . "\n";
    $result = file_put_atomic($configPath, $content);

    if ($result) {
        if (function_exists('opcache_invalidate')) {
            opcache_invalidate($configPath, true);
        }
        clearstatcache(true, $configPath);
    }

    return $result !== false;
}

function clear_config_theme_if_missing(): void
{
    static $checked = false;
    if ($checked) {
        return;
    }
    $checked = true;

    $configuredTheme = trim((string) (defined('THEME') ? THEME : ''));
    if ($configuredTheme === '' || THEME_ENABLED) {
        return;
    }

    $configPath = BASE_DIR . '/config/config.php';
    $configDir = dirname($configPath);
    $configWritable = is_file($configPath)
        ? is_writable($configPath)
        : (is_dir($configDir) && is_writable($configDir));
    if (!$configWritable) {
        return;
    }

    if (!save_config(['THEME' => ''])) {
        wiki_log('config.theme_autoclear_failed', ['theme' => $configuredTheme], 'warning');
        return;
    }

    wiki_log('config.theme_autocleared', ['theme' => $configuredTheme], 'info');
}
