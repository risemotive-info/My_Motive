<?php
/**
 * NEW FILE: includes/timetable_helpers.php
 * WORK TIMETABLE: database side. The rules themselves are in timetable_engine.php (no database there).
 *
 *   tt_schedules / tt_schedule_breaks   each employee's timetable. NEVER edited in place: a change is a new
 *                                       version from a date (today or later); a temporary adjustment covers a date
 *                                       range without touching the permanent timetable. History stays as it was.
 *   tt_events                           check-in, break start/return, post movements, sign-out. Time = SERVER time.
 *                                       One of each per day (unique key): a double click never records twice.
 *                                       Employees cannot edit them; changes only through an approved correction.
 *   tt_corrections                      "I forgot to record my return" -> request -> supervisor decides -> audited.
 *   tt_extensions                       a supervisor extends a break or a post return for one day.
 *   tt_overtime                         time after the official departure, waiting for approval (then to payroll).
 *   tt_notification_log                 every reminder per channel, unique: never sent twice; delivery history.
 *   tt_holidays, tt_settings, tt_audit  public holidays, configuration, the audit trail (append only).
 *
 * Approved exceptions come from Leave Requests (status 'Approved' only): full-day leave types make the day a
 * leave day; the timed types below change the day's rules for their hours.
 */
require_once __DIR__ . '/timetable_engine.php';
require_once __DIR__ . '/access_control.php'; // access_can(): needed even where config/db.php does not load it (live server)
require_once __DIR__ . '/mailer.php';

/** Leave types with hours (one date, start and end time), and what each one does in the timetable. */
const TT_TIMED_LEAVE = [
    'Short Leave'         => 'Away for the hours given: not lost time.',
    'Late Arrival'        => 'May arrive up to the END time without being late.',
    'Early Departure'     => 'May sign out from the START time.',
    'Extended Break'      => 'The break that runs at the START time may last until the END time.',
    'External Assignment' => 'Working outside for the hours given: not lost time.',
];

/** Notification types: kind => [label, who, default channels app/email/sms]. SMS only for high-risk ones. */
const TT_NOTIFY_KINDS = [
    'pre_report'         => ['Before reporting time', 'Employee', [1, 0, 0]],
    'report'             => ['Reporting time reached', 'Employee', [1, 0, 0]],
    'not_reported'       => ['Not reported after grace period', 'Employee', [1, 1, 0]],
    'not_reported_sup'   => ['Not reported (to supervisor)', 'Supervisor', [1, 1, 1]],
    'late_arrival'       => ['Late arrival recorded', 'Employee', [1, 1, 0]],
    'late_arrival_sup'   => ['Late arrival (to supervisor)', 'Supervisor', [1, 0, 0]],
    'break_pre'          => ['Before break starts (10 min)', 'Employee', [1, 0, 0]],
    'break_start'        => ['Break start time', 'Employee', [1, 0, 0]],
    'break_end_pre'      => ['Before break ends (15 min)', 'Employee', [1, 1, 0]],
    'break_end'          => ['Break end time', 'Employee', [1, 0, 0]],
    'return_overdue'     => ['Return time exceeded', 'Employee', [1, 1, 1]],
    'return_overdue_sup' => ['Return time exceeded (to supervisor)', 'Supervisor', [1, 1, 1]],
    'late_return'        => ['Late return recorded', 'Employee', [1, 1, 0]],
    'late_return_sup'    => ['Late return (to supervisor)', 'Supervisor', [1, 0, 0]],
    'post_go'            => ['Time to proceed to assigned post', 'Employee', [1, 0, 0]],
    'post_return_pre'    => ['Before post return time', 'Employee', [1, 0, 0]],
    'post_overdue'       => ['Post return exceeded', 'Employee', [1, 1, 1]],
    'post_overdue_sup'   => ['Post return exceeded (to supervisor)', 'Supervisor', [1, 1, 1]],
    'pre_departure'      => ['Before departure', 'Employee', [1, 0, 0]],
    'departure'          => ['Official departure time', 'Employee', [1, 0, 0]],
    'next_day'           => ['Successful sign-out: next working day', 'Employee', [1, 1, 0]],
    'decision'           => ['Decisions (corrections, overtime, extensions)', 'Employee', [1, 1, 0]],
    'request_sup'        => ['New request to decide (to supervisor)', 'Supervisor', [1, 0, 0]],
];

const TT_EVENT_LABELS = [
    'check_in' => 'Check-in', 'break_start' => 'Break start', 'break_end' => 'Return from break', 'post_leave' => 'Left for assigned post',
    'post_arrive' => 'Arrived at post', 'post_depart' => 'Left the post', 'post_return' => 'Returned from post', 'sign_out' => 'Sign-out',
];

function tt_e($v): string { return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8'); }

/* ------------------------------------------------------------------ storage */

const TT_SCHEMA_VERSION = '1';

function tt_ensure(mysqli $conn): void
{
    static $done = false;
    if ($done) { return; }
    $done = true;
    // Already installed (this version)? Then nothing to do: this runs on every page and every clock tick.
    try {
        $r = mysqli_query($conn, "SELECT v FROM tt_settings WHERE k = 'schema_version'");
        $row = $r ? mysqli_fetch_row($r) : null;
        if ($row && $row[0] === TT_SCHEMA_VERSION && tt_setting_raw($conn, 'holidays_seeded_' . date('Y'))) { return; }
    } catch (Throwable $e) { /* first run: the tables do not exist yet */ }
    $q = function (string $sql) use ($conn) { mysqli_query($conn, $sql); };
    $q("CREATE TABLE IF NOT EXISTS tt_schedules (
        id INT(11) NOT NULL AUTO_INCREMENT, user_id INT(11) NOT NULL, kind VARCHAR(10) NOT NULL DEFAULT 'permanent',
        title VARCHAR(120) NULL, valid_from DATE NOT NULL, valid_to DATE NULL, working_days VARCHAR(20) NOT NULL DEFAULT '1,2,3,4,5',
        report_time TIME NOT NULL, arrival_time TIME NULL, departure_time TIME NOT NULL,
        grace_arrival INT(11) NULL, grace_return INT(11) NULL,
        post_name VARCHAR(160) NULL, post_leave_main TIME NULL, post_arrive TIME NULL, post_leave TIME NULL, post_return_main TIME NULL,
        note VARCHAR(500) NULL, status VARCHAR(10) NOT NULL DEFAULT 'active', replaced_by INT(11) NULL,
        created_by INT(11) NULL, created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id), KEY user_dates (user_id, valid_from)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $q("CREATE TABLE IF NOT EXISTS tt_schedule_breaks (
        id INT(11) NOT NULL AUTO_INCREMENT, schedule_id INT(11) NOT NULL, label VARCHAR(60) NOT NULL, break_type VARCHAR(10) NOT NULL DEFAULT 'break',
        start_time TIME NOT NULL, end_time TIME NOT NULL, PRIMARY KEY (id), KEY schedule_id (schedule_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $q("CREATE TABLE IF NOT EXISTS tt_events (
        id INT(11) NOT NULL AUTO_INCREMENT, user_id INT(11) NOT NULL, work_date DATE NOT NULL, event_type VARCHAR(20) NOT NULL,
        ref_id INT(11) NOT NULL DEFAULT 0, scheduled_at DATETIME NULL, actual_at DATETIME NOT NULL, diff_minutes INT(11) NULL,
        source VARCHAR(12) NOT NULL DEFAULT 'employee', correction_id INT(11) NULL, ip VARCHAR(45) NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id), UNIQUE KEY one_event (user_id, work_date, event_type, ref_id), KEY work_date (work_date)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $q("CREATE TABLE IF NOT EXISTS tt_corrections (
        id INT(11) NOT NULL AUTO_INCREMENT, user_id INT(11) NOT NULL, work_date DATE NOT NULL, event_type VARCHAR(20) NOT NULL, ref_id INT(11) NOT NULL DEFAULT 0,
        requested_time DATETIME NOT NULL, reason TEXT NOT NULL, status VARCHAR(10) NOT NULL DEFAULT 'Pending',
        requested_by INT(11) NULL, decided_by INT(11) NULL, decided_at DATETIME NULL, decision_note VARCHAR(500) NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP, PRIMARY KEY (id), KEY user_id (user_id), KEY status (status)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $q("CREATE TABLE IF NOT EXISTS tt_extensions (
        id INT(11) NOT NULL AUTO_INCREMENT, user_id INT(11) NOT NULL, work_date DATE NOT NULL, kind VARCHAR(6) NOT NULL, ref_id INT(11) NOT NULL DEFAULT 0,
        new_end DATETIME NOT NULL, reason VARCHAR(500) NOT NULL, status VARCHAR(10) NOT NULL DEFAULT 'Pending',
        requested_by INT(11) NULL, decided_by INT(11) NULL, decided_at DATETIME NULL, decision_note VARCHAR(500) NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP, PRIMARY KEY (id), KEY user_date (user_id, work_date)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $q("CREATE TABLE IF NOT EXISTS tt_overtime (
        id INT(11) NOT NULL AUTO_INCREMENT, user_id INT(11) NOT NULL, work_date DATE NOT NULL, minutes INT(11) NOT NULL,
        approved_minutes INT(11) NOT NULL DEFAULT 0, status VARCHAR(10) NOT NULL DEFAULT 'Pending',
        decided_by INT(11) NULL, decided_at DATETIME NULL, decision_note VARCHAR(500) NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP, PRIMARY KEY (id), UNIQUE KEY one_day (user_id, work_date)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $q("CREATE TABLE IF NOT EXISTS tt_notification_log (
        id INT(11) NOT NULL AUTO_INCREMENT, subject_user_id INT(11) NOT NULL, recipient_id INT(11) NOT NULL, work_date DATE NOT NULL,
        kind VARCHAR(30) NOT NULL, ref_id INT(11) NOT NULL DEFAULT 0, channel VARCHAR(6) NOT NULL, status VARCHAR(10) NOT NULL DEFAULT 'pending',
        title VARCHAR(160) NULL, detail VARCHAR(500) NULL, created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id), UNIQUE KEY once (subject_user_id, recipient_id, work_date, kind, ref_id, channel), KEY work_date (work_date)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $q("CREATE TABLE IF NOT EXISTS tt_holidays (
        id INT(11) NOT NULL AUTO_INCREMENT, holiday_date DATE NOT NULL, name VARCHAR(120) NOT NULL, created_by INT(11) NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP, PRIMARY KEY (id), UNIQUE KEY holiday_date (holiday_date)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $q("CREATE TABLE IF NOT EXISTS tt_settings (k VARCHAR(60) NOT NULL, v TEXT NULL, PRIMARY KEY (k)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $q("CREATE TABLE IF NOT EXISTS tt_audit (
        id INT(11) NOT NULL AUTO_INCREMENT, actor_id INT(11) NULL, user_id INT(11) NULL, entity VARCHAR(20) NOT NULL, entity_id INT(11) NULL,
        action VARCHAR(40) NOT NULL, details TEXT NOT NULL, created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id), KEY user_id (user_id), KEY entity (entity, entity_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    // Leave Requests: the timed exception types (a category each, managed under Categories like the others).
    try {
        require_once __DIR__ . '/category_helpers.php';
        category_ensure($conn);
        $ins = mysqli_prepare($conn, "INSERT IGNORE INTO rm_categories (group_key, name) VALUES ('leave_type', ?)");
        foreach (array_keys(TT_TIMED_LEAVE) as $name) { mysqli_stmt_bind_param($ins, 's', $name); mysqli_stmt_execute($ins); }
        foreach (['start_time', 'end_time'] as $tc) {
            if (!category_column_info($conn, 'leave_requests', $tc)) { mysqli_query($conn, "ALTER TABLE leave_requests ADD COLUMN `$tc` TIME NULL"); }
        }
    } catch (Throwable $e) { error_log('tt_ensure leave types: ' . $e->getMessage()); }

    // Rwanda's fixed-date public holidays for this year and next (movable ones, e.g. Eid, Good Friday, are added by the Admin).
    if (!tt_setting_raw($conn, 'holidays_seeded_' . date('Y'))) {
        $fixed = ['01-01' => "New Year's Day", '01-02' => 'Day after New Year', '02-01' => "National Heroes' Day",
                  '04-07' => 'Genocide against the Tutsi Memorial Day', '05-01' => 'Labour Day', '07-01' => 'Independence Day',
                  '07-04' => 'Liberation Day', '08-15' => 'Assumption Day', '12-25' => 'Christmas Day', '12-26' => 'Boxing Day'];
        $h = mysqli_prepare($conn, 'INSERT IGNORE INTO tt_holidays (holiday_date, name) VALUES (?, ?)');
        foreach ([(int) date('Y'), (int) date('Y') + 1] as $y) {
            foreach ($fixed as $md => $name) { $d = $y . '-' . $md; mysqli_stmt_bind_param($h, 'ss', $d, $name); mysqli_stmt_execute($h); }
            $umuganura = date('Y-m-d', strtotime("first friday of august $y"));
            $n = 'Umuganura Day'; mysqli_stmt_bind_param($h, 'ss', $umuganura, $n); mysqli_stmt_execute($h);
        }
        tt_setting_set($conn, 'holidays_seeded_' . date('Y'), '1');
    }
    tt_setting_set($conn, 'schema_version', TT_SCHEMA_VERSION);
}

function tt_setting_raw(mysqli $conn, string $k): ?string
{
    $s = mysqli_prepare($conn, 'SELECT v FROM tt_settings WHERE k = ?');
    mysqli_stmt_bind_param($s, 's', $k);
    mysqli_stmt_execute($s);
    $r = mysqli_fetch_row(mysqli_stmt_get_result($s));
    return $r ? $r[0] : null;
}

function tt_setting_set(mysqli $conn, string $k, ?string $v): void
{
    $s = mysqli_prepare($conn, 'INSERT INTO tt_settings (k, v) VALUES (?, ?) ON DUPLICATE KEY UPDATE v = VALUES(v)');
    mysqli_stmt_bind_param($s, 'ss', $k, $v);
    mysqli_stmt_execute($s);
}

/** Numbers (TT_DEFAULTS keys) + 'channels' (kind => [app, email, sms]). */
function tt_settings(mysqli $conn, bool $fresh = false): array
{
    static $cache = null;
    if ($cache !== null && !$fresh) { return $cache; }
    tt_ensure($conn);
    $out = TT_DEFAULTS;
    foreach (mysqli_fetch_all(mysqli_query($conn, 'SELECT k, v FROM tt_settings'), MYSQLI_ASSOC) as $r) {
        if (array_key_exists($r['k'], TT_DEFAULTS)) { $out[$r['k']] = (int) $r['v']; }
    }
    $ch = json_decode((string) tt_setting_raw($conn, 'channels'), true) ?: [];
    $out['channels'] = [];
    foreach (TT_NOTIFY_KINDS as $kind => [$label, $who, $def]) {
        $c = $ch[$kind] ?? $def;
        $out['channels'][$kind] = [(int) ($c[0] ?? 0), (int) ($c[1] ?? 0), (int) ($c[2] ?? 0)];
    }
    return $cache = $out;
}

function tt_audit(mysqli $conn, ?int $actor, ?int $userId, string $entity, ?int $entityId, string $action, string $details): void
{
    $s = mysqli_prepare($conn, 'INSERT INTO tt_audit (actor_id, user_id, entity, entity_id, action, details) VALUES (?, ?, ?, ?, ?, ?)');
    mysqli_stmt_bind_param($s, 'iisiss', $actor, $userId, $entity, $entityId, $action, $details);
    mysqli_stmt_execute($s);
}

/* ------------------------------------------------------------------ small helpers */

function tt_csrf(): string
{
    if (empty($_SESSION['tt_csrf'])) { $_SESSION['tt_csrf'] = bin2hex(random_bytes(16)); }
    return $_SESSION['tt_csrf'];
}

function tt_csrf_ok(): bool
{
    return !empty($_POST['csrf']) && !empty($_SESSION['tt_csrf']) && hash_equals($_SESSION['tt_csrf'], (string) $_POST['csrf']);
}

function tt_csrf_field(): string { return '<input type="hidden" name="csrf" value="' . tt_e(tt_csrf()) . '">'; }

function tt_is_supervisor(): bool { return in_array(current_user_role(), ['Admin', 'Manager'], true); }

function tt_valid_date(?string $d): bool
{
    $x = DateTime::createFromFormat('Y-m-d', (string) $d);
    return $x && $x->format('Y-m-d') === $d;
}

function tt_valid_time(?string $t): ?string
{
    $t = substr(trim((string) $t), 0, 5);
    return preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $t) ? $t . ':00' : null;
}

function tt_user(mysqli $conn, int $id): ?array
{
    $s = mysqli_prepare($conn, 'SELECT u.id, u.names, u.email, u.phone, u.role, u.department_id, u.is_active, d.name AS department
        FROM users u LEFT JOIN departments d ON d.id = u.department_id WHERE u.id = ?');
    mysqli_stmt_bind_param($s, 'i', $id);
    mysqli_stmt_execute($s);
    return mysqli_fetch_assoc(mysqli_stmt_get_result($s)) ?: null;
}

/** Active employees, optionally one department: [id => row]. */
function tt_employees(mysqli $conn, ?int $departmentId = null): array
{
    $sql = 'SELECT u.id, u.names, u.email, u.phone, u.role, u.department_id, d.name AS department FROM users u
            LEFT JOIN departments d ON d.id = u.department_id WHERE u.is_active = 1' . ($departmentId ? ' AND u.department_id = ' . (int) $departmentId : '') . ' ORDER BY u.names';
    $out = [];
    foreach (mysqli_fetch_all(mysqli_query($conn, $sql), MYSQLI_ASSOC) as $r) { $out[(int) $r['id']] = $r; }
    return $out;
}

/** The people told about an employee: active Managers of the same department, else every active Admin. */
function tt_supervisors(mysqli $conn, int $userId): array
{
    $u = tt_user($conn, $userId);
    $ids = [];
    if ($u && $u['department_id']) {
        $s = mysqli_prepare($conn, "SELECT id FROM users WHERE role = 'Manager' AND is_active = 1 AND department_id = ? AND id <> ?");
        $dep = (int) $u['department_id'];
        mysqli_stmt_bind_param($s, 'ii', $dep, $userId);
        mysqli_stmt_execute($s);
        $ids = array_map('intval', array_column(mysqli_fetch_all(mysqli_stmt_get_result($s), MYSQLI_ASSOC), 'id'));
    }
    if (!$ids) {
        $ids = array_map('intval', array_column(mysqli_fetch_all(mysqli_query($conn, "SELECT id FROM users WHERE role = 'Admin' AND is_active = 1 AND id <> " . $userId), MYSQLI_ASSOC), 'id'));
    }
    return $ids;
}

/** Managers see their department; Admins everyone. Returns null = everyone, or a list of user ids. */
function tt_visible_user_ids(mysqli $conn): ?array
{
    if (current_user_role() === 'Admin') { return null; }
    $me = (int) current_user_id();
    $dep = (int) (mysqli_fetch_row(mysqli_query($conn, 'SELECT department_id FROM users WHERE id = ' . $me))[0] ?? 0);
    if (!$dep) { return [$me]; }
    return array_map('intval', array_column(mysqli_fetch_all(mysqli_query($conn, 'SELECT id FROM users WHERE department_id = ' . $dep), MYSQLI_ASSOC), 'id'));
}

function tt_can_see_user(mysqli $conn, int $userId): bool
{
    if ($userId === (int) current_user_id()) { return true; }
    if (!tt_is_supervisor()) { return false; }
    $v = tt_visible_user_ids($conn);
    return $v === null || in_array($userId, $v, true);
}

/* ------------------------------------------------------------------ loading days */

/**
 * Everything needed to calculate days from $from to $to: [userId][date] => ['plan', 'events', 'ex', 'att'].
 * $userIds null = every active employee. Loaded in a few queries, whatever the number of days.
 */
function tt_contexts(mysqli $conn, string $from, string $to, ?array $userIds = null): array
{
    tt_ensure($conn);
    $set = tt_settings($conn);
    if ($userIds === null) { $userIds = array_keys(tt_employees($conn)); }
    $userIds = array_values(array_unique(array_map('intval', $userIds)));
    if (!$userIds) { return []; }
    $in = implode(',', $userIds);
    $f = mysqli_real_escape_string($conn, $from); $t = mysqli_real_escape_string($conn, $to);

    $schedules = []; $breaks = [];
    foreach (mysqli_fetch_all(mysqli_query($conn, "SELECT * FROM tt_schedules WHERE user_id IN ($in) AND status <> 'cancelled'
            AND valid_from <= '$t' AND (valid_to IS NULL OR valid_to >= DATE_SUB('$f', INTERVAL 1 DAY))"), MYSQLI_ASSOC) as $r) {
        $schedules[(int) $r['user_id']][] = $r;
    }
    $sids = [];
    foreach ($schedules as $rows) { foreach ($rows as $r) { $sids[] = (int) $r['id']; } }
    if ($sids) {
        foreach (mysqli_fetch_all(mysqli_query($conn, 'SELECT * FROM tt_schedule_breaks WHERE schedule_id IN (' . implode(',', $sids) . ') ORDER BY start_time'), MYSQLI_ASSOC) as $b) {
            $breaks[(int) $b['schedule_id']][] = $b;
        }
    }
    $holidays = tt_holidays($conn, $from, $to);

    $leaves = [];
    foreach (mysqli_fetch_all(mysqli_query($conn, "SELECT * FROM leave_requests WHERE user_id IN ($in) AND status = 'Approved'
            AND start_date <= '$t' AND end_date >= '$f'"), MYSQLI_ASSOC) as $l) { $leaves[(int) $l['user_id']][] = $l; }

    $ext = [];
    foreach (mysqli_fetch_all(mysqli_query($conn, "SELECT * FROM tt_extensions WHERE user_id IN ($in) AND status = 'Approved'
            AND work_date BETWEEN '$f' AND '$t'"), MYSQLI_ASSOC) as $x) { $ext[(int) $x['user_id']][$x['work_date']][] = $x; }

    $events = [];
    foreach (mysqli_fetch_all(mysqli_query($conn, "SELECT user_id, work_date, event_type, ref_id, actual_at, source FROM tt_events
            WHERE user_id IN ($in) AND work_date BETWEEN '$f' AND '$t'"), MYSQLI_ASSOC) as $e) {
        $u = (int) $e['user_id']; $d = $e['work_date']; $ts = strtotime($e['actual_at']);
        $events[$u][$d] = $events[$u][$d] ?? ['check_in' => null, 'sign_out' => null, 'breaks' => [], 'post' => [], 'corrected' => []];
        $ref = (int) $e['ref_id'];
        switch ($e['event_type']) {
            case 'check_in': $events[$u][$d]['check_in'] = $ts; break;
            case 'sign_out': $events[$u][$d]['sign_out'] = $ts; break;
            case 'break_start': $events[$u][$d]['breaks'][$ref]['start'] = $ts; $events[$u][$d]['breaks'][$ref]['end'] = $events[$u][$d]['breaks'][$ref]['end'] ?? null; break;
            case 'break_end': $events[$u][$d]['breaks'][$ref]['end'] = $ts; break;
            case 'post_leave': $events[$u][$d]['post']['leave_main'] = $ts; break;
            case 'post_arrive': $events[$u][$d]['post']['arrive_post'] = $ts; break;
            case 'post_depart': $events[$u][$d]['post']['leave_post'] = $ts; break;
            case 'post_return': $events[$u][$d]['post']['return_main'] = $ts; break;
        }
        if ($e['source'] !== 'employee') { $events[$u][$d]['corrected'][] = $e['event_type']; }
    }

    $out = [];
    $days = [];
    for ($d = $from; $d <= $to; $d = date('Y-m-d', strtotime($d . ' +1 day'))) { $days[] = $d; }
    foreach ($userIds as $u) {
        foreach ($days as $d) {
            $sch = tt_engine_pick_schedule($schedules[$u] ?? [], $d);
            $dayType = 'work'; $label = '';
            $dayLeaves = array_filter($leaves[$u] ?? [], function ($l) use ($d) { return $l['start_date'] <= $d && $l['end_date'] >= $d; });
            foreach ($dayLeaves as $l) {
                if (!isset(TT_TIMED_LEAVE[$l['leave_type']]) || empty($l['start_time'])) { $dayType = 'leave'; $label = $l['leave_type'] . ' leave (approved)'; }
            }
            if (isset($holidays[$d])) { $dayType = 'holiday'; $label = $holidays[$d]; }
            $plan = tt_engine_plan($sch, $sch ? ($breaks[(int) $sch['id']] ?? []) : [], $d, $dayType, $label, $set);
            $out[$u][$d] = [
                'plan' => $plan,
                'events' => $events[$u][$d] ?? ['check_in' => null, 'sign_out' => null, 'breaks' => [], 'post' => [], 'corrected' => []],
                'ex' => $plan ? tt_exceptions($plan, $dayLeaves, $ext[$u][$d] ?? []) : tt_norm_exceptions([]),
            ];
        }
    }
    return $out;
}

/** Approved timed leave + supervisor extensions -> the EXCEPTIONS array of the engine. */
function tt_exceptions(array $plan, array $dayLeaves, array $extensions): array
{
    $ex = tt_norm_exceptions([]);
    $d = $plan['date'];
    foreach ($dayLeaves as $l) {
        $type = $l['leave_type'];
        if (!isset(TT_TIMED_LEAVE[$type]) || empty($l['start_time'])) { continue; }
        $s = tt_at($d, $l['start_time']); $e = tt_at($d, $l['end_time'] ?: $l['start_time']);
        if ($plan['report'] && $s < $plan['report'] - 6 * 3600) { $s += 86400; $e += 86400; } // night shift
        $ex['labels'][] = $type . ' ' . substr($l['start_time'], 0, 5) . ($l['end_time'] ? '–' . substr($l['end_time'], 0, 5) : '') . ' (approved)';
        switch ($type) {
            case 'Late Arrival': $ex['late_until'] = max((int) $ex['late_until'], $e); break;
            case 'Early Departure': $ex['early_from'] = $ex['early_from'] ? min($ex['early_from'], $s) : $s; break;
            case 'Extended Break':
                foreach ($plan['breaks'] as $b) {
                    if ($s >= $b['start'] - 3600 && $s <= $b['end']) { $ex['break_ext'][$b['id']] = max((int) ($ex['break_ext'][$b['id']] ?? 0), $e); }
                }
                break;
            default: if ($e > $s) { $ex['windows'][] = [$s, $e, $type]; }
        }
    }
    foreach ($extensions as $x) {
        $ts = strtotime($x['new_end']);
        if ($x['kind'] === 'break') { $ex['break_ext'][(int) $x['ref_id']] = max((int) ($ex['break_ext'][(int) $x['ref_id']] ?? 0), $ts); }
        else { $ex['post_ext'] = max((int) $ex['post_ext'], $ts); }
        $ex['labels'][] = ($x['kind'] === 'break' ? 'Break' : 'Post return') . ' extended to ' . date('H:i', $ts) . ' (approved)';
    }
    return $ex;
}

function tt_holidays(mysqli $conn, string $from, string $to): array
{
    $s = mysqli_prepare($conn, 'SELECT holiday_date, name FROM tt_holidays WHERE holiday_date BETWEEN ? AND ?');
    mysqli_stmt_bind_param($s, 'ss', $from, $to);
    mysqli_stmt_execute($s);
    $out = [];
    foreach (mysqli_fetch_all(mysqli_stmt_get_result($s), MYSQLI_ASSOC) as $r) { $out[$r['holiday_date']] = $r['name']; }
    return $out;
}

function tt_context(mysqli $conn, int $userId, string $date): array
{
    return tt_contexts($conn, $date, $date, [$userId])[$userId][$date];
}

/**
 * The day an action belongs to: today, unless yesterday's night shift is still open
 * (checked in, not signed out, departure less than 6 hours ago or still ahead).
 */
function tt_work_date(mysqli $conn, int $userId, int $now): string
{
    $today = date('Y-m-d', $now);
    $y = date('Y-m-d', $now - 86400);
    $c = tt_context($conn, $userId, $y);
    if ($c['plan'] && $c['events']['check_in'] && !$c['events']['sign_out'] && $c['plan']['departure'] > strtotime($today) && $now < $c['plan']['departure'] + 6 * 3600) {
        return $y;
    }
    return $today;
}

function tt_next_working_plan(mysqli $conn, int $userId, string $fromDate): ?array
{
    $to = date('Y-m-d', strtotime($fromDate . ' +60 days'));
    $ctx = tt_contexts($conn, date('Y-m-d', strtotime($fromDate . ' +1 day')), $to, [$userId])[$userId] ?? [];
    return tt_engine_next_working_day(function ($d) use ($ctx) { return $ctx[$d]['plan'] ?? null; }, $fromDate);
}

/* ------------------------------------------------------------------ recording actions */

/**
 * The employee presses a button. Everything is checked again here with the SERVER time; the browser only asks.
 * Returns ['ok' => bool, 'message' => string, 'next_day' => ?string].
 */
function tt_record_action(mysqli $conn, int $userId, string $action, int $ref = 0): array
{
    $map = ['check_in' => 'check_in', 'break_start' => 'break_start', 'break_end' => 'break_end', 'post_leave' => 'post_leave',
            'post_arrive' => 'post_arrive', 'post_depart' => 'post_depart', 'post_return' => 'post_return', 'sign_out' => 'sign_out'];
    if (!isset($map[$action])) { return ['ok' => false, 'message' => 'Unknown action.']; }
    $lock = 'tt_user_' . $userId;
    mysqli_query($conn, "SELECT GET_LOCK('$lock', 5)");
    try {
        $now = time();
        $date = tt_work_date($conn, $userId, $now);
        $ctx = tt_context($conn, $userId, $date);
        $set = tt_settings($conn);
        if ($action === 'break_end' && !$ref) { $ob = $ctx['plan'] ? tt_open_break($ctx['plan'], tt_norm_events($ctx['events'])) : null; $ref = $ob ? $ob['id'] : 0; }
        [$ok, $why] = tt_engine_can($ctx['plan'], $ctx['events'], $ctx['ex'], $now, $action, $ref, $set);
        if (!$ok) {
            tt_audit($conn, $userId, $userId, 'event', null, 'refused', TT_EVENT_LABELS[$action] . ' refused at ' . date('H:i:s', $now) . ': ' . $why);
            return ['ok' => false, 'message' => $why];
        }
        $plan = $ctx['plan'];
        $scheduled = tt_scheduled_for($plan, $ctx['ex'], $action, $ref);
        $refId = in_array($action, ['break_start', 'break_end'], true) ? $ref : 0;
        $actual = date('Y-m-d H:i:s', $now);
        $sched = $scheduled ? date('Y-m-d H:i:s', $scheduled) : null;
        $diff = $scheduled ? (int) round(($now - $scheduled) / 60) : null;
        $ip = substr((string) ($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45);
        $s = mysqli_prepare($conn, "INSERT IGNORE INTO tt_events (user_id, work_date, event_type, ref_id, scheduled_at, actual_at, diff_minutes, source, ip)
            VALUES (?, ?, ?, ?, ?, ?, ?, 'employee', ?)");
        mysqli_stmt_bind_param($s, 'ississis', $userId, $date, $action, $refId, $sched, $actual, $diff, $ip);
        mysqli_stmt_execute($s);
        if (mysqli_stmt_affected_rows($s) !== 1) { return ['ok' => false, 'message' => 'This was already recorded. Nothing was recorded twice.']; }
        $eventId = (int) mysqli_insert_id($conn);

        // Re-read the day with the new event, then react.
        $ctx = tt_context($conn, $userId, $date);
        $calc = tt_engine_compute($ctx['plan'], $ctx['events'], $ctx['ex'], $now, $set);
        $u = tt_user($conn, $userId);
        $name = $u['names'] ?? 'Employee';
        $msg = TT_EVENT_LABELS[$action] . ' recorded at ' . date('g:i:s A', $now) . ' (official MY MOTIVE time).';
        $nextDay = null;

        if ($action === 'check_in') {
            tt_attendance_check_in($conn, $userId, $date, $now, $calc);
            if ($calc['late_arrival_minutes'] > 0) {
                $msg .= ' You are ' . tt_dur($calc['late_arrival_minutes']) . ' late; this is recorded as lost working time.';
                tt_deliver($conn, 'late_arrival', 0, $date, $userId, $userId, 'Late arrival recorded',
                    'Your reporting time was ' . tt_hm12(tt_allowed_arrival($plan, $ctx['ex'])) . ' and your check-in was recorded at ' . date('g:i A', $now) . '. You were ' . tt_dur($calc['late_arrival_minutes']) . ' late. This time has been recorded for attendance and working-time evaluation.');
                foreach (tt_supervisors($conn, $userId) as $sup) {
                    tt_deliver($conn, 'late_arrival_sup', 0, $date, $userId, $sup, 'Late arrival: ' . $name, $name . ' checked in at ' . date('g:i A', $now) . ', ' . tt_dur($calc['late_arrival_minutes']) . ' late.');
                }
            }
        } elseif ($action === 'break_end') {
            $b = null; foreach ($plan['breaks'] as $x) { if ($x['id'] === $ref) { $b = $x; } }
            $due = $b ? tt_break_due($b, $ctx['ex']) : $now;
            $late = tt_min($now - $due);
            if ($b && $now > $due + 60 * (int) $plan['grace_return']) {
                $msg = 'Return recorded at ' . date('g:i A', $now) . '. Your break ended at ' . tt_hm12($due) . ': ' . tt_dur($late) . ' over the authorized break.';
                tt_deliver($conn, 'late_return', $ref, $date, $userId, $userId, 'Late return from ' . strtolower($b['label']),
                    'Your scheduled break ended at ' . tt_hm12($due) . ', but your return was recorded at ' . date('g:i A', $now) . '. You exceeded your authorized break duration by ' . $late . ' minutes. This time has been recorded for attendance and working-time evaluation.');
                foreach (tt_supervisors($conn, $userId) as $sup) {
                    tt_deliver($conn, 'late_return_sup', $ref, $date, $userId, $sup, 'Late return: ' . $name, $name . ' returned from ' . strtolower($b['label']) . ' at ' . date('g:i A', $now) . ' (due ' . tt_hm12($due) . ', ' . $late . ' min late).');
                }
            } else {
                $msg = 'Welcome back. Return recorded at ' . date('g:i A', $now) . ' — on time.';
            }
        } elseif ($action === 'break_start') {
            $b = null; foreach ($plan['breaks'] as $x) { if ($x['id'] === $ref) { $b = $x; } }
            $msg = ($b ? $b['label'] : 'Break') . ' started at ' . date('g:i A', $now) . '. Return to work by ' . tt_hm12($b ? tt_break_due($b, $ctx['ex']) : $now) . '.';
        } elseif ($action === 'sign_out') {
            tt_attendance_sign_out($conn, $userId, $date, $now, $calc);
            if ($calc['overtime_minutes'] >= (int) $set['overtime_min']) {
                $o = mysqli_prepare($conn, "INSERT INTO tt_overtime (user_id, work_date, minutes) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE minutes = IF(status = 'Pending', VALUES(minutes), minutes)");
                mysqli_stmt_bind_param($o, 'isi', $userId, $date, $calc['overtime_minutes']);
                mysqli_stmt_execute($o);
                foreach (tt_supervisors($conn, $userId) as $sup) {
                    tt_deliver($conn, 'request_sup', 1, $date, $userId, $sup, 'Overtime to approve: ' . $name, $name . ' worked ' . tt_dur($calc['overtime_minutes']) . ' after the official departure time on ' . date('d M Y', strtotime($date)) . '.');
                }
            }
            $next = tt_next_working_plan($conn, $userId, $date);
            $nextDay = tt_engine_next_day_text($next);
            $msg = 'Your sign-out has been recorded successfully.';
            tt_deliver($conn, 'next_day', 0, $date, $userId, $userId, 'Your next working day', $msg . "\n\n" . $nextDay);
        }
        tt_audit($conn, $userId, $userId, 'event', $eventId, $action, TT_EVENT_LABELS[$action] . ' at ' . $actual . ($sched ? ' (scheduled ' . $sched . ')' : ''));
        return ['ok' => true, 'message' => $msg, 'next_day' => $nextDay];
    } finally {
        mysqli_query($conn, "SELECT RELEASE_LOCK('$lock')");
    }
}

/** The scheduled time an action is compared with. */
function tt_scheduled_for(?array $plan, array $ex, string $action, int $ref): ?int
{
    if (!$plan || $plan['day_type'] !== 'work') { return null; }
    $b = null; foreach ($plan['breaks'] as $x) { if ($x['id'] === $ref) { $b = $x; } }
    switch ($action) {
        case 'check_in': return tt_allowed_arrival($plan, $ex);
        case 'break_start': return $b ? $b['start'] : null;
        case 'break_end': return $b ? tt_break_due($b, $ex) : null;
        case 'post_leave': return $plan['post']['leave_main'] ?? null;
        case 'post_arrive': return $plan['post']['arrive'] ?? null;
        case 'post_depart': return $plan['post']['leave_post'] ?? null;
        case 'post_return': return tt_post_due($plan, $ex);
        case 'sign_out': return tt_allowed_departure($plan, $ex);
    }
    return null;
}

/* ------------------------------------------------------------------ Attendance module (same records, no duplicates) */

function tt_attendance_row(mysqli $conn, int $userId, string $date): ?array
{
    $s = mysqli_prepare($conn, 'SELECT * FROM attendance WHERE user_id = ? AND attendance_date = ? ORDER BY id LIMIT 1');
    mysqli_stmt_bind_param($s, 'is', $userId, $date);
    mysqli_stmt_execute($s);
    return mysqli_fetch_assoc(mysqli_stmt_get_result($s)) ?: null;
}

function tt_attendance_check_in(mysqli $conn, int $userId, string $date, int $now, array $calc): void
{
    try {
        $late = (int) $calc['late_arrival_minutes'];
        $status = $late > 0 ? 'Late' : 'Present';
        $time = date('H:i:s', $now);
        if ($row = tt_attendance_row($conn, $userId, $date)) {   // already clocked in from the Attendance page: keep it, add the timetable figures
            $s = mysqli_prepare($conn, 'UPDATE attendance SET late_minutes = ?, status = ? WHERE id = ?');
            $id = (int) $row['id'];
            mysqli_stmt_bind_param($s, 'isi', $late, $status, $id);
        } else {
            $s = mysqli_prepare($conn, "INSERT INTO attendance (user_id, attendance_date, check_in, status, workflow_status, late_minutes) VALUES (?, ?, ?, ?, 'Pending Clock-In Approval', ?)");
            mysqli_stmt_bind_param($s, 'isssi', $userId, $date, $time, $status, $late);
        }
        mysqli_stmt_execute($s);
    } catch (Throwable $e) { error_log('tt attendance check-in: ' . $e->getMessage()); }
}

/** Sign-out: worked minutes and lateness from the timetable. Overtime stays 0 until a supervisor approves it. */
function tt_attendance_sign_out(mysqli $conn, int $userId, string $date, int $now, array $calc): void
{
    try {
        $row = tt_attendance_row($conn, $userId, $date);
        if (!$row) { return; }
        $time = date('H:i:s', $now);
        $worked = (int) $calc['worked_minutes']; $late = (int) $calc['late_arrival_minutes']; $id = (int) $row['id'];
        $wf = $row['workflow_status'] === 'Working' ? 'Pending Clock-Out Confirmation' : $row['workflow_status'];
        $s = mysqli_prepare($conn, 'UPDATE attendance SET check_out = ?, worked_minutes = ?, late_minutes = ?, overtime_minutes = 0, workflow_status = ? WHERE id = ?');
        mysqli_stmt_bind_param($s, 'siisi', $time, $worked, $late, $wf, $id);
        mysqli_stmt_execute($s);
    } catch (Throwable $e) { error_log('tt attendance sign-out: ' . $e->getMessage()); }
}

/** Late minutes for the Attendance approval page (null = the employee has no timetable that day). */
function tt_attendance_late_minutes(mysqli $conn, int $userId, string $date, string $checkIn): ?int
{
    try {
        $ctx = tt_context($conn, $userId, $date);
        if (!$ctx['plan'] || $ctx['plan']['day_type'] !== 'work') { return null; }
        $ev = $ctx['events']; $ev['check_in'] = $ev['check_in'] ?: strtotime($date . ' ' . $checkIn);
        return (int) tt_engine_compute($ctx['plan'], $ev, $ctx['ex'], time(), tt_settings($conn))['late_arrival_minutes'];
    } catch (Throwable $e) { return null; }
}

/* ------------------------------------------------------------------ notifications */

/**
 * Sends one notification through the channels configured for its kind, ONCE per channel (unique log row).
 * In-app = the existing notifications table (bell), email = the existing mailer, SMS = the existing Pindo sender.
 */
function tt_deliver(mysqli $conn, string $kind, int $ref, string $workDate, int $subjectId, int $recipientId, string $title, string $message): int
{
    $set = tt_settings($conn);
    [$app, $email, $sms] = $set['channels'][$kind] ?? [1, 0, 0];
    $sent = 0;
    $recipient = null;
    foreach (['app' => $app, 'email' => $email, 'sms' => $sms] as $channel => $on) {
        if (!$on) { continue; }
        $s = mysqli_prepare($conn, "INSERT IGNORE INTO tt_notification_log (subject_user_id, recipient_id, work_date, kind, ref_id, channel, title) VALUES (?, ?, ?, ?, ?, ?, ?)");
        $t = mb_substr($title, 0, 160);
        mysqli_stmt_bind_param($s, 'iississ', $subjectId, $recipientId, $workDate, $kind, $ref, $channel, $t);
        mysqli_stmt_execute($s);
        if (mysqli_stmt_affected_rows($s) !== 1) { continue; } // already sent: never twice
        $logId = (int) mysqli_insert_id($conn);
        $status = 'sent'; $detail = null;
        try {
            $recipient = $recipient ?? tt_user($conn, $recipientId);
            if ($channel === 'app') {
                $n = mysqli_prepare($conn, 'INSERT INTO notifications (user_id, title, message) VALUES (?, ?, ?)');
                mysqli_stmt_bind_param($n, 'iss', $recipientId, $title, $message);
                mysqli_stmt_execute($n);
            } elseif ($channel === 'email') {
                if (empty($recipient['email'])) { $status = 'skipped'; $detail = 'No email address'; }
                else {
                    $html = '<p>Hi ' . tt_e($recipient['names']) . ',</p><p>' . nl2br(tt_e($message)) . '</p><p style="color:#8A90A3;font-size:12px;">MY MOTIVE · Work Timetable · official time ' . date('d M Y, H:i') . '</p>';
                    if (!send_email($recipient['email'], $recipient['names'], $title, $html)) { $status = 'failed'; $detail = 'Mail server refused'; }
                }
            } elseif ($channel === 'sms') {
                require_once __DIR__ . '/sms_helpers.php';
                $ok = sms_send($conn, $recipient['phone'] ?? null, 'MY MOTIVE: ' . $message, 'TIMETABLE_' . strtoupper($kind), 'tt_notification', $logId, $recipientId);
                if (!$ok) { $status = 'failed'; $detail = 'See SMS log'; }
            }
        } catch (Throwable $e) {
            $status = 'failed'; $detail = mb_substr($e->getMessage(), 0, 500);
        }
        $u = mysqli_prepare($conn, 'UPDATE tt_notification_log SET status = ?, detail = ? WHERE id = ?');
        mysqli_stmt_bind_param($u, 'ssi', $status, $detail, $logId);
        mysqli_stmt_execute($u);
        if ($status === 'sent') { $sent++; }
    }
    return $sent;
}

/**
 * Sends every reminder that is due now, for every employee. Safe to call as often as you like:
 *  - at most once per 50 seconds (shared by all pages and the cron link),
 *  - and each reminder only once (tt_notification_log).
 * Returns the number of messages sent, or -1 when skipped (ran less than 50 seconds ago).
 */
function tt_tick(mysqli $conn, bool $force = false): int
{
    tt_ensure($conn);
    $now = time();
    if (!$force) {
        mysqli_query($conn, "INSERT IGNORE INTO tt_settings (k, v) VALUES ('last_tick', '0')");
        $limit = $now - 50;
        $s = mysqli_prepare($conn, "UPDATE tt_settings SET v = ? WHERE k = 'last_tick' AND CAST(v AS UNSIGNED) < ?");
        $nowS = (string) $now;
        mysqli_stmt_bind_param($s, 'si', $nowS, $limit);
        mysqli_stmt_execute($s);
        if (mysqli_stmt_affected_rows($s) !== 1) { return -1; }
    } else {
        tt_setting_set($conn, 'last_tick', (string) $now);
    }
    $set = tt_settings($conn);
    $emps = tt_employees($conn);
    $today = date('Y-m-d', $now); $yesterday = date('Y-m-d', $now - 86400);
    $ctx = tt_contexts($conn, $yesterday, $today, array_keys($emps));
    $sent = 0;
    foreach ($ctx as $uid => $days) {
        foreach ($days as $date => $c) {
            if (!$c['plan']) { continue; }
            if ($date === $yesterday && !($c['plan']['departure'] > strtotime($today))) { continue; } // only night shifts from yesterday
            foreach (tt_engine_due_reminders($c['plan'], $c['events'], $c['ex'], $now, $set, $emps[$uid]['names'] ?? '') as $r) {
                $to = $r['to'] === 'supervisor' ? tt_supervisors($conn, (int) $uid) : [(int) $uid];
                foreach ($to as $rid) { $sent += tt_deliver($conn, $r['kind'], (int) $r['ref'], $date, (int) $uid, $rid, $r['title'], $r['message']); }
            }
        }
    }
    return $sent;
}

/* ------------------------------------------------------------------ decisions (corrections, overtime, extensions) */

function tt_pending_counts(mysqli $conn): array
{
    tt_ensure($conn);
    $v = tt_visible_user_ids($conn);
    $me = (int) current_user_id();
    $w = ($v === null ? '1=1' : 'user_id IN (' . (implode(',', $v) ?: '0') . ')') . ' AND user_id <> ' . $me;
    $c = function ($t) use ($conn, $w) { return (int) mysqli_fetch_row(mysqli_query($conn, "SELECT COUNT(*) FROM $t WHERE status = 'Pending' AND $w"))[0]; };
    return ['corrections' => $c('tt_corrections'), 'overtime' => $c('tt_overtime'), 'extensions' => $c('tt_extensions')];
}

/** Applies an approved correction: inserts or replaces the event (source = correction), keeps the old value in the audit. */
function tt_apply_correction(mysqli $conn, array $c, int $actor): void
{
    $uid = (int) $c['user_id']; $date = $c['work_date']; $type = $c['event_type']; $ref = (int) $c['ref_id'];
    $s = mysqli_prepare($conn, 'SELECT * FROM tt_events WHERE user_id = ? AND work_date = ? AND event_type = ? AND ref_id = ?');
    mysqli_stmt_bind_param($s, 'issi', $uid, $date, $type, $ref);
    mysqli_stmt_execute($s);
    $old = mysqli_fetch_assoc(mysqli_stmt_get_result($s));
    $cid = (int) $c['id'];
    if ($old) {
        $u = mysqli_prepare($conn, "UPDATE tt_events SET actual_at = ?, source = 'correction', correction_id = ? WHERE id = ?");
        $oid = (int) $old['id'];
        mysqli_stmt_bind_param($u, 'sii', $c['requested_time'], $cid, $oid);
        mysqli_stmt_execute($u);
    } else {
        $i = mysqli_prepare($conn, "INSERT INTO tt_events (user_id, work_date, event_type, ref_id, actual_at, source, correction_id) VALUES (?, ?, ?, ?, ?, 'correction', ?)");
        mysqli_stmt_bind_param($i, 'issisi', $uid, $date, $type, $ref, $c['requested_time'], $cid);
        mysqli_stmt_execute($i);
    }
    tt_audit($conn, $actor, $uid, 'correction', $cid, 'applied', (TT_EVENT_LABELS[$type] ?? $type) . ' on ' . $date . ': '
        . ($old ? 'was ' . $old['actual_at'] . ' (' . $old['source'] . ')' : 'was not recorded') . ', now ' . $c['requested_time'] . '. Reason: ' . $c['reason']);
    // Attendance follows the corrected check-in / sign-out.
    try {
        $ctx = tt_context($conn, $uid, $date);
        $calc = tt_engine_compute($ctx['plan'], $ctx['events'], $ctx['ex'], time(), tt_settings($conn));
        $row = tt_attendance_row($conn, $uid, $date);
        if ($type === 'check_in' && !$row) { tt_attendance_check_in($conn, $uid, $date, strtotime($c['requested_time']), $calc); }
        elseif ($row && $type === 'check_in') {
            $t = date('H:i:s', strtotime($c['requested_time'])); $late = (int) $calc['late_arrival_minutes']; $id = (int) $row['id'];
            $st = $late > 0 ? 'Late' : 'Present';
            $q = mysqli_prepare($conn, "UPDATE attendance SET check_in = ?, late_minutes = ?, status = IF(status IN ('Present', 'Late'), ?, status) WHERE id = ?");
            mysqli_stmt_bind_param($q, 'sisi', $t, $late, $st, $id); mysqli_stmt_execute($q);
        } elseif ($type === 'sign_out') { tt_attendance_sign_out($conn, $uid, $date, strtotime($c['requested_time']), $calc); }
    } catch (Throwable $e) { error_log('tt correction attendance: ' . $e->getMessage()); }
}

/* ------------------------------------------------------------------ schedules */

function tt_schedule_rows(mysqli $conn, int $userId): array
{
    tt_ensure($conn);
    $rows = mysqli_fetch_all(mysqli_query($conn, 'SELECT s.*, u.names AS created_by_name FROM tt_schedules s LEFT JOIN users u ON u.id = s.created_by
        WHERE s.user_id = ' . $userId . ' ORDER BY s.valid_from DESC, s.id DESC'), MYSQLI_ASSOC);
    foreach ($rows as &$r) {
        $r['breaks'] = mysqli_fetch_all(mysqli_query($conn, 'SELECT * FROM tt_schedule_breaks WHERE schedule_id = ' . (int) $r['id'] . ' ORDER BY start_time'), MYSQLI_ASSOC);
    }
    return $rows;
}

function tt_schedule_text(array $s): string
{
    $days = ['1' => 'Mon', '2' => 'Tue', '3' => 'Wed', '4' => 'Thu', '5' => 'Fri', '6' => 'Sat', '7' => 'Sun'];
    $d = implode(', ', array_map(function ($x) use ($days) { return $days[$x] ?? $x; }, array_filter(explode(',', $s['working_days']), 'strlen')));
    $b = implode('; ', array_map(function ($x) { return $x['label'] . ' ' . substr($x['start_time'], 0, 5) . '–' . substr($x['end_time'], 0, 5); }, $s['breaks'] ?? []));
    return $d . ' · ' . substr($s['report_time'], 0, 5) . '–' . substr($s['departure_time'], 0, 5) . ($b ? ' · ' . $b : '')
        . (!empty($s['post_leave_main']) ? ' · post ' . ($s['post_name'] ?: '') . ' ' . substr($s['post_leave_main'], 0, 5) . '–' . substr((string) $s['post_return_main'], 0, 5) : '');
}

/**
 * Saves a timetable. $data: user_id, kind (permanent|temporary), valid_from, valid_to (temporary), working_days[],
 * report_time, arrival_time, departure_time, grace_arrival, grace_return, post_*, note, breaks[[label,type,start,end]].
 * A PERMANENT timetable replaces the current one from valid_from (today or later): the old version ends the day before
 * and keeps every past day exactly as it was. Returns [ok, message, id].
 */
function tt_save_schedule(mysqli $conn, array $data, int $actor): array
{
    tt_ensure($conn);
    $uid = (int) ($data['user_id'] ?? 0);
    if (!$uid || !tt_user($conn, $uid)) { return [false, 'Choose an employee.', 0]; }
    $kind = ($data['kind'] ?? '') === 'temporary' ? 'temporary' : 'permanent';
    $from = (string) ($data['valid_from'] ?? '');
    $to = $kind === 'temporary' ? (string) ($data['valid_to'] ?? '') : null;
    $today = date('Y-m-d');
    if (!tt_valid_date($from)) { return [false, 'Choose a valid start date.', 0]; }
    if ($from < $today) { return [false, 'A timetable can only start today or later. Past days keep the timetable they had (history is never rewritten).', 0]; }
    if ($kind === 'temporary' && (!tt_valid_date($to) || $to < $from)) { return [false, 'A temporary adjustment needs an end date on or after its start date.', 0]; }
    $days = array_values(array_intersect(array_map('strval', (array) ($data['working_days'] ?? [])), ['1', '2', '3', '4', '5', '6', '7']));
    if (!$days) { return [false, 'Choose at least one working day.', 0]; }
    sort($days);
    $report = tt_valid_time($data['report_time'] ?? ''); $dep = tt_valid_time($data['departure_time'] ?? '');
    if (!$report || !$dep || $report === $dep) { return [false, 'Enter a valid reporting time and departure time.', 0]; }
    $arrival = tt_valid_time($data['arrival_time'] ?? '');
    $ga = ($data['grace_arrival'] ?? '') === '' ? null : max(0, min(120, (int) $data['grace_arrival']));
    $gr = ($data['grace_return'] ?? '') === '' ? null : max(0, min(120, (int) $data['grace_return']));
    $postName = trim((string) ($data['post_name'] ?? ''));
    $pl = tt_valid_time($data['post_leave_main'] ?? ''); $pa = tt_valid_time($data['post_arrive'] ?? '');
    $pd = tt_valid_time($data['post_leave'] ?? ''); $pr = tt_valid_time($data['post_return_main'] ?? '');
    if ($postName === '' && ($pl || $pa)) { $postName = 'Assigned post'; }
    if ($postName !== '' && !$pl) { return [false, 'An assigned post needs the time to leave the main workplace.', 0]; }
    $breaks = [];
    foreach ((array) ($data['breaks'] ?? []) as $b) {
        $bs = tt_valid_time($b['start'] ?? ''); $be = tt_valid_time($b['end'] ?? '');
        if (!$bs && !$be) { continue; }
        if (!$bs || !$be || $bs === $be) { return [false, 'Every break needs a valid start and end time.', 0]; }
        $breaks[] = ['label' => mb_substr(trim((string) ($b['label'] ?? '')) ?: 'Break', 0, 60), 'type' => ($b['type'] ?? '') === 'lunch' ? 'lunch' : 'break', 'start' => $bs, 'end' => $be];
    }
    // Check with the engine: breaks inside the working hours, not overlapping.
    $probe = tt_engine_plan(['working_days' => '1,2,3,4,5,6,7', 'report_time' => $report, 'arrival_time' => $arrival, 'departure_time' => $dep,
        'grace_arrival' => 0, 'grace_return' => 0], array_map(function ($b, $i) { return ['id' => $i + 1, 'label' => $b['label'], 'start_time' => $b['start'], 'end_time' => $b['end']]; }, $breaks, array_keys($breaks)), $from);
    $prev = null;
    foreach ($probe['breaks'] as $b) {
        if ($b['start'] < $probe['report'] || $b['end'] > $probe['departure']) { return [false, '"' . $b['label'] . '" must be inside the working hours.', 0]; }
        if ($prev && $b['start'] < $prev['end']) { return [false, 'Breaks must not overlap ("' . $prev['label'] . '" and "' . $b['label'] . '").', 0]; }
        $prev = $b;
    }
    if (tt_engine_scheduled_minutes($probe) <= 0) { return [false, 'The breaks take the whole working day.', 0]; }
    $note = mb_substr(trim((string) ($data['note'] ?? '')), 0, 500);
    $title = mb_substr(trim((string) ($data['title'] ?? '')), 0, 120) ?: null;
    $wd = implode(',', $days);

    mysqli_begin_transaction($conn);
    try {
        $s = mysqli_prepare($conn, 'INSERT INTO tt_schedules (user_id, kind, title, valid_from, valid_to, working_days, report_time, arrival_time, departure_time,
            grace_arrival, grace_return, post_name, post_leave_main, post_arrive, post_leave, post_return_main, note, created_by)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
        $pn = $postName !== '' ? $postName : null;
        mysqli_stmt_bind_param($s, 'issssssssiissssssi', $uid, $kind, $title, $from, $to, $wd, $report, $arrival, $dep, $ga, $gr, $pn, $pl, $pa, $pd, $pr, $note, $actor);
        mysqli_stmt_execute($s);
        $id = (int) mysqli_insert_id($conn);
        $bi = mysqli_prepare($conn, 'INSERT INTO tt_schedule_breaks (schedule_id, label, break_type, start_time, end_time) VALUES (?, ?, ?, ?, ?)');
        foreach ($breaks as $b) { mysqli_stmt_bind_param($bi, 'issss', $id, $b['label'], $b['type'], $b['start'], $b['end']); mysqli_stmt_execute($bi); }

        $replaced = [];
        if ($kind === 'permanent') {
            $prevDay = date('Y-m-d', strtotime($from . ' -1 day'));
            foreach (mysqli_fetch_all(mysqli_query($conn, "SELECT id, valid_from FROM tt_schedules WHERE user_id = $uid AND kind = 'permanent' AND id <> $id
                    AND status <> 'cancelled' AND (valid_to IS NULL OR valid_to >= '" . mysqli_real_escape_string($conn, $from) . "')"), MYSQLI_ASSOC) as $old) {
                if ($old['valid_from'] >= $from) {   // never used yet: cancelled (kept for the record)
                    mysqli_query($conn, "UPDATE tt_schedules SET status = 'cancelled', replaced_by = $id WHERE id = " . (int) $old['id']);
                } else {
                    mysqli_query($conn, "UPDATE tt_schedules SET status = 'replaced', valid_to = '$prevDay', replaced_by = $id WHERE id = " . (int) $old['id']);
                }
                $replaced[] = (int) $old['id'];
            }
        }
        $row = mysqli_fetch_assoc(mysqli_query($conn, 'SELECT * FROM tt_schedules WHERE id = ' . $id));
        $row['breaks'] = array_map(function ($b) { return ['label' => $b['label'], 'start_time' => $b['start'], 'end_time' => $b['end']]; }, $breaks);
        tt_audit($conn, $actor, $uid, 'schedule', $id, $kind === 'temporary' ? 'temporary_adjustment' : 'new_version',
            ucfirst($kind) . ' timetable from ' . $from . ($to ? ' to ' . $to : '') . ': ' . tt_schedule_text($row)
            . ($replaced ? '. Replaces version(s) #' . implode(', #', $replaced) . ' from this date.' : '') . ($note !== '' ? ' Note: ' . $note : ''));
        mysqli_commit($conn);
    } catch (Throwable $e) {
        mysqli_rollback($conn);
        error_log('tt_save_schedule: ' . $e->getMessage());
        return [false, 'The timetable could not be saved. Nothing was changed.' . (current_user_role() === 'Admin' ? ' [' . $e->getMessage() . ']' : ''), 0];
    }
    try {
        require_once __DIR__ . '/notification_helper.php';
        notifyUser($conn, $uid, 'Your work timetable was updated', 'A ' . $kind . ' timetable applies to you from ' . date('d M Y', strtotime($from)) . ($to ? ' to ' . date('d M Y', strtotime($to)) : '')
            . ': ' . tt_schedule_text($row) . '. Open Work Timetable in MY MOTIVE to see it.');
    } catch (Throwable $e) { error_log('tt schedule notify: ' . $e->getMessage()); }
    return [true, ucfirst($kind) . ' timetable saved. The employee was notified.', $id];
}

/** Cancels a schedule that has not started yet (a future version or a future temporary adjustment). */
function tt_cancel_schedule(mysqli $conn, int $id, int $actor, string $reason): array
{
    $r = mysqli_fetch_assoc(mysqli_query($conn, 'SELECT * FROM tt_schedules WHERE id = ' . $id));
    if (!$r || $r['status'] === 'cancelled') { return [false, 'Timetable not found.']; }
    if ($r['valid_from'] <= date('Y-m-d')) { return [false, 'This timetable has already been used. It cannot be cancelled; create a new version from a future date instead.']; }
    mysqli_query($conn, "UPDATE tt_schedules SET status = 'cancelled' WHERE id = $id");
    // A cancelled future permanent version gives the dates back to the version it replaced.
    if ($r['kind'] === 'permanent') {
        mysqli_query($conn, "UPDATE tt_schedules SET status = 'active', valid_to = NULL, replaced_by = NULL WHERE replaced_by = $id AND status = 'replaced'");
    }
    tt_audit($conn, $actor, (int) $r['user_id'], 'schedule', $id, 'cancelled', 'Timetable #' . $id . ' (from ' . $r['valid_from'] . ') cancelled before it started. Reason: ' . $reason);
    return [true, 'Timetable cancelled.'];
}

/* ------------------------------------------------------------------ reports */

/**
 * Per-employee totals over a period (days up to today only; today counts as it stands now).
 * Returns ['rows' => [userId => totals + employee], 'days' => [userId => [date => calc]]].
 */
function tt_period_report(mysqli $conn, string $from, string $to, ?int $departmentId = null, ?int $userId = null): array
{
    $emps = tt_employees($conn, $departmentId);
    $vis = tt_visible_user_ids($conn);
    if ($vis !== null) { $emps = array_intersect_key($emps, array_flip($vis)); }
    if ($userId) { $emps = array_intersect_key($emps, [$userId => 1]); }
    $today = date('Y-m-d');
    $end = min($to, $today);
    $rows = []; $days = [];
    if (!$emps || $from > $end) { return ['rows' => [], 'days' => []]; }
    $ctx = tt_contexts($conn, $from, $end, array_keys($emps));
    $ot = [];
    foreach (mysqli_fetch_all(mysqli_query($conn, "SELECT user_id, work_date, status, minutes, approved_minutes FROM tt_overtime WHERE work_date BETWEEN '"
        . mysqli_real_escape_string($conn, $from) . "' AND '" . mysqli_real_escape_string($conn, $end) . "'"), MYSQLI_ASSOC) as $o) { $ot[(int) $o['user_id']][$o['work_date']] = $o; }
    $now = time();
    foreach ($emps as $uid => $e) {
        $t = ['employee' => $e, 'working_days' => 0, 'present_days' => 0, 'absent_days' => 0, 'late_days' => 0, 'leave_days' => 0,
              'scheduled' => 0, 'worked' => 0, 'auth_break' => 0, 'actual_break' => 0, 'excess_break' => 0, 'late_minutes' => 0,
              'late_returns' => 0, 'early_departure' => 0, 'absence' => 0, 'post_delay' => 0, 'lost' => 0, 'overtime_pending' => 0, 'overtime_approved' => 0, 'flags' => 0, 'has_schedule' => false];
        foreach ($ctx[$uid] ?? [] as $d => $c) {
            $calc = tt_engine_compute($c['plan'], $c['events'], $c['ex'], $d < $today ? strtotime($d . ' 23:59:59') + 6 * 3600 : $now, tt_settings($conn));
            $days[$uid][$d] = $calc + ['plan' => $c['plan'], 'events' => $c['events'], 'ex' => $c['ex']];
            if (!$c['plan']) { continue; }
            $t['has_schedule'] = true;
            if ($c['plan']['day_type'] === 'leave') { $t['leave_days']++; }
            if ($c['plan']['day_type'] !== 'work') { continue; }
            $t['working_days']++;
            if ($c['events']['check_in']) { $t['present_days']++; } elseif ($calc['status'] === 'Absent') { $t['absent_days']++; }
            if ($calc['punctuality'] === 'Late') { $t['late_days']++; }
            $t['scheduled'] += $calc['scheduled_minutes']; $t['worked'] += $calc['worked_minutes'];
            $t['auth_break'] += $calc['authorized_break_minutes']; $t['actual_break'] += $calc['actual_break_minutes'];
            $t['excess_break'] += $calc['excess_break_minutes']; $t['late_minutes'] += $calc['late_arrival_minutes'];
            $t['late_returns'] += isset($calc['lost_by_reason']['late_return']) ? 1 : 0;
            $t['early_departure'] += $calc['early_departure_minutes']; $t['absence'] += $calc['absence_minutes'];
            $t['post_delay'] += $calc['post_delay_minutes']; $t['lost'] += $calc['lost_minutes']; $t['flags'] += count($calc['flags']);
        }
        foreach ($ot[$uid] ?? [] as $o) {
            if ($o['status'] === 'Approved') { $t['overtime_approved'] += (int) $o['approved_minutes']; }
            elseif ($o['status'] === 'Pending') { $t['overtime_pending'] += (int) $o['minutes']; }
        }
        $t['attendance_pct'] = $t['working_days'] ? round(100 * $t['present_days'] / $t['working_days'], 1) : null;
        $t['punctuality_pct'] = $t['present_days'] ? round(100 * ($t['present_days'] - $t['late_days']) / $t['present_days'], 1) : null;
        // Punctuality score for rankings: share of scheduled time NOT lost.
        $t['score'] = $t['scheduled'] ? round(100 * max(0, $t['scheduled'] - $t['lost']) / $t['scheduled'], 1) : null;
        $rows[$uid] = $t;
    }
    return ['rows' => $rows, 'days' => $days];
}

/** For Payroll: recorded unworked time and approved overtime of one employee in a month ('YYYY-MM'). */
function tt_payroll_summary(mysqli $conn, int $userId, string $period): ?array
{
    if (!preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $period)) { return null; }
    try {
        tt_ensure($conn);
        if (!(int) mysqli_fetch_row(mysqli_query($conn, 'SELECT COUNT(*) FROM tt_schedules WHERE user_id = ' . $userId))[0]) { return null; }
        $from = $period . '-01'; $to = date('Y-m-t', strtotime($from));
        $end = min($to, date('Y-m-d'));
        if ($from > $end) { return null; }
        $ctx = tt_contexts($conn, $from, $end, [$userId])[$userId] ?? [];
        $lost = 0; $by = [];
        foreach ($ctx as $d => $c) {
            $calc = tt_engine_compute($c['plan'], $c['events'], $c['ex'], $d < date('Y-m-d') ? strtotime($d . ' 23:59:59') + 6 * 3600 : time(), tt_settings($conn));
            $lost += $calc['lost_minutes'];
            foreach ($calc['lost_by_reason'] as $r => $m) { $by[$r] = ($by[$r] ?? 0) + $m; }
        }
        $ot = mysqli_fetch_row(mysqli_query($conn, "SELECT COALESCE(SUM(CASE WHEN status = 'Approved' THEN approved_minutes END), 0), COALESCE(SUM(CASE WHEN status = 'Pending' THEN minutes END), 0)
            FROM tt_overtime WHERE user_id = $userId AND work_date BETWEEN '$from' AND '$to'"));
        return ['lost_minutes' => $lost, 'by_reason' => $by, 'overtime_approved' => (int) $ot[0], 'overtime_pending' => (int) $ot[1]];
    } catch (Throwable $e) { error_log('tt_payroll_summary: ' . $e->getMessage()); return null; }
}

/* ------------------------------------------------------------------ shared UI pieces */

function tt_badge(string $status): string
{
    $c = TT_STATUS_STYLE[$status] ?? 'secondary';
    $txt = in_array($c, ['light'], true) ? ' text-dark border' : '';
    return '<span class="badge bg-' . $c . $txt . '">' . tt_e($status) . '</span>';
}

function tt_tabs(string $active): void
{
    $tabs = [['my.php', 'My Timetable', 'bi-clock-history', true]];
    if (tt_is_supervisor()) {
        $pc = tt_pending_counts($GLOBALS['conn']);
        $n = array_sum($pc);
        $tabs[] = ['board.php', 'Live Board', 'bi-broadcast', access_can('timetable.approve')];
        $tabs[] = ['approvals.php', 'Approvals' . ($n ? ' <span class="badge rounded-pill bg-danger">' . $n . '</span>' : ''), 'bi-check2-circle', access_can('timetable.approve')];
        $tabs[] = ['reports.php', 'Reports', 'bi-bar-chart', access_can('timetable.reports')];
    }
    $tabs[] = ['schedules.php', 'Timetables', 'bi-calendar-week', access_can('timetable.manage')];
    $tabs[] = ['settings.php', 'Settings', 'bi-gear', access_can('timetable.manage')];
    echo '<div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3"><h2 class="mb-0">Work Timetable</h2>'
        . '<ul class="nav nav-pills small">';
    foreach ($tabs as [$file, $label, $icon, $show]) {
        if (!$show) { continue; }
        echo '<li class="nav-item"><a class="nav-link py-1 px-3' . ($file === $active ? ' active' : '') . '" href="' . $file . '"><i class="bi ' . $icon . ' me-1"></i>' . $label . '</a></li>';
    }
    echo '</ul></div>';
}

function tt_flash(): void
{
    foreach (['success' => 'success', 'error' => 'danger'] as $k => $c) {
        if (!empty($_GET[$k])) {
            echo '<div class="alert alert-' . $c . ' alert-dismissible fade show" style="white-space:pre-line;">' . tt_e($_GET[$k]) . '<button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>';
        }
    }
}