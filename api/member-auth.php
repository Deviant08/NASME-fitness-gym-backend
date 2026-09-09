<?php
require_once __DIR__ . '/helpers.php';

$action = $_GET['action'] ?? '';
$m      = method();

/** Streak only becomes active after this many consecutive check-in days. */
const STREAK_MIN_DAYS = 5;

function ensureCheckinsTable(PDO $db): void {
    $db->exec('CREATE TABLE IF NOT EXISTS checkins (
        id INT AUTO_INCREMENT PRIMARY KEY,
        member_id INT NOT NULL,
        checked_in_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX (member_id),
        INDEX (checked_in_at)
    )');
}

/**
 * Subscription end date from registration/start date + plan.
 * Daily  → same day as start (valid that day only)
 * Weekly → start + 7 days
 * Monthly → start + 1 month
 * Yearly → start + 1 year
 * (Same logic as admin members.php)
 */
function computeExpiresAt(?string $plan, ?string $startDate): string {
    if (!$startDate) $startDate = date('Y-m-d');
    $plan = strtolower(trim((string)$plan));
    try {
        $dt = new DateTime($startDate);
    } catch (Throwable $e) {
        $dt = new DateTime('today');
    }
    if (str_contains($plan, 'day')) {
        // Daily plan ends on the registration/start day itself
        return $dt->format('Y-m-d');
    }
    if (str_contains($plan, 'week')) {
        $dt->modify('+7 days');
    } elseif (str_contains($plan, 'year')) {
        $dt->modify('+1 year');
    } else {
        // Monthly / default
        $dt->modify('+1 month');
    }
    return $dt->format('Y-m-d');
}

/**
 * Ensure expires_at is set (backfill from plan + start) and flip Active → Expired when past.
 * Returns the (possibly updated) member row.
 */
function ensureMemberExpiry(PDO $db, array $member): array {
    $today = date('Y-m-d');
    $id = (int)$member['id'];

    // Backfill missing expires_at from plan + start/joined date
    if (empty($member['expires_at']) && !empty($member['plan'])) {
        $start = $member['start_date'] ?? $member['joined_at'] ?? $today;
        $start = $start ? substr((string)$start, 0, 10) : $today;
        $computed = computeExpiresAt($member['plan'], $start);
        try {
            $db->prepare('UPDATE members SET expires_at = ? WHERE id = ?')->execute([$computed, $id]);
            $member['expires_at'] = $computed;
        } catch (Throwable $e) {
            $member['expires_at'] = $computed;
        }
    }

    // Auto-expire if past end date
    if (($member['status'] ?? '') === 'Active' && !empty($member['expires_at']) && $member['expires_at'] < $today) {
        try {
            $db->prepare('UPDATE members SET status = "Expired", updated_at = NOW() WHERE id = ? AND status = "Active"')
               ->execute([$id]);
            $member['status'] = 'Expired';
        } catch (Throwable $e) {}
    }

    return $member;
}

function loadMemberRow(PDO $db, int $id): ?array {
    $stmt = $db->prepare('SELECT * FROM members WHERE id = ?');
    $stmt->execute([$id]);
    $row = $stmt->fetch();
    return $row ?: null;
}

/**
 * Consecutive daily check-ins ending today or yesterday.
 * Streak only "counts" (active) once consecutive days >= STREAK_MIN_DAYS (5).
 */
function computeStreak(PDO $db, int $memberId): array {
    ensureCheckinsTable($db);
    $stmt = $db->prepare('SELECT DISTINCT DATE(checked_in_at) AS d FROM checkins WHERE member_id = ? ORDER BY d DESC LIMIT 90');
    $stmt->execute([$memberId]);
    $dates = array_map(fn($r) => $r['d'], $stmt->fetchAll());

    $consecutive = 0;
    if ($dates) {
        $today = new DateTime('today');
        $cursor = clone $today;
        // Allow streak to continue if last check-in was yesterday (not yet today)
        $first = new DateTime($dates[0]);
        $diffFirst = (int)$today->diff($first)->format('%r%a');
        if ($diffFirst > 1) {
            // Gap longer than 1 day — streak broken
            $consecutive = 0;
        } else {
            if ($diffFirst === 1) {
                // Last check-in was yesterday — start counting from yesterday
                $cursor = clone $first;
            }
            foreach ($dates as $d) {
                $day = new DateTime($d);
                if ($day->format('Y-m-d') === $cursor->format('Y-m-d')) {
                    $consecutive++;
                    $cursor->modify('-1 day');
                } else {
                    break;
                }
            }
        }
    }

    $active = $consecutive >= STREAK_MIN_DAYS;
    return [
        'consecutive_days' => $consecutive,
        'streak_active'    => $active,
        // Displayed streak only after 5 days; before that show 0
        'streak'           => $active ? $consecutive : 0,
        'streak_threshold' => STREAK_MIN_DAYS,
        'progress'         => min($consecutive, STREAK_MIN_DAYS),
        'message'          => $active
            ? ($consecutive . '-day streak')
            : ('Streak starts after ' . STREAK_MIN_DAYS . ' days — ' . $consecutive . '/' . STREAK_MIN_DAYS),
    ];
}

function memberSessionPayload(array $member, ?array $streak = null): array {
    $payload = [
        'id'          => $member['id'],
        'member_code' => $member['member_code'],
        'full_name'   => $member['full_name'],
        'plan'        => $member['plan'],
        'status'      => $member['status'],
        'checkins'    => (int)($member['checkins'] ?? 0),
        'joined_at'   => $member['joined_at'] ?? $member['start_date'] ?? null,
        'start_date'  => $member['start_date'] ?? null,
        'expires_at'  => $member['expires_at'] ?? null,
        'phone'       => $member['phone'],
        'email'       => $member['email'] ?? null,
    ];
    if ($streak !== null) {
        $payload['streak'] = $streak;
    }
    return $payload;
}

if ($action === 'login' && $m === 'POST') {
    $b          = body();
    $memberCode = strtoupper(trim($b['member_code'] ?? ''));
    $phone      = trim($b['phone'] ?? '');

    if (!$memberCode || !$phone) {
        respond(['error' => 'Member code and phone number are required.'], 400);
    }

    $db   = getDB();
    $stmt = $db->prepare('SELECT * FROM members WHERE member_code = ?');
    $stmt->execute([$memberCode]);
    $member = $stmt->fetch();

    if (!$member) {
        respond(['error' => 'Member ID not found. Please check your member code.'], 401);
    }

    $cleanInput  = preg_replace('/[\s\-+]/', '', $phone);
    $cleanStored = preg_replace('/[\s\-+]/', '', $member['phone']);
    if ($cleanInput !== $cleanStored) {
        respond(['error' => 'Incorrect phone number. Please try again.'], 401);
    }

    // Backfill expires_at + auto-expire if past (same rules as admin)
    $member = ensureMemberExpiry($db, $member);

    if ($member['status'] === 'Archived') {
        $reason = $member['archive_reason'] ?? '';
        respond([
            'error' => 'This membership has been archived and can no longer be used to log in.' . ($reason ? ' Reason: ' . $reason : ''),
            'archived' => true,
        ], 403);
    }
    if ($member['status'] === 'Suspended') {
        respond(['error' => 'Your membership has been suspended. Please contact the gym.'], 403);
    }
    if ($member['status'] === 'Expired') {
        respond(['error' => 'Your membership has expired. Please renew at the front desk.', 'expired' => true], 403);
    }

    $streak = computeStreak($db, (int)$member['id']);
    $_SESSION['member'] = memberSessionPayload($member, $streak);

    logAction($db, null, "Member {$member['member_code']} ({$member['full_name']}) logged in", 'success');
    respond(['success' => true, 'member' => $_SESSION['member']]);
}

if ($action === 'me' && $m === 'GET') {
    respond(['member' => $_SESSION['member'] ?? null]);
}

if ($action === 'profile' && $m === 'GET') {
    if (empty($_SESSION['member'])) respond(['error' => 'Not logged in.'], 401);
    $db       = getDB();
    $memberId = (int)$_SESSION['member']['id'];
    $member   = loadMemberRow($db, $memberId);
    if (!$member) respond(['error' => 'Member not found.'], 404);

    // Backfill expires_at so member portal always shows the correct end date
    $member = ensureMemberExpiry($db, $member);

    $streak = computeStreak($db, $memberId);
    $_SESSION['member'] = memberSessionPayload($member, $streak);

    $payStmt = $db->prepare('SELECT txn_code, plan, amount, method, status, paid_at FROM payments WHERE member_id = ? ORDER BY paid_at DESC');
    $payStmt->execute([$memberId]);

    ensureCheckinsTable($db);
    $cinStmt = $db->prepare('SELECT id, checked_in_at FROM checkins WHERE member_id = ? ORDER BY checked_in_at DESC LIMIT 20');
    $cinStmt->execute([$memberId]);

    respond([
        'member'   => $_SESSION['member'],
        'payments' => $payStmt->fetchAll(),
        'checkins' => $cinStmt->fetchAll(),
        'streak'   => $streak,
    ]);
}

if ($action === 'checkin' && $m === 'POST') {
    if (empty($_SESSION['member'])) respond(['error' => 'Not logged in.'], 401);
    $db       = getDB();
    $memberId = (int)$_SESSION['member']['id'];
    $member   = loadMemberRow($db, $memberId);
    if (!$member) respond(['error' => 'Member not found.'], 404);

    // Backfill expires_at + auto-expire before allowing check-in
    $member = ensureMemberExpiry($db, $member);

    if (in_array($member['status'], ['Archived', 'Suspended', 'Expired', 'Pending'], true)) {
        respond(['error' => 'You cannot check in while membership status is ' . $member['status'] . '.'], 403);
    }

    ensureCheckinsTable($db);

    $dup = $db->prepare('SELECT id FROM checkins WHERE member_id = ? AND DATE(checked_in_at) = CURDATE() LIMIT 1');
    $dup->execute([$memberId]);
    if ($dup->fetch()) {
        respond(['error' => 'You already checked in today.', 'already' => true], 409);
    }

    $db->prepare('INSERT INTO checkins (member_id, checked_in_at) VALUES (?, NOW())')->execute([$memberId]);
    $db->prepare('UPDATE members SET checkins = COALESCE(checkins, 0) + 1, updated_at = NOW() WHERE id = ?')->execute([$memberId]);

    $member = loadMemberRow($db, $memberId);
    $member = ensureMemberExpiry($db, $member);
    $streak = computeStreak($db, $memberId);
    $_SESSION['member'] = memberSessionPayload($member, $streak);

    $msg = 'Checked in successfully.';
    if ($streak['streak_active']) {
        $msg .= ' ' . $streak['streak'] . '-day streak!';
    } else {
        $msg .= ' Streak builds after 5 consecutive days (' . $streak['progress'] . '/5).';
    }

    logAction($db, null, "Member {$member['member_code']} ({$member['full_name']}) checked in", 'success');
    respond([
        'success'       => true,
        'message'       => $msg,
        'member'        => $_SESSION['member'],
        'streak'        => $streak,
        'checked_in_at' => date('Y-m-d H:i:s'),
    ]);
}

if ($action === 'payments' && $m === 'GET') {
    if (empty($_SESSION['member'])) respond(['error' => 'Not logged in.'], 401);
    $db   = getDB();
    $stmt = $db->prepare('SELECT txn_code, plan, amount, method, status, paid_at FROM payments WHERE member_id = ? ORDER BY paid_at DESC');
    $stmt->execute([$_SESSION['member']['id']]);
    respond(['data' => $stmt->fetchAll()]);
}

if ($action === 'logout' && $m === 'POST') {
    $name = $_SESSION['member']['full_name']   ?? 'Unknown';
    $code = $_SESSION['member']['member_code'] ?? '';
    unset($_SESSION['member']);
    $db = getDB();
    logAction($db, null, "Member $code ($name) logged out", 'info');
    respond(['success' => true]);
}

respond(['error' => 'Invalid action.'], 400);
