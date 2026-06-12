<?php

require_once __DIR__ . '/../Auth.php';
require_once __DIR__ . '/../Database.php';
require_once __DIR__ . '/../Mailer.php';

class UserController {
    private const PASSWORD_MIN_LENGTH = 8;

    private function sendJsonHeaders() {
        if (!headers_sent()) {
            header('Content-Type: application/json');
            header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
            header('Pragma: no-cache');
            header('Expires: 0');
        }
    }

    private function readInput() {
        $contentType = $_SERVER['CONTENT_TYPE'] ?? '';
        if (stripos($contentType, 'application/json') !== false) {
            $data = json_decode(file_get_contents('php://input'), true);
            return is_array($data) ? $data : [];
        }
        return is_array($_POST) ? $_POST : [];
    }

    private function usersList(PDO $db) {
        $stmt = $db->query("SELECT id, username, email, role, created_at, (password_hash IS NULL) AS activation_pending FROM users ORDER BY created_at ASC, id ASC");
        return $stmt ? $stmt->fetchAll() : [];
    }

    private function countFullAdmins(array $users) {
        $count = 0;
        foreach ($users as $user) {
            if (Auth::normalizeRole($user['role'] ?? null) === 'full_admin') {
                $count++;
            }
        }
        return $count;
    }

    private function decorateUsers(array $users) {
        $currentUser = Auth::user();
        $currentUserId = (int)($currentUser['id'] ?? 0);
        $fullAdminCount = $this->countFullAdmins($users);
        $decorated = [];

        foreach ($users as $user) {
            $normalizedRole = Auth::normalizeRole($user['role'] ?? null) ?: 'admin';
            $isCurrentUser = (int)$user['id'] === $currentUserId;
            $guardrail = '';
            $canDelete = true;
            $canChangeRole = true;

            if ($isCurrentUser) {
                $canDelete = false;
                $guardrail = 'You cannot delete the account you are currently using.';
            }

            if ($normalizedRole === 'full_admin' && $fullAdminCount <= 1) {
                $canDelete = false;
                $canChangeRole = false;
                $guardrail = 'Campo must always keep at least one Full Admin account.';
            }

            $decorated[] = [
                'id'                 => (int)$user['id'],
                'username'           => $user['username'],
                'email'              => $user['email'] ?? '',
                'role'               => $normalizedRole,
                'role_label'         => Auth::roleLabel($normalizedRole),
                'created_at'         => $user['created_at'] ?? null,
                'activation_pending' => (bool)($user['activation_pending'] ?? false),
                'is_current_user'    => $isCurrentUser,
                'can_delete'         => $canDelete,
                'can_change_role'    => $canChangeRole,
                'guardrail_message'  => $guardrail,
            ];
        }

        return [
            'users'            => $decorated,
            'current_user_id'  => $currentUserId,
            'full_admin_count' => $fullAdminCount,
        ];
    }

    private function userById(PDO $db, $id) {
        $stmt = $db->prepare("SELECT id, username, email, role, created_at FROM users WHERE id = ? LIMIT 1");
        $stmt->execute([(int)$id]);
        return $stmt->fetch();
    }

    private function validateUsername(PDO $db, $username, $excludeId = null) {
        $username = trim((string)$username);
        if ($username === '') return 'Please enter a username.';
        if (strlen($username) > 255) return 'Username is too long.';

        $sql = "SELECT id FROM users WHERE LOWER(username) = LOWER(?)";
        $params = [$username];
        if ($excludeId !== null) {
            $sql .= " AND id <> ?";
            $params[] = (int)$excludeId;
        }
        $sql .= " LIMIT 1";
        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        if ($stmt->fetch()) return 'That username is already in use.';

        return null;
    }

    private function validateEmail(PDO $db, $email, $excludeId = null) {
        $email = trim((string)$email);
        if ($email === '') return 'Please enter an email address.';
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) return 'Please enter a valid email address.';
        if (strlen($email) > 255) return 'Email address is too long.';

        $sql = "SELECT id FROM users WHERE LOWER(email) = LOWER(?)";
        $params = [$email];
        if ($excludeId !== null) {
            $sql .= " AND id <> ?";
            $params[] = (int)$excludeId;
        }
        $sql .= " LIMIT 1";
        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        if ($stmt->fetch()) return 'That email address is already in use.';

        return null;
    }

    private function validatePassword($password, $required = true) {
        $password = (string)$password;
        if ($password === '' && !$required) return null;
        if ($password === '') return 'Please enter a password.';
        if (strlen($password) < self::PASSWORD_MIN_LENGTH) {
            return 'Password must be at least ' . self::PASSWORD_MIN_LENGTH . ' characters long.';
        }
        return null;
    }

    private function validateRole($role) {
        $role = trim((string)$role);
        if (!in_array($role, Auth::validRoles(), true)) return 'Please choose a valid role.';
        return null;
    }

    private function ensureRoleSchemaReady() {
        if (Auth::isLegacyRoleSchema()) {
            http_response_code(409);
            echo json_encode([
                'success' => false,
                'message' => 'User role migration is required before user management can change roles. Please run /api/migrate first.'
            ]);
            return false;
        }
        return true;
    }

    private function sendActivationEmail(PDO $db, $userId, $username, $email, $server = []) {
        $token   = bin2hex(random_bytes(32));
        $expires = date('Y-m-d H:i:s', time() + 72 * 3600);

        $db->prepare("DELETE FROM user_tokens WHERE user_id=? AND type='activation' AND used_at IS NULL")
           ->execute([$userId]);
        $db->prepare("INSERT INTO user_tokens (user_id, token, type, expires_at) VALUES (?, ?, 'activation', ?)")
           ->execute([$userId, $token, $expires]);

        $config = CampoMailer::configFromDb($db, $server);
        $activationUrl = $config['app_base_url'] . '/activate?token=' . $token;
        $body = "Hello $username,\n\nYour Campo admin account has been created.\n\nPlease click the link below to activate your account and set your own password:\n\n$activationUrl\n\nThis link is valid for 72 hours.\n\nIf you did not expect this email, please ignore it.";

        CampoMailer::sendTextWithConfig($email, 'Activate your Campo account', $body, $config);
    }

    public function index() {
        $this->sendJsonHeaders();
        try {
            $db = Database::connect();
            $decorated = $this->decorateUsers($this->usersList($db));

            $mailConfig = CampoMailer::configFromDb($db, $_SERVER);
            $mailStatus = CampoMailer::statusFromConfig($mailConfig);

            echo json_encode([
                'users'              => $decorated['users'],
                'current_user_id'    => $decorated['current_user_id'],
                'full_admin_count'   => $decorated['full_admin_count'],
                'roles'              => Auth::roleOptions(),
                'migration_required' => Auth::isLegacyRoleSchema(),
                'password_min_length'=> self::PASSWORD_MIN_LENGTH,
                'mail_configured'    => $mailStatus['configured'],
            ]);
        } catch (Throwable $e) {
            http_response_code(500);
            echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        }
    }

    public function store() {
        $this->sendJsonHeaders();
        if (!$this->ensureRoleSchemaReady()) return;

        $input    = $this->readInput();
        $username = trim((string)($input['username'] ?? ''));
        $email    = trim((string)($input['email'] ?? ''));
        $role     = trim((string)($input['role'] ?? ''));

        try {
            $db = Database::connect();

            $usernameError = $this->validateUsername($db, $username);
            if ($usernameError) {
                http_response_code(422);
                echo json_encode(['success' => false, 'message' => $usernameError]);
                return;
            }

            $emailError = $this->validateEmail($db, $email);
            if ($emailError) {
                http_response_code(422);
                echo json_encode(['success' => false, 'message' => $emailError]);
                return;
            }

            $roleError = $this->validateRole($role);
            if ($roleError) {
                http_response_code(422);
                echo json_encode(['success' => false, 'message' => $roleError]);
                return;
            }

            $stmt = $db->prepare("INSERT INTO users (username, email, password_hash, role) VALUES (?, ?, NULL, ?)");
            $stmt->execute([$username, $email, $role]);
            $userId = (int)$db->lastInsertId();

            $mailSent  = false;
            $mailError = null;
            try {
                $this->sendActivationEmail($db, $userId, $username, $email, $_SERVER);
                $mailSent = true;
            } catch (Throwable $e) {
                $mailError = $e->getMessage();
            }

            echo json_encode([
                'success'               => true,
                'id'                    => $userId,
                'activation_email_sent' => $mailSent,
                'mail_error'            => $mailError,
            ]);
        } catch (Throwable $e) {
            http_response_code(500);
            echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        }
    }

    public function update($id) {
        $this->sendJsonHeaders();
        if (!$this->ensureRoleSchemaReady()) return;

        $input    = $this->readInput();
        $userId   = (int)$id;
        $username = trim((string)($input['username'] ?? ''));
        $email    = trim((string)($input['email'] ?? ''));
        $role     = trim((string)($input['role'] ?? ''));
        $password = (string)($input['password'] ?? '');

        try {
            $db = Database::connect();
            $target = $this->userById($db, $userId);
            if (!$target) {
                http_response_code(404);
                echo json_encode(['success' => false, 'message' => 'User not found.']);
                return;
            }

            $users = $this->usersList($db);
            $fullAdminCount = $this->countFullAdmins($users);
            $targetNormalizedRole = Auth::normalizeRole($target['role'] ?? null) ?: 'admin';

            $usernameError = $this->validateUsername($db, $username, $userId);
            if ($usernameError) {
                http_response_code(422);
                echo json_encode(['success' => false, 'message' => $usernameError]);
                return;
            }

            $emailError = $this->validateEmail($db, $email, $userId);
            if ($emailError) {
                http_response_code(422);
                echo json_encode(['success' => false, 'message' => $emailError]);
                return;
            }

            $roleError = $this->validateRole($role);
            if ($roleError) {
                http_response_code(422);
                echo json_encode(['success' => false, 'message' => $roleError]);
                return;
            }

            $passwordError = $this->validatePassword($password, false);
            if ($passwordError) {
                http_response_code(422);
                echo json_encode(['success' => false, 'message' => $passwordError]);
                return;
            }

            if ($targetNormalizedRole === 'full_admin' && $role !== 'full_admin' && $fullAdminCount <= 1) {
                http_response_code(422);
                echo json_encode([
                    'success' => false,
                    'message' => 'Campo must always keep at least one Full Admin account.'
                ]);
                return;
            }

            $fields = ['username = ?', 'email = ?', 'role = ?'];
            $params = [$username, $email, $role];

            if ($password !== '') {
                $fields[] = 'password_hash = ?';
                $params[] = password_hash($password, PASSWORD_DEFAULT);
            }

            $params[] = $userId;
            $stmt = $db->prepare("UPDATE users SET " . implode(', ', $fields) . " WHERE id = ?");
            $stmt->execute($params);

            Auth::resetCurrentUser();

            echo json_encode(['success' => true]);
        } catch (Throwable $e) {
            http_response_code(500);
            echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        }
    }

    public function delete($id) {
        $this->sendJsonHeaders();
        $userId = (int)$id;
        $currentUser = Auth::user();
        if (!$currentUser) {
            http_response_code(401);
            echo json_encode(['success' => false, 'message' => 'Unauthorized']);
            return;
        }

        if ($currentUser['id'] === $userId) {
            http_response_code(422);
            echo json_encode(['success' => false, 'message' => 'You cannot delete the account you are currently using.']);
            return;
        }

        try {
            $db = Database::connect();
            $target = $this->userById($db, $userId);
            if (!$target) {
                http_response_code(404);
                echo json_encode(['success' => false, 'message' => 'User not found.']);
                return;
            }

            $users = $this->usersList($db);
            $fullAdminCount = $this->countFullAdmins($users);
            if ((Auth::normalizeRole($target['role'] ?? null) ?: 'admin') === 'full_admin' && $fullAdminCount <= 1) {
                http_response_code(422);
                echo json_encode([
                    'success' => false,
                    'message' => 'Campo must always keep at least one Full Admin account.'
                ]);
                return;
            }

            $db->prepare("DELETE FROM user_tokens WHERE user_id = ?")->execute([$userId]);
            $db->prepare("DELETE FROM users WHERE id = ?")->execute([$userId]);

            echo json_encode(['success' => true]);
        } catch (Throwable $e) {
            http_response_code(500);
            echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        }
    }
}
