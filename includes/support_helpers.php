<?php
/**
 * NEW FILE: includes/support_helpers.php
 *
 * Support tickets. A business raises a ticket from its own dashboard; the platform admin answers it in the admin portal.
 * Both sides use this file. The tickets live in the PLATFORM database (support_tickets, support_ticket_messages), so the admin
 * sees the tickets of every business in one place.
 *
 * Rules kept here:
 *   - A business only ever sees its own tickets (the business comes from the SIGNED-IN session, never from the URL or a form).
 *   - Inside a business: the Admin sees every ticket of the business, anyone else only the tickets they raised themselves.
 *   - Internal notes written by the platform admin are never shown to the business, and never emailed to it.
 *   - A closed ticket cannot be answered again: raise a new one. A resolved ticket re-opens when the business answers it.
 *   - Everything typed is stored as plain text and shown escaped, so nothing a user types can change a page.
 *   - If the ticket tables are not installed yet (the migration has not run), the menu badge and the dashboard card simply
 *     stay quiet instead of breaking the page.
 */
require_once __DIR__ . '/platform.php';
require_once __DIR__ . '/platform_mail.php';

/** What the business can say the ticket is about. */
const SUPPORT_CATEGORIES = [
    'not_working' => 'Something is not working',
    'how_to'      => 'I need help using the system',
    'account'     => 'My account, users or package',
    'billing'     => 'Payment or billing',
    'data'        => 'Wrong or missing data or reports',
    'suggestion'  => 'A suggestion or a new feature',
    'other'       => 'Something else',
];
const SUPPORT_PRIORITIES = ['normal' => 'Normal', 'high' => 'High: it slows my work', 'urgent' => 'Urgent: I cannot work'];
const SUPPORT_STATUSES = ['open', 'in_progress', 'waiting_customer', 'resolved', 'closed'];

const SUPPORT_MAX_PER_HOUR_PER_USER = 5;
const SUPPORT_MAX_PER_DAY_PER_BUSINESS = 40;

function support_h($v): string
{
    return htmlspecialchars((string) ($v ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/** The words shown for a status. The admin and the business see slightly different wording for one of them. */
function support_status_label(string $status, bool $forBusiness = true): string
{
    $labels = ['open' => 'Open', 'in_progress' => 'In progress', 'waiting_customer' => $forBusiness ? 'Waiting for your answer' : 'Waiting for the business',
        'resolved' => 'Resolved', 'closed' => 'Closed'];
    return $labels[$status] ?? $status;
}

function support_status_badge(string $status): string
{
    return ['open' => 'primary', 'in_progress' => 'info', 'waiting_customer' => 'warning', 'resolved' => 'success', 'closed' => 'secondary'][$status] ?? 'secondary';
}

function support_priority_badge(string $priority): string
{
    return ['normal' => 'secondary', 'high' => 'warning', 'urgent' => 'danger'][$priority] ?? 'secondary';
}

/** Plain text, no control characters, cut to a length. */
function support_clean($value, int $max): string
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

function support_csrf_token(): string
{
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    if (empty($_SESSION['support_csrf'])) {
        $_SESSION['support_csrf'] = bin2hex(random_bytes(24));
    }
    return (string) $_SESSION['support_csrf'];
}

function support_csrf_ok(): bool
{
    $sent = $_POST['csrf'] ?? '';
    return is_string($sent) && $sent !== '' && !empty($_SESSION['support_csrf']) && hash_equals((string) $_SESSION['support_csrf'], $sent);
}

// ───────────────────────────── notices by email (never allowed to break a ticket) ─────────────────────────────

function support_ticket_link_admin(int $id): string
{
    return platform_public_url() . '/platform/ticket.php?id=' . $id;
}

function support_ticket_link_company(int $id): string
{
    return platform_public_url() . '/modules/support/view.php?id=' . $id;
}

/** To every active platform admin. */
function support_email_admins(string $subject, string $heading, string $bodyHtml): void
{
    try {
        $res = mysqli_query(platform_conn(), 'SELECT name, email FROM platform_admins WHERE is_active = 1');
        while ($a = mysqli_fetch_assoc($res)) {
            platform_send_email((string) $a['email'], (string) $a['name'], $subject, platform_mail_wrap($heading, $bodyHtml));
        }
    } catch (Throwable $e) {
        error_log('[support] could not email the admins: ' . $e->getMessage());
    }
}

function support_email_business(array $ticket, string $subject, string $heading, string $bodyHtml): void
{
    try {
        $to = (string) ($ticket['contact_email'] ?? '');
        if (filter_var($to, FILTER_VALIDATE_EMAIL)) {
            platform_send_email($to, (string) $ticket['user_name'], $subject, platform_mail_wrap($heading, $bodyHtml));
        }
    } catch (Throwable $e) {
        error_log('[support] could not email the business: ' . $e->getMessage());
    }
}

function support_quote(string $text): string
{
    return '<div style="background:#F4F6FB;border-left:3px solid #4C6FFF;padding:10px 14px;margin:12px 0;white-space:pre-wrap">' . support_h($text) . '</div>';
}

// ───────────────────────────── the business side ─────────────────────────────

/**
 * Raises a ticket. $company = row of the platform "companies" table (id, company_code, name).
 * $user = id, name, role, email, phone of the signed-in person. $in = category, priority, subject, message, email, phone, page.
 *
 * @return array{ok:bool, id?:int, reference?:string, error?:string}
 */
function support_create(array $company, array $user, array $in): array
{
    $category = (string) ($in['category'] ?? '');
    $priority = (string) ($in['priority'] ?? 'normal');
    $subject = support_clean($in['subject'] ?? '', 160);
    $message = support_clean($in['message'] ?? '', 5000);
    $email = support_clean($in['email'] ?? '', 190);
    $phone = support_clean($in['phone'] ?? '', 30);
    $page = support_clean($in['page'] ?? '', 200);

    if (!isset(SUPPORT_CATEGORIES[$category])) {
        return ['ok' => false, 'error' => 'Choose what the ticket is about.'];
    }
    if (!isset(SUPPORT_PRIORITIES[$priority])) {
        return ['ok' => false, 'error' => 'Choose how urgent it is.'];
    }
    if (mb_strlen($subject) < 5) {
        return ['ok' => false, 'error' => 'Write a short title of at least 5 characters, for example: "Cannot delete a payroll".'];
    }
    if (mb_strlen($message) < 10) {
        return ['ok' => false, 'error' => 'Describe the problem in at least 10 characters: what you did and what you saw.'];
    }
    if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return ['ok' => false, 'error' => 'The email address is not valid. Leave it empty if you do not want an email reply.'];
    }
    if ($phone !== '' && !preg_match('/^[0-9+() \-]{6,30}$/', $phone)) {
        return ['ok' => false, 'error' => 'The phone number is not valid.'];
    }

    try {
        $db = platform_conn();
        $companyId = (int) $company['id'];
        $userId = (int) $user['id'];

        $hour = $db->prepare('SELECT COUNT(*) FROM support_tickets WHERE company_id = ? AND company_user_id = ? AND created_at >= (NOW() - INTERVAL 1 HOUR)');
        $hour->bind_param('ii', $companyId, $userId);
        $hour->execute();
        if ((int) $hour->get_result()->fetch_row()[0] >= SUPPORT_MAX_PER_HOUR_PER_USER) {
            return ['ok' => false, 'error' => 'You have raised several tickets in the last hour. Please wait for an answer, or add your new details to a ticket you already have.'];
        }
        $day = $db->prepare('SELECT COUNT(*) FROM support_tickets WHERE company_id = ? AND created_at >= (NOW() - INTERVAL 1 DAY)');
        $day->bind_param('i', $companyId);
        $day->execute();
        if ((int) $day->get_result()->fetch_row()[0] >= SUPPORT_MAX_PER_DAY_PER_BUSINESS) {
            return ['ok' => false, 'error' => 'Your business has raised many tickets today. Please add your details to an existing ticket, or try again tomorrow.'];
        }

        $db->begin_transaction();
        $temp = 'T' . bin2hex(random_bytes(8));
        $code = (string) $company['company_code'];
        $name = (string) $company['name'];
        $uname = support_clean($user['name'] ?? 'User', 150);
        $urole = support_clean($user['role'] ?? '', 30);
        $emailOrNull = $email !== '' ? $email : null;
        $phoneOrNull = $phone !== '' ? $phone : null;
        $pageOrNull = $page !== '' ? $page : null;
        $ins = $db->prepare("INSERT INTO support_tickets (reference, company_id, company_code, company_name, company_user_id, user_name, user_role, contact_email, contact_phone,
                category, priority, subject, page_hint, status, company_unread, platform_unread, last_activity_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'open', 0, 1, NOW())");
        $ins->bind_param('sissi' . str_repeat('s', 8), $temp, $companyId, $code, $name, $userId, $uname, $urole, $emailOrNull, $phoneOrNull, $category, $priority, $subject, $pageOrNull);
        $ins->execute();
        $id = (int) $db->insert_id;
        $reference = 'TK-' . str_pad((string) $id, 6, '0', STR_PAD_LEFT);
        $ref = $db->prepare('UPDATE support_tickets SET reference = ? WHERE id = ?');
        $ref->bind_param('si', $reference, $id);
        $ref->execute();
        $msg = $db->prepare("INSERT INTO support_ticket_messages (ticket_id, author_type, author_id, author_name, body) VALUES (?, 'company', ?, ?, ?)");
        $msg->bind_param('iiss', $id, $userId, $uname, $message);
        $msg->execute();
        $db->commit();
    } catch (Throwable $e) {
        try {
            platform_conn()->rollback();
        } catch (Throwable $ignore) {
        }
        error_log('[support] create failed: ' . $e->getMessage());
        return ['ok' => false, 'error' => 'The ticket could not be saved. Nothing was sent. Please try again in a moment.'];
    }

    $body = '<p><strong>' . support_h($name) . '</strong> (' . support_h($code) . ') raised a ticket.</p>'
        . '<p>From: ' . support_h($uname) . ($urole !== '' ? ' (' . support_h($urole) . ')' : '') . '<br>Email: ' . support_h($email ?: 'none') . '<br>Phone: ' . support_h($phone ?: 'none') . '<br>'
        . 'About: ' . support_h(SUPPORT_CATEGORIES[$category]) . '<br>Urgency: ' . support_h(SUPPORT_PRIORITIES[$priority]) . '<br>Subject: <strong>' . support_h($subject) . '</strong></p>'
        . support_quote($message)
        . '<p><a href="' . support_h(support_ticket_link_admin($id)) . '">Open the ticket</a></p>';
    support_email_admins('[' . $reference . '] New ticket from ' . $name . ': ' . $subject, 'New support ticket ' . $reference, $body);
    return ['ok' => true, 'id' => $id, 'reference' => $reference];
}

/** The tickets this person may see. Admin of the business: all of them. Anyone else: only their own. */
function support_company_tickets(int $companyId, int $userId, bool $isAdmin, string $filter = 'all'): array
{
    $where = 'company_id = ?' . ($isAdmin ? '' : ' AND company_user_id = ?');
    $where .= [
        'active'   => " AND status IN ('open', 'in_progress', 'waiting_customer')",
        'answered' => ' AND (company_unread = 1)',
        'resolved' => " AND status = 'resolved'",
        'closed'   => " AND status = 'closed'",
    ][$filter] ?? '';
    $st = platform_conn()->prepare('SELECT * FROM support_tickets WHERE ' . $where . ' ORDER BY last_activity_at DESC, id DESC LIMIT 300');
    if ($isAdmin) {
        $st->bind_param('i', $companyId);
    } else {
        $st->bind_param('ii', $companyId, $userId);
    }
    $st->execute();
    return $st->get_result()->fetch_all(MYSQLI_ASSOC);
}

/** One ticket, only if this person may see it, otherwise null. */
function support_company_ticket(int $id, int $companyId, int $userId, bool $isAdmin): ?array
{
    $st = platform_conn()->prepare('SELECT * FROM support_tickets WHERE id = ? AND company_id = ? AND (company_user_id = ? OR ? = 1)');
    $admin = $isAdmin ? 1 : 0;
    $st->bind_param('iiii', $id, $companyId, $userId, $admin);
    $st->execute();
    $row = $st->get_result()->fetch_assoc();
    return $row ?: null;
}

/** The conversation. The business never gets the internal notes. */
function support_messages(int $ticketId, bool $forBusiness): array
{
    $st = platform_conn()->prepare('SELECT * FROM support_ticket_messages WHERE ticket_id = ?' . ($forBusiness ? ' AND is_internal = 0' : '') . ' ORDER BY id');
    $st->bind_param('i', $ticketId);
    $st->execute();
    return $st->get_result()->fetch_all(MYSQLI_ASSOC);
}

function support_mark_read_by_business(int $ticketId): void
{
    $st = platform_conn()->prepare('UPDATE support_tickets SET company_unread = 0 WHERE id = ? AND company_unread = 1');
    $st->bind_param('i', $ticketId);
    $st->execute();
}

/**
 * The business answers. @return array{ok:bool, error?:string}
 */
function support_company_reply(array $ticket, array $user, string $body): array
{
    $body = support_clean($body, 5000);
    if (mb_strlen($body) < 2) {
        return ['ok' => false, 'error' => 'Write your answer first.'];
    }
    if ($ticket['status'] === 'closed') {
        return ['ok' => false, 'error' => 'This ticket is closed. Please raise a new ticket.'];
    }
    try {
        $db = platform_conn();
        $id = (int) $ticket['id'];
        $uid = (int) $user['id'];
        $uname = support_clean($user['name'] ?? 'User', 150);
        $db->begin_transaction();
        $m = $db->prepare("INSERT INTO support_ticket_messages (ticket_id, author_type, author_id, author_name, body) VALUES (?, 'company', ?, ?, ?)");
        $m->bind_param('iiss', $id, $uid, $uname, $body);
        $m->execute();
        // An answer to a "resolved" or "waiting for you" ticket puts it back in the admin's list as open.
        // resolved_at is decided BEFORE status is changed: in one UPDATE, MySQL uses the new value of a column that was just set.
        $t = $db->prepare("UPDATE support_tickets SET platform_unread = 1, company_unread = 0, last_activity_at = NOW(),
            resolved_at = IF(status = 'resolved', NULL, resolved_at), status = IF(status IN ('resolved', 'waiting_customer'), 'open', status) WHERE id = ?");
        $t->bind_param('i', $id);
        $t->execute();
        $db->commit();
    } catch (Throwable $e) {
        try {
            platform_conn()->rollback();
        } catch (Throwable $ignore) {
        }
        error_log('[support] company reply failed: ' . $e->getMessage());
        return ['ok' => false, 'error' => 'Your answer could not be saved. Please try again.'];
    }
    support_email_admins('[' . $ticket['reference'] . '] ' . $ticket['company_name'] . ' answered: ' . $ticket['subject'], 'New answer on ' . $ticket['reference'],
        '<p><strong>' . support_h($ticket['company_name']) . '</strong>, ' . support_h($user['name'] ?? '') . ' wrote:</p>' . support_quote($body)
        . '<p><a href="' . support_h(support_ticket_link_admin((int) $ticket['id'])) . '">Open the ticket</a></p>');
    return ['ok' => true];
}

/** The business closes its own ticket (the one who raised it, or the Admin of the business). */
function support_company_close(array $ticket, array $user): bool
{
    if ($ticket['status'] === 'closed') {
        return false;
    }
    try {
        $db = platform_conn();
        $id = (int) $ticket['id'];
        $uid = (int) $user['id'];
        $uname = support_clean($user['name'] ?? 'User', 150);
        $db->begin_transaction();
        $note = 'Closed by ' . $uname . '.';
        $m = $db->prepare("INSERT INTO support_ticket_messages (ticket_id, author_type, author_id, author_name, body) VALUES (?, 'company', ?, ?, ?)");
        $m->bind_param('iiss', $id, $uid, $uname, $note);
        $m->execute();
        $t = $db->prepare("UPDATE support_tickets SET status = 'closed', closed_at = NOW(), last_activity_at = NOW(), company_unread = 0 WHERE id = ? AND status <> 'closed'");
        $t->bind_param('i', $id);
        $t->execute();
        $db->commit();
        return true;
    } catch (Throwable $e) {
        try {
            platform_conn()->rollback();
        } catch (Throwable $ignore) {
        }
        error_log('[support] close failed: ' . $e->getMessage());
        return false;
    }
}

/** How many tickets this person has that the platform answered and they have not read. 0 if the tables are not installed yet. */
function support_company_unread_count(int $companyId, int $userId, bool $isAdmin): int
{
    try {
        $st = platform_conn()->prepare('SELECT COUNT(*) FROM support_tickets WHERE company_id = ? AND company_unread = 1' . ($isAdmin ? '' : ' AND company_user_id = ?'));
        if ($isAdmin) {
            $st->bind_param('i', $companyId);
        } else {
            $st->bind_param('ii', $companyId, $userId);
        }
        $st->execute();
        return (int) $st->get_result()->fetch_row()[0];
    } catch (Throwable $e) {
        return 0;
    }
}

/** The signed-in person, from the business's own database. */
function support_current_user(mysqli $conn): array
{
    $id = (int) ($_SESSION['user_id'] ?? 0);
    $name = (string) ($_SESSION['user_name'] ?? 'User');
    $email = '';
    $phone = '';
    try {
        $st = $conn->prepare('SELECT names, email, phone FROM users WHERE id = ?');
        $st->bind_param('i', $id);
        $st->execute();
        if ($row = $st->get_result()->fetch_assoc()) {
            $name = (string) $row['names'];
            $email = (string) ($row['email'] ?? '');
            $phone = (string) ($row['phone'] ?? '');
        }
    } catch (Throwable $e) {
    }
    return ['id' => $id, 'name' => $name, 'role' => (string) ($_SESSION['user_role'] ?? ''), 'email' => $email, 'phone' => $phone];
}

// ───────────────────────────── the platform admin side ─────────────────────────────

/**
 * The list for the admin. $f: status ('all' | 'active' | one status), priority, q (business / subject / reference / person), unread (1).
 */
function support_admin_tickets(array $f): array
{
    $where = ['1 = 1'];
    $types = '';
    $params = [];
    $status = (string) ($f['status'] ?? 'active');
    if ($status === 'active') {
        $where[] = "status IN ('open', 'in_progress', 'waiting_customer')";
    } elseif (in_array($status, SUPPORT_STATUSES, true)) {
        $where[] = 'status = ?';
        $types .= 's';
        $params[] = $status;
    }
    $priority = (string) ($f['priority'] ?? '');
    if (isset(SUPPORT_PRIORITIES[$priority])) {
        $where[] = 'priority = ?';
        $types .= 's';
        $params[] = $priority;
    }
    if (!empty($f['unread'])) {
        $where[] = "platform_unread = 1 AND status <> 'closed'";
    }
    $q = support_clean($f['q'] ?? '', 80);
    if ($q !== '') {
        $like = '%' . str_replace(['%', '_'], ['\\%', '\\_'], $q) . '%';
        $where[] = '(reference LIKE ? OR company_name LIKE ? OR company_code LIKE ? OR subject LIKE ? OR user_name LIKE ?)';
        $types .= 'sssss';
        array_push($params, $like, $like, $like, $like, $like);
    }
    $st = platform_conn()->prepare('SELECT * FROM support_tickets WHERE ' . implode(' AND ', $where)
        . " ORDER BY platform_unread DESC, FIELD(priority, 'urgent', 'high', 'normal'), last_activity_at DESC, id DESC LIMIT 300");
    if ($types !== '') {
        $st->bind_param($types, ...$params);
    }
    $st->execute();
    return $st->get_result()->fetch_all(MYSQLI_ASSOC);
}

function support_admin_ticket(int $id): ?array
{
    $st = platform_conn()->prepare('SELECT * FROM support_tickets WHERE id = ?');
    $st->bind_param('i', $id);
    $st->execute();
    $row = $st->get_result()->fetch_assoc();
    return $row ?: null;
}

function support_admin_mark_read(int $id): void
{
    $st = platform_conn()->prepare('UPDATE support_tickets SET platform_unread = 0 WHERE id = ? AND platform_unread = 1');
    $st->bind_param('i', $id);
    $st->execute();
}

/** Tickets that need an admin: written by a business and not read yet. 0 if the tables are not installed yet. */
function support_admin_unread_count(): int
{
    try {
        return (int) mysqli_fetch_row(mysqli_query(platform_conn(), "SELECT COUNT(*) FROM support_tickets WHERE platform_unread = 1 AND status <> 'closed'"))[0];
    } catch (Throwable $e) {
        return 0;
    }
}

function support_admin_counts(): array
{
    $out = ['active' => 0, 'unread' => 0, 'resolved' => 0, 'closed' => 0, 'urgent' => 0];
    try {
        $r = mysqli_fetch_assoc(mysqli_query(platform_conn(), "SELECT
            SUM(status IN ('open','in_progress','waiting_customer')) AS active, SUM(platform_unread = 1 AND status <> 'closed') AS unread,
            SUM(status = 'resolved') AS resolved, SUM(status = 'closed') AS closed,
            SUM(priority = 'urgent' AND status IN ('open','in_progress','waiting_customer')) AS urgent FROM support_tickets"));
        foreach ($out as $k => $_) {
            $out[$k] = (int) ($r[$k] ?? 0);
        }
    } catch (Throwable $e) {
    }
    return $out;
}

/**
 * The admin answers (public) or writes an internal note (only admins see it).
 * $newStatus: the status after this answer (ignored for an internal note).
 *
 * @return array{ok:bool, error?:string}
 */
function support_admin_reply(array $ticket, array $admin, string $body, bool $internal, string $newStatus): array
{
    $body = support_clean($body, 5000);
    if (mb_strlen($body) < 2) {
        return ['ok' => false, 'error' => 'Write the answer first.'];
    }
    if (!in_array($newStatus, SUPPORT_STATUSES, true)) {
        $newStatus = (string) $ticket['status'];
    }
    try {
        $db = platform_conn();
        $id = (int) $ticket['id'];
        $aid = (int) $admin['id'];
        $aname = support_clean($admin['name'] ?? 'Support', 150);
        $flag = $internal ? 1 : 0;
        $db->begin_transaction();
        $m = $db->prepare("INSERT INTO support_ticket_messages (ticket_id, author_type, author_id, author_name, body, is_internal) VALUES (?, 'platform', ?, ?, ?, ?)");
        $m->bind_param('iissi', $id, $aid, $aname, $body, $flag);
        $m->execute();
        if ($internal) {
            $t = $db->prepare('UPDATE support_tickets SET last_activity_at = NOW() WHERE id = ?');
            $t->bind_param('i', $id);
        } else {
            $t = $db->prepare("UPDATE support_tickets SET status = ?, company_unread = 1, platform_unread = 0, last_activity_at = NOW(),
                resolved_at = IF(? = 'resolved', COALESCE(resolved_at, NOW()), IF(? = 'closed', resolved_at, NULL)),
                closed_at = IF(? = 'closed', COALESCE(closed_at, NOW()), NULL), assigned_admin_id = COALESCE(assigned_admin_id, ?) WHERE id = ?");
            $t->bind_param('ssssii', $newStatus, $newStatus, $newStatus, $newStatus, $aid, $id);
        }
        $t->execute();
        $db->commit();
    } catch (Throwable $e) {
        try {
            platform_conn()->rollback();
        } catch (Throwable $ignore) {
        }
        error_log('[support] admin reply failed: ' . $e->getMessage());
        return ['ok' => false, 'error' => 'The answer could not be saved. Please try again.'];
    }
    if (!$internal) {
        support_email_business($ticket, '[' . $ticket['reference'] . '] MY MOTIVE support answered: ' . $ticket['subject'], 'Your ticket ' . $ticket['reference'] . ' has an answer',
            '<p>Hello ' . support_h($ticket['user_name']) . ',</p><p>The MY MOTIVE support team wrote:</p>' . support_quote($body)
            . '<p>Status: <strong>' . support_h(support_status_label($newStatus)) . '</strong></p>'
            . '<p><a href="' . support_h(support_ticket_link_company((int) $ticket['id'])) . '">Open the ticket to read it and answer</a> (you need to sign in to your business first).</p>');
    }
    return ['ok' => true];
}

/** Priority and who looks after the ticket. $assign: 'me' | 'none' | null (leave as it is). */
function support_admin_update(array $ticket, array $admin, ?string $priority, ?string $assign): bool
{
    try {
        $db = platform_conn();
        $id = (int) $ticket['id'];
        if ($priority !== null && isset(SUPPORT_PRIORITIES[$priority])) {
            $st = $db->prepare('UPDATE support_tickets SET priority = ? WHERE id = ?');
            $st->bind_param('si', $priority, $id);
            $st->execute();
        }
        if ($assign === 'me') {
            $aid = (int) $admin['id'];
            $st = $db->prepare('UPDATE support_tickets SET assigned_admin_id = ? WHERE id = ?');
            $st->bind_param('ii', $aid, $id);
            $st->execute();
        } elseif ($assign === 'none') {
            $st = $db->prepare('UPDATE support_tickets SET assigned_admin_id = NULL WHERE id = ?');
            $st->bind_param('i', $id);
            $st->execute();
        }
        return true;
    } catch (Throwable $e) {
        error_log('[support] admin update failed: ' . $e->getMessage());
        return false;
    }
}
