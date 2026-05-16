<?php

class CampoAuthController {

    private function db() {
        return Database::connect();
    }

    private static array $allowedOrigins = [
        'https://campo.urbantek.online',
        'http://campo.urbantek.online',
        'https://campo.nix.local',
        'http://campo.nix.local',
    ];

    private function corsHeaders(string $methods = 'POST, OPTIONS'): void {
        $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
        if (in_array($origin, self::$allowedOrigins, true)) {
            header('Access-Control-Allow-Origin: ' . $origin);
            header('Access-Control-Allow-Methods: ' . $methods);
            header('Access-Control-Allow-Headers: Content-Type, X-Campo-Token, Authorization');
            header('Vary: Origin');
        }
    }

    public function preflight(): void {
        $this->corsHeaders();
        http_response_code(204);
        echo '';
    }

    private function json(int $code, array $data): void {
        $this->corsHeaders('POST, GET, OPTIONS');
        http_response_code($code);
        header('Content-Type: application/json');
        echo json_encode($data);
    }

    private function input(): array {
        $raw = file_get_contents('php://input');
        if ($raw) {
            $json = json_decode($raw, true);
            if (is_array($json)) return $json;
        }
        return $_POST ?: [];
    }

    private function ip(): string {
        return substr((string)($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45);
    }

    // ── POST /api/public/auth/request-otp ────────────────────────────────────

    public function requestOtp(): void {
        $data  = $this->input();
        $email = strtolower(trim((string)($data['email'] ?? '')));

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $this->json(422, ['message' => 'A valid email address is required.']);
            return;
        }

        $db = $this->db();

        // Rate-limit: max 3 unused codes per email in the last 10 minutes
        $recent = $db->prepare(
            "SELECT COUNT(*) FROM campo_otp_codes
             WHERE email = ? AND used_at IS NULL AND created_at > DATE_SUB(NOW(), INTERVAL 10 MINUTE)"
        );
        $recent->execute([$email]);
        if ((int)$recent->fetchColumn() >= 3) {
            $this->json(429, ['message' => 'Too many attempts. Please wait a few minutes and try again.']);
            return;
        }

        // Generate 6-digit code
        $code     = str_pad((string)random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        $codeHash = password_hash($code, PASSWORD_BCRYPT);

        $db->prepare(
            "INSERT INTO campo_otp_codes (email, code_hash, expires_at, ip_address)
             VALUES (?, ?, DATE_ADD(NOW(), INTERVAL 10 MINUTE), ?)"
        )->execute([$email, $codeHash, $this->ip()]);
        $insertedId = (int)$db->lastInsertId();

        // Clean up old expired codes for this email
        $db->prepare("DELETE FROM campo_otp_codes WHERE email = ? AND expires_at < NOW() AND id != ?")->execute([$email, $insertedId]);

        // Send email
        try {
            $config = CampoMailer::configFromDb($db, $_SERVER);
            $subject = 'Your Campo sign-in code';
            $body    = "Your Campo sign-in code is:\n\n    $code\n\nThis code expires in 10 minutes. If you didn't request this, you can ignore it.";
            CampoMailer::sendTextWithConfig($email, $subject, $body, $config);
            $this->json(200, ['success' => true, 'message' => 'Code sent. Check your email.']);
        } catch (Throwable $e) {
            // Remove the code we just saved since we couldn't deliver it
            $db->prepare("DELETE FROM campo_otp_codes WHERE id = ?")->execute([$insertedId]);
            $this->json(500, ['message' => 'Could not send email. Please try again or contact the camp office.']);
        }
    }

    // ── POST /api/public/auth/verify-otp ─────────────────────────────────────

    public function verifyOtp(): void {
        $data  = $this->input();
        $email = strtolower(trim((string)($data['email'] ?? '')));
        $code  = trim((string)($data['code'] ?? ''));

        if (!filter_var($email, FILTER_VALIDATE_EMAIL) || $code === '') {
            $this->json(422, ['message' => 'Email and code are required.']);
            return;
        }

        $db = $this->db();

        // Fetch the most recent unused, unexpired code for this email
        $stmt = $db->prepare(
            "SELECT id, code_hash FROM campo_otp_codes
             WHERE email = ? AND used_at IS NULL AND expires_at > NOW()
             ORDER BY created_at DESC LIMIT 1"
        );
        $stmt->execute([$email]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$row || !password_verify($code, $row['code_hash'])) {
            $this->json(401, ['message' => 'Invalid or expired code. Please request a new one.']);
            return;
        }

        // Mark code used
        $db->prepare("UPDATE campo_otp_codes SET used_at = NOW() WHERE id = ?")->execute([$row['id']]);

        // Upsert user (create account on first sign-in)
        $userStmt = $db->prepare("SELECT id, name, phone, household_id FROM campo_users WHERE email = ?");
        $userStmt->execute([$email]);
        $user = $userStmt->fetch(PDO::FETCH_ASSOC);

        if (!$user) {
            // Auto-match to household via member email
            $householdId = $this->matchHouseholdByEmail($db, $email);
            // Auto-populate name from matched member
            $autoName = $householdId ? $this->nameFromEmail($db, $email) : '';
            $db->prepare(
                "INSERT INTO campo_users (email, name, household_id, created_at) VALUES (?, ?, ?, NOW())"
            )->execute([$email, $autoName, $householdId]);
            $userId = (int)$db->lastInsertId();
            $user   = ['id' => $userId, 'name' => $autoName, 'phone' => '', 'household_id' => $householdId];
        } else {
            $userId = (int)$user['id'];
            // Re-attempt match if household still unlinked
            if (!$user['household_id']) {
                $householdId = $this->matchHouseholdByEmail($db, $email);
                if ($householdId) {
                    $db->prepare("UPDATE campo_users SET household_id = ? WHERE id = ?")->execute([$householdId, $userId]);
                    $user['household_id'] = $householdId;
                }
            }
        }

        // Update last_login_at
        $db->prepare("UPDATE campo_users SET last_login_at = NOW() WHERE id = ?")->execute([$userId]);

        // Create session token
        $token     = bin2hex(random_bytes(32));
        $tokenHash = hash('sha256', $token);
        $ua        = substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255);

        $db->prepare(
            "INSERT INTO campo_sessions (user_id, token_hash, expires_at, ip_address, user_agent)
             VALUES (?, ?, DATE_ADD(NOW(), INTERVAL 30 DAY), ?, ?)"
        )->execute([$userId, $tokenHash, $this->ip(), $ua]);

        $this->json(200, [
            'success' => true,
            'token'   => $token,
            'user'    => [
                'id'           => $userId,
                'email'        => $email,
                'name'         => $user['name'],
                'phone'        => $user['phone'],
                'household_id' => $user['household_id'],
            ],
        ]);
    }

    // ── POST /api/public/auth/logout ──────────────────────────────────────────

    public function logout(): void {
        $token = $this->resolveToken();
        if ($token) {
            $hash = hash('sha256', $token);
            $this->db()->prepare("DELETE FROM campo_sessions WHERE token_hash = ?")->execute([$hash]);
        }
        $this->json(200, ['success' => true]);
    }

    // ── GET /api/public/auth/me ───────────────────────────────────────────────

    public function me(): void {
        $token = $this->resolveToken();
        if (!$token) {
            $this->json(401, ['message' => 'Not authenticated.']);
            return;
        }

        $user = $this->userFromToken($token);
        if (!$user) {
            $this->json(401, ['message' => 'Session expired or invalid.']);
            return;
        }

        $this->json(200, ['user' => $user]);
    }

    // ── POST /api/public/auth/update-profile ─────────────────────────────────

    public function updateProfile(): void {
        $token = $this->resolveToken();
        if (!$token) { $this->json(401, ['message' => 'Not authenticated.']); return; }

        $user = $this->userFromToken($token);
        if (!$user) { $this->json(401, ['message' => 'Session expired or invalid.']); return; }

        $data  = $this->input();
        $name  = trim((string)($data['name']  ?? ''));
        $phone = trim((string)($data['phone'] ?? ''));

        $this->db()->prepare(
            "UPDATE campo_users SET name = ?, phone = ? WHERE id = ?"
        )->execute([$name, $phone, $user['id']]);

        $this->json(200, ['success' => true, 'user' => array_merge($user, ['name' => $name, 'phone' => $phone])]);
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    public function resolveToken(): ?string {
        $header = $_SERVER['HTTP_X_CAMPO_TOKEN'] ?? $_SERVER['HTTP_AUTHORIZATION'] ?? '';
        if (str_starts_with($header, 'Bearer ')) {
            return trim(substr($header, 7));
        }
        if ($header !== '') return trim($header);

        // Also accept as POST body field (for logout convenience)
        $data = $this->input();
        $t = trim((string)($data['token'] ?? ''));
        return $t !== '' ? $t : null;
    }

    private function matchHouseholdByEmail(PDO $db, string $email): ?int {
        $stmt = $db->prepare("SELECT household_id FROM members WHERE LOWER(email) = LOWER(?) AND household_id IS NOT NULL LIMIT 1");
        $stmt->execute([$email]);
        $id = $stmt->fetchColumn();
        return $id ? (int)$id : null;
    }

    private function nameFromEmail(PDO $db, string $email): string {
        $stmt = $db->prepare("SELECT first_name, last_name FROM members WHERE LOWER(email) = LOWER(?) LIMIT 1");
        $stmt->execute([$email]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) return '';
        return trim(($row['first_name'] ?? '') . ' ' . ($row['last_name'] ?? ''));
    }

    public function userFromToken(string $token): ?array {
        $hash = hash('sha256', $token);
        $db   = $this->db();

        $stmt = $db->prepare(
            "SELECT cs.id AS session_id, cs.user_id, cs.expires_at,
                    cu.email, cu.name, cu.phone, cu.household_id
             FROM campo_sessions cs
             JOIN campo_users cu ON cu.id = cs.user_id
             WHERE cs.token_hash = ? AND cs.expires_at > NOW()
             LIMIT 1"
        );
        $stmt->execute([$hash]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) return null;

        // Slide expiry
        $db->prepare(
            "UPDATE campo_sessions SET last_used_at = NOW(), expires_at = DATE_ADD(NOW(), INTERVAL 30 DAY) WHERE id = ?"
        )->execute([$row['session_id']]);

        return [
            'id'           => (int)$row['user_id'],
            'email'        => $row['email'],
            'name'         => $row['name'],
            'phone'        => $row['phone'],
            'household_id' => $row['household_id'] ? (int)$row['household_id'] : null,
        ];
    }
}
