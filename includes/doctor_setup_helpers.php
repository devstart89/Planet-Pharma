<?php
/**
 * Doctor first-login setup — shared helpers.
 *
 * Include this file (only — it has no side effects) from anywhere that
 * needs to know whether a doctor has finished mandatory setup:
 *   - login_controller.php (decides where to redirect after login)
 *   - account_settings.php (renders the forced setup UI)
 *   - require_doctor_setup.php (the page guard, see that file)
 *
 * SCHEMA REQUIREMENT — run once if not already present:
 *   ALTER TABLE users ADD COLUMN must_change_password TINYINT(1) NOT NULL DEFAULT 0;
 *
 * Whatever creates new Doctor accounts (the "User Form" admin screen)
 * should set must_change_password = 1 and leave license_number,
 * ptr_number, and signature_path blank for new doctors, so they're
 * routed through this flow on their first login. Existing doctor
 * accounts that are already fully set up are unaffected — the default
 * of 0 combined with already-filled fields means isDoctorSetupComplete()
 * returns true for them immediately.
 */

if (!function_exists('normalizeRole')) {
    function normalizeRole(?string $role): string {
        return str_replace('-', '_', strtolower(trim((string) $role)));
    }
}

if (!function_exists('isDoctorSetupComplete')) {
    /**
     * @param array $user Must contain email, username, license_number,
     *                     signature_path keys (missing/null keys are
     *                     treated as empty). ptr_number is intentionally
     *                     NOT part of this check — it's an optional
     *                     profile field, not a first-login requirement.
     * @param bool $mustChangePassword Current value of the user's
     *                     must_change_password flag.
     */
    function isDoctorSetupComplete(array $user, bool $mustChangePassword): bool {
        return $mustChangePassword === false
            && trim((string) ($user['email'] ?? '')) !== ''
            && trim((string) ($user['username'] ?? '')) !== ''
            && trim((string) ($user['license_number'] ?? '')) !== ''
            && !empty($user['signature_path']);
    }
}

if (!function_exists('fetchDoctorSetupRow')) {
    /**
     * Fetches just the columns needed to evaluate setup completeness.
     * Returns null if the user doesn't exist. Throws PDOException if
     * must_change_password (or another expected column) is missing —
     * callers should catch this and decide whether to fail open or
     * closed for their context.
     */
    function fetchDoctorSetupRow(PDO $conn, int $userId): ?array {
        $stmt = $conn->prepare("
            SELECT email, username, license_number, ptr_number,
                   signature_path, must_change_password
            FROM users
            WHERE id = ?
        ");
        $stmt->execute([$userId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }
}