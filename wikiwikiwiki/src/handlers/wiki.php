<?php

declare(strict_types=1);

function edit_view_data(
    string $title,
    ?string $content,
    bool $isNew,
    ?int $modifiedAt,
    bool $conflict = false,
    ?string $currentContent = null,
): array {
    $data = [
        'page' => $title,
        'content' => $content,
        'isNew' => $isNew,
        'modifiedAt' => $modifiedAt,
    ];

    if ($conflict) {
        $data['conflict'] = true;
        $data['currentContent'] = $currentContent;
    }

    return $data;
}

function handle_home(string $method, array $matches): void
{
    require_get_method($method);
    render_view(HOME_PAGE);
}

function handle_new_page(string $method, array $matches): void
{
    require_edit_access();

    if ($method === 'POST') {
        validate_post_request();

        $rawTitle = request_trimmed($_POST, 'title');
        $title = sanitize_page_title($rawTitle);
        $content = request_string($_POST, 'content');
        $overwriteExisting = request_string($_POST, 'overwrite_existing', '0') === '1';

        if (mb_strlen($rawTitle) > PAGE_TITLE_MAX_LENGTH) {
            flash('error', t('flash.page.title_too_long_message'));
            render('new', ['inputTitle' => $rawTitle, 'inputContent' => $content]);
            exit;
        }

        if ($title === '') {
            flash('error', t('flash.page.title_required'));
            render('new', ['inputContent' => $content]);
            exit;
        }

        if (page_title_uses_reserved_route_suffix($title)) {
            flash('error', t('flash.page.title_reserved'));
            render('new', ['inputTitle' => $rawTitle, 'inputContent' => $content]);
            exit;
        }

        if (!page_title_fits_filename_limit($title)) {
            flash('error', t('flash.page.title_too_long_filename'));
            render('new', ['inputTitle' => $rawTitle, 'inputContent' => $content]);
            exit;
        }

        if (trim($content) === '') {
            flash('error', t('flash.page.content_required'));
            render('new', ['inputTitle' => $title]);
            exit;
        }

        $pageExists = page_exists($title);

        if ($pageExists && !$overwriteExisting) {
            render('new', [
                'inputTitle' => $title,
                'inputContent' => $content,
                'existingTitle' => $title,
                'existingContent' => page_get($title) ?? '',
                'overwriteExisting' => true,
            ]);
            exit;
        }

        if (!page_save($title, $content)) {
            flash('error', t('flash.page.save_failed'));
            redirect(url('/new'));
        }
        flash('success', t($pageExists ? 'flash.page.saved' : 'flash.page.created'));
        redirect(url($title));
    }

    render('new');
}

function handle_random(string $method, array $matches): void
{
    require_get_method($method);
    $pages = page_all();
    if ($pages === []) {
        redirect(url('/'));
    }
    redirect(url($pages[array_rand($pages)]));
}

function handle_wiki_edit(string $method, array $matches): void
{
    require_get_method($method);
    $title = route_title_or_400($matches);

    require_edit_access();

    $content = page_get($title);
    $isNew = ($content === null);
    $modifiedAt = page_last_modified_at($title);
    render('edit', edit_view_data(
        title: $title,
        content: $content,
        isNew: $isNew,
        modifiedAt: $modifiedAt,
    ));
}

function handle_wiki_page(string $method, array $matches): void
{
    if ($method === 'GET') {
        handle_wiki_view($method, $matches);
        return;
    }

    if ($method === 'POST') {
        handle_wiki_update($method, $matches);
        return;
    }

    header('Allow: GET, POST');
    render_error_page(405, '405', t('error.request.method_not_allowed'));
}

function handle_wiki_view(string $method, array $matches): void
{
    $title = route_title_or_400($matches);
    render_view($title);
}

function handle_wiki_update(string $method, array $matches): void
{
    if ($method !== 'POST') {
        header('Allow: POST');
        render_error_page(405, '405', t('error.request.method_not_allowed'));
    }

    $title = route_title_or_400($matches);
    require_edit_access();
    validate_post_request();

    $content = request_string($_POST, 'content');
    $normalizedContent = page_normalize_content_for_save($content);
    $originalModifiedAt = request_string($_POST, 'original_modified_at');

    if ($originalModifiedAt !== '' && page_exists($title)) {
        $currentModifiedAt = page_last_modified_at($title);
        if ($currentModifiedAt !== null && (string) $currentModifiedAt !== $originalModifiedAt) {
            $currentContent = page_get($title) ?? '';
            if ($normalizedContent !== $currentContent) {
                render('edit', edit_view_data(
                    title: $title,
                    content: $content,
                    isNew: false,
                    modifiedAt: $currentModifiedAt,
                    conflict: true,
                    currentContent: $currentContent,
                ));
                exit;
            }
        }
    }

    if ($normalizedContent === '') {
        $deleteHistoryRequested = request_string($_POST, 'delete_history', '0') === '1';
        $deleteHistory = $deleteHistoryRequested && is_admin();
        if (page_delete($title, $deleteHistory)) {
            flash('success', t('flash.page.deleted'));
        } else {
            flash('error', t('flash.page.save_failed'));
        }
        redirect(url('/'));
    }

    $rawNewTitle = request_trimmed($_POST, 'new_title');
    if ($rawNewTitle !== '' && $rawNewTitle !== $title && $title !== HOME_PAGE) {
        $renderEditError = static function (string $messageKey) use (&$title, $content): never {
            flash('error', t($messageKey));
            render('edit', edit_view_data(
                title: $title,
                content: $content,
                isNew: false,
                modifiedAt: page_last_modified_at($title),
            ));
            exit;
        };

        if (mb_strlen($rawNewTitle) > PAGE_TITLE_MAX_LENGTH) {
            $renderEditError('flash.page.title_too_long_message');
        }
        $newTitle = sanitize_page_title($rawNewTitle);
        if ($newTitle === '') {
            $renderEditError('flash.page.title_required');
        }
        if (page_title_uses_reserved_route_suffix($newTitle)) {
            $renderEditError('flash.page.title_reserved');
        }
        if (!page_title_fits_filename_limit($newTitle)) {
            $renderEditError('flash.page.title_too_long_filename');
        }
        if (page_exists($newTitle)) {
            if (page_redirect_target($newTitle) === null) {
                $renderEditError('flash.page.exists');
            }
        }
        if (!page_rename($title, $newTitle)) {
            $renderEditError('flash.page.save_failed');
        }
        $title = $newTitle;
    }

    $currentContent = page_get($title) ?? '';
    if ($normalizedContent === $currentContent) {
        redirect(url($title));
    }

    if (!page_save($title, $normalizedContent, contentIsNormalized: true)) {
        flash('error', t('flash.page.save_failed'));
        redirect(url($title, '/edit'));
    }
    flash('success', t('flash.page.saved'));
    redirect(url($title));
}


function handle_wiki_backlinks(string $method, array $matches): void
{
    require_get_method($method);
    $title = route_title_or_400($matches);

    $backlinks = page_backlinks($title);
    render('backlinks', [
        'page' => $title,
        'backlinks' => $backlinks,
        'parent' => page_parent($title),
        'children' => page_children($title),
        'siblings' => page_siblings($title),
    ]);
}

function handle_wiki_raw(string $method, array $matches): void
{
    require_get_method($method);
    $title = route_title_or_400($matches);
    $ext = strtolower(request_string($matches, 2, 'txt'));

    $content = page_get($title);
    if ($content === null) {
        render_404_page($title . '.' . $ext);
    }
    $filename = pathinfo(title_to_filename($title), PATHINFO_FILENAME) . '.' . ($ext === 'md' ? 'md' : 'txt');
    $safeFilename = str_replace(["\r", "\n", '"'], '', $filename);
    header('Content-Type: text/markdown; charset=utf-8');
    header('Content-Disposition: inline; filename="' . $safeFilename . '"');
    echo $content;
}
