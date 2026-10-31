<?php
/**
 * NEW FILE: includes/announcement_helpers.php
 *
 * Public announcements. The platform admin writes them (admin portal), every person who uses MY MOTIVE sees them
 * (business dashboard + the Announcements page). Both sides use this file. The data lives in the PLATFORM database
 * (announcements, announcement_reads), so one announcement reaches every business.
 *
 * Rules kept here:
 *   - Only a PUBLISHED announcement, inside its start / end time, is shown. Drafts and archived ones are never shown.
 *   - "Business Admins only" announcements are shown to the Admin of each business and to nobody else.
 *   - "Read" is remembered per PERSON per BUSINESS (a user id is only unique inside one business, so the business is part of the key).
 *   - Times are compared with the PHP clock, the same clock the admin used when typing the dates, so the database clock never matters.
 *   - Everything typed is stored as plain text and shown escaped. A button link must start with http:// or https://.
 *   - If the tables are not installed yet (the migration has not run), the menu badge and the dashboard notice stay quiet
 *     instead of breaking the page.
 */
require_once __DIR__ . '/platform.php';

const ANNOUNCEMENT_LEVELS = ['info' => 'Information', 'success' => 'Good news', 'warning' => 'Warning', 'important' => 'Important'];
const ANNOUNCEMENT_AUDIENCES = ['everyone' => 'Everyone who uses MY MOTIVE', 'admins' => 'Business Admins only'];
const ANNOUNCEMENT_HISTORY_DAYS = 90;

function announcement_h($v): string
{
    return htmlspecialchars((string) ($v ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/** The Bootstrap colour of a level. */
function announcement_alert_class(string $level): string
{
    return ['info' => 'info', 'success' => 'success', 'warning' => 'warning', 'important' => 'danger'][$level] ?? 'info';
}

/** "Now", in the clock that the admin's dates are typed in. */
function announcement_now(): string
{
    return date('Y-m-d H:i:s');
}

/** Plain text, no control characters, cut to a length. */
function announcement_clean($value, int $max): string
{
    $s = is_string($value) ? $value : '';
    if (!mb_check_encoding($s, 'UTF-8')) {
        $s = mb_convert_encoding($s, 'UTF-8', 'UTF-8');
    }
    $s = str_replace(["\r\n", "\r"], "\n", $s);
    $s = (string) preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $s);
    return mb_substr(trim($s), 0, $max);
}

// ───────────────────────────── protection of the forms on the business side ─────────────────────────────

function announcement_csrf_token(): string
{
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    if (empty($_SESSION['announcement_csrf'])) {
        $_SESSION['announcement_csrf'] = bin2hex(random_bytes(24));
    }
    return (string) $_SESSION['announcement_csrf'];
}

function announcement_csrf_ok(): bool
{
    $sent = $_POST['csrf'] ?? '';
    return is_string($sent) && $sent !== '' && !empty($_SESSION['announcement_csrf']) && hash_equals((string) $_SESSION['announcement_csrf'], $sent);
}

// ───────────────────────────── what the admin types ─────────────────────────────

/** A date typed in the admin's form ("2026-11-02T09:30"), as 'Y-m-d H:i:00'. '' = no date (null). false = not a valid date. */
function announcement_parse_time($value)
{
    $v = is_string($value) ? trim($value) : '';
    if ($v === '') {
        return null;
    }
    foreach (['Y-m-d\TH:i', 'Y-m-d H:i', 'Y-m-d\TH:i:s', 'Y-m-d H:i:s'] as $format) {
        $d = DateTime::createFromFormat('!' . $format, $v);
        $errors = DateTime::getLastErrors();
        if ($d && (!$errors || ($errors['warning_count'] === 0 && $errors['error_count'] === 0))) {
            return $d->format('Y-m-d H:i:00');
        }
    }
    return false;
}

/** The button link: '' = none, false = not allowed. Only http:// and https:// (never javascript: and the like). */
function announcement_clean_link($value)
{
    $u = is_string($value) ? trim($value) : '';
    if ($u === '') {
        return '';
    }
    if (strlen($u) > 300 || preg_match('/[\s\x00-\x1F<>"\']/', $u) || !preg_match('#^https?://#i', $u)) {
        return false;
    }
    $parts = parse_url($u);
    return ($parts !== false && !empty($parts['host']) && filter_var($u, FILTER_VALIDATE_URL) !== false) ? $u : false;
}

/**
 * Checks what the admin typed. $mustBeCurrent: the announcement will be published, so its end time must not be in the past.
 *
 * @return array{ok:bool, error?:string, data?:array}
 */
function announcement_validate(array $in, bool $mustBeCurrent): array
{
    $title = announcement_clean($in['title'] ?? '', 160);
    $body = announcement_clean($in['body'] ?? '', 5000);
    $level = (string) ($in['level'] ?? '');
    $audience = (string) ($in['audience'] ?? '');
    $link = announcement_clean_link($in['link_url'] ?? '');
    $label = announcement_clean($in['link_label'] ?? '', 40);
    $starts = announcement_parse_time($in['starts_at'] ?? '');
    $ends = announcement_parse_time($in['ends_at'] ?? '');

    if (mb_strlen($title) < 3) {
        return ['ok' => false, 'error' => 'Write a title of at least 3 characters.'];
    }
    if (mb_strlen($body) < 5) {
        return ['ok' => false, 'error' => 'Write the message (at least 5 characters).'];
    }
    if (!isset(ANNOUNCEMENT_LEVELS[$level])) {
        return ['ok' => false, 'error' => 'Choose the type of the announcement.'];
    }
    if (!isset(ANNOUNCEMENT_AUDIENCES[$audience])) {
        return ['ok' => false, 'error' => 'Choose who will see the announcement.'];
    }
    if ($link === false) {
        return ['ok' => false, 'error' => 'The button link must be a full web address starting with http:// or https://, with no spaces.'];
    }
    if ($starts === false || $ends === false) {
        return ['ok' => false, 'error' => 'A date or time is not valid. Use the date picker.'];
    }
    if ($starts !== null && $ends !== null && $ends <= $starts) {
        return ['ok' => false, 'error' => 'The end time must be after the start time.'];
    }
    if ($mustBeCurrent && $ends !== null && $ends <= announcement_now()) {
        return ['ok' => false, 'error' => 'The end time has already passed, so nobody would see this announcement. Choose a later end time, or clear it.'];
    }
    if ($link === '') {
        $label = '';
    } elseif ($label === '') {
        $label = 'Learn more';
    }
    return ['ok' => true, 'data' => ['title' => $title, 'body' => $body, 'level' => $level, 'audience' => $audience,
        'link_url' => $link, 'link_label' => $label, 'starts_at' => $starts, 'ends_at' => $ends]];
}

// ───────────────────────────── the admin side ─────────────────────────────

/** draft | scheduled | live | ended | archived */
function announcement_state(array $a, ?string $now = null): string
{
    $now = $now ?? announcement_now();
    if ($a['status'] === 'draft') {
        return 'draft';
    }
    if ($a['status'] === 'archived') {
        return 'archived';
    }
    if (!empty($a['starts_at']) && $a['starts_at'] > $now) {
        return 'scheduled';
    }
    if (!empty($a['ends_at']) && $a['ends_at'] <= $now) {
        return 'ended';
    }
    return 'live';
}

function announcement_get(int $id): ?array
{
    $st = platform_conn()->prepare('SELECT * FROM announcements WHERE id = ?');
    $st->bind_param('i', $id);
    $st->execute();
    return $st->get_result()->fetch_assoc() ?: null;
}

/** Every announcement, with how many people have read it. */
function announcement_list_admin(): array
{
    $res = mysqli_query(platform_conn(), "SELECT a.*,
            (SELECT COUNT(*) FROM announcement_reads r WHERE r.announcement_id = a.id) AS reads_people,
            (SELECT COUNT(DISTINCT r.company_id) FROM announcement_reads r WHERE r.announcement_id = a.id) AS reads_businesses
        FROM announcements a
        ORDER BY FIELD(a.status, 'published', 'draft', 'archived'), COALESCE(a.starts_at, a.published_at, a.created_at) DESC, a.id DESC LIMIT 300");
    return $res->fetch_all(MYSQLI_ASSOC);
}

/**
 * Creates (id = null) or changes an announcement. $submit: 'publish' (publish it now) or 'save' (keep its state; a new one is a draft).
 *
 * @return array{ok:bool, id?:int, error?:string}
 */
function announcement_save(?int $id, array $in, string $submit, array $admin, bool $showAgain = false): array
{
    $existing = $id ? announcement_get($id) : null;
    if ($id && !$existing) {
        return ['ok' => false, 'error' => 'That announcement does not exist any more.'];
    }
    $status = $existing ? (string) $existing['status'] : 'draft';
    if ($submit === 'publish' && $status !== 'archived') {
        $status = 'published';
    }
    $v = announcement_validate($in, $status === 'published');
    if (!$v['ok']) {
        return $v;
    }
    $d = $v['data'];
    try {
        $db = platform_conn();
        $link = $d['link_url'] !== '' ? $d['link_url'] : null;
        $label = $d['link_label'] !== '' ? $d['link_label'] : null;
        if ($existing) {
            $st = $db->prepare("UPDATE announcements SET title = ?, body = ?, level = ?, audience = ?, link_url = ?, link_label = ?, starts_at = ?, ends_at = ?,
                    status = ?, published_at = IF(? = 'published', COALESCE(published_at, ?), published_at) WHERE id = ?");
            $publishedNow = announcement_now();   // the same clock as the dates the admin types (never the database's own clock)
            $st->bind_param('sssssssssssi', $d['title'], $d['body'], $d['level'], $d['audience'], $link, $label, $d['starts_at'], $d['ends_at'], $status, $status, $publishedNow, $id);
            $st->execute();
            if ($showAgain) {
                $reset = $db->prepare('DELETE FROM announcement_reads WHERE announcement_id = ?');
                $reset->bind_param('i', $id);
                $reset->execute();
            }
            return ['ok' => true, 'id' => $id];
        }
        $aid = (int) $admin['id'];
        $aname = announcement_clean($admin['name'] ?? '', 150);
        $st = $db->prepare("INSERT INTO announcements (title, body, level, audience, link_url, link_label, starts_at, ends_at, status, published_at, created_by, created_by_name)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, IF(? = 'published', ?, NULL), ?, ?)");
        $publishedNow = announcement_now();   // the same clock as the dates the admin types (never the database's own clock)
        $st->bind_param('sssssssssssis', $d['title'], $d['body'], $d['level'], $d['audience'], $link, $label, $d['starts_at'], $d['ends_at'], $status, $status, $publishedNow, $aid, $aname);
        $st->execute();
        return ['ok' => true, 'id' => (int) $db->insert_id];
    } catch (Throwable $e) {
        error_log('[announcement] save failed: ' . $e->getMessage());
        return ['ok' => false, 'error' => 'The announcement could not be saved. Nothing was changed. Please try again.'];
    }
}

/**
 * publish | unpublish | archive | restore | delete.  A published announcement must be archived before it can be deleted.
 *
 * @return array{ok:bool, message:string}
 */
function announcement_action(int $id, string $action): array
{
    $a = announcement_get($id);
    if (!$a) {
        return ['ok' => false, 'message' => 'That announcement does not exist any more.'];
    }
    $db = platform_conn();
    $status = (string) $a['status'];
    try {
        if ($action === 'publish' && $status === 'draft') {
            $v = announcement_validate($a, true);
            if (!$v['ok']) {
                return ['ok' => false, 'message' => $v['error']];
            }
            $st = $db->prepare("UPDATE announcements SET status = 'published', published_at = COALESCE(published_at, ?) WHERE id = ?");
            $publishNow = announcement_now();
            $msg = 'Published. People will see it now' . (!empty($a['starts_at']) && $a['starts_at'] > announcement_now() ? ' (from its start time).' : '.');
        } elseif ($action === 'unpublish' && $status === 'published') {
            $st = $db->prepare("UPDATE announcements SET status = 'draft' WHERE id = ?");
            $msg = 'Unpublished. It is a draft again and nobody sees it.';
        } elseif ($action === 'archive' && in_array($status, ['draft', 'published'], true)) {
            $st = $db->prepare("UPDATE announcements SET status = 'archived' WHERE id = ?");
            $msg = 'Archived. Nobody sees it any more.';
        } elseif ($action === 'restore' && $status === 'archived') {
            $st = $db->prepare("UPDATE announcements SET status = 'draft' WHERE id = ?");
            $msg = 'Restored as a draft.';
        } elseif ($action === 'delete' && in_array($status, ['draft', 'archived'], true)) {
            $st = $db->prepare('DELETE FROM announcements WHERE id = ?');
            $msg = 'Deleted.';
        } else {
            return ['ok' => false, 'message' => $action === 'delete' ? 'A published announcement cannot be deleted. Archive it first.' : 'That is not possible for this announcement right now.'];
        }
        if ($action === 'publish') {
            $st->bind_param('si', $publishNow, $id);
        } else {
            $st->bind_param('i', $id);
        }
        $st->execute();
        return ['ok' => true, 'message' => $msg];
    } catch (Throwable $e) {
        error_log('[announcement] action failed: ' . $e->getMessage());
        return ['ok' => false, 'message' => 'The change could not be saved. Please try again.'];
    }
}

// ───────────────────────────── the business side ─────────────────────────────

/** The part of a query that says "an announcement this person may see now". Parameters: now, now, isAdmin (1/0). */
const ANNOUNCEMENT_VISIBLE_SQL = "a.status = 'published' AND (a.starts_at IS NULL OR a.starts_at <= ?) AND (a.ends_at IS NULL OR a.ends_at > ?)
    AND (a.audience = 'everyone' OR ? = 1)";

/** The announcements this person can see now. $unreadOnly: only the ones they have not read. */
function announcement_for_user(int $companyId, int $userId, bool $isAdmin, bool $unreadOnly = false, int $limit = 50): array
{
    $now = announcement_now();
    $admin = $isAdmin ? 1 : 0;
    $st = platform_conn()->prepare('SELECT a.*, (r.user_id IS NOT NULL) AS is_read FROM announcements a
        LEFT JOIN announcement_reads r ON r.announcement_id = a.id AND r.company_id = ? AND r.user_id = ?
        WHERE ' . ANNOUNCEMENT_VISIBLE_SQL . ($unreadOnly ? ' AND r.user_id IS NULL' : '')
        . " ORDER BY FIELD(a.level, 'important', 'warning', 'success', 'info'), COALESCE(a.starts_at, a.published_at) DESC, a.id DESC LIMIT ?");
    $st->bind_param('iissii', $companyId, $userId, $now, $now, $admin, $limit);
    $st->execute();
    return $st->get_result()->fetch_all(MYSQLI_ASSOC);
}

/** The ones that ended in the last 90 days (so people can still find what they missed). */
function announcement_earlier(bool $isAdmin, int $limit = 30): array
{
    $now = announcement_now();
    $since = date('Y-m-d H:i:s', time() - ANNOUNCEMENT_HISTORY_DAYS * 86400);
    $admin = $isAdmin ? 1 : 0;
    $st = platform_conn()->prepare("SELECT a.* FROM announcements a WHERE a.status = 'published' AND a.ends_at IS NOT NULL AND a.ends_at <= ? AND a.ends_at > ?
        AND (a.audience = 'everyone' OR ? = 1) ORDER BY a.ends_at DESC, a.id DESC LIMIT ?");
    $st->bind_param('ssii', $now, $since, $admin, $limit);
    $st->execute();
    return $st->get_result()->fetch_all(MYSQLI_ASSOC);
}

/** How many announcements this person has not read yet. 0 if the tables are not installed yet. */
function announcement_unread_count(int $companyId, int $userId, bool $isAdmin): int
{
    try {
        $now = announcement_now();
        $admin = $isAdmin ? 1 : 0;
        $st = platform_conn()->prepare('SELECT COUNT(*) FROM announcements a
            LEFT JOIN announcement_reads r ON r.announcement_id = a.id AND r.company_id = ? AND r.user_id = ?
            WHERE ' . ANNOUNCEMENT_VISIBLE_SQL . ' AND r.user_id IS NULL');
        $st->bind_param('iissi', $companyId, $userId, $now, $now, $admin);
        $st->execute();
        return (int) $st->get_result()->fetch_row()[0];
    } catch (Throwable $e) {
        return 0;
    }
}

/** Marks one announcement as read, but only if this person is allowed to see it (so nobody can fill the table with guesses). */
function announcement_mark_read(int $companyId, int $userId, bool $isAdmin, int $announcementId): bool
{
    $now = announcement_now();
    $admin = $isAdmin ? 1 : 0;
    $st = platform_conn()->prepare('INSERT IGNORE INTO announcement_reads (announcement_id, company_id, user_id)
        SELECT a.id, ?, ? FROM announcements a WHERE a.id = ? AND ' . ANNOUNCEMENT_VISIBLE_SQL);
    $st->bind_param('iiissi', $companyId, $userId, $announcementId, $now, $now, $admin);
    $st->execute();
    return $st->affected_rows > 0;
}

/** Marks everything this person can see now as read. */
function announcement_mark_all_read(int $companyId, int $userId, bool $isAdmin): void
{
    $now = announcement_now();
    $admin = $isAdmin ? 1 : 0;
    $st = platform_conn()->prepare('INSERT IGNORE INTO announcement_reads (announcement_id, company_id, user_id)
        SELECT a.id, ?, ? FROM announcements a WHERE ' . ANNOUNCEMENT_VISIBLE_SQL);
    $st->bind_param('iissi', $companyId, $userId, $now, $now, $admin);
    $st->execute();
}

/** One announcement as a card. Used on the business page and in the admin's preview. */
function announcement_card_html(array $a, bool $isNew = false, bool $showAudience = false): string
{
    $c = announcement_alert_class((string) $a['level']);
    $when = $a['starts_at'] ?? null ?: ($a['published_at'] ?? null ?: ($a['created_at'] ?? ''));
    $html = '<div class="card mb-3 border-start border-4 border-' . $c . '"><div class="card-body">'
        . '<div class="d-flex justify-content-between align-items-start flex-wrap gap-2 mb-2"><div>'
        . '<span class="badge text-bg-' . $c . ' me-2">' . announcement_h(ANNOUNCEMENT_LEVELS[$a['level']] ?? $a['level']) . '</span>'
        . ($isNew ? '<span class="badge text-bg-danger me-2">New</span>' : '')
        . ($showAudience && $a['audience'] === 'admins' ? '<span class="badge text-bg-secondary me-2">For business Admins</span>' : '')
        . '<strong>' . announcement_h($a['title']) . '</strong></div>'
        . ($when !== '' ? '<span class="text-muted small">' . announcement_h(date('d M Y', strtotime((string) $when))) . '</span>' : '') . '</div>'
        . '<div style="white-space:pre-wrap;word-break:break-word;">' . announcement_h($a['body']) . '</div>';
    if (!empty($a['link_url'])) {
        $html .= '<div class="mt-3"><a class="btn btn-sm btn-outline-' . $c . '" href="' . announcement_h($a['link_url']) . '" target="_blank" rel="noopener noreferrer">'
            . announcement_h($a['link_label'] ?: 'Learn more') . '</a></div>';
    }
    return $html . '</div></div>';
}
