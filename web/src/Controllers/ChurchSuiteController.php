<?php

require_once __DIR__ . '/../Auth.php';
require_once __DIR__ . '/../Database.php';
require_once __DIR__ . '/../ChurchSuiteClientInterface.php';
require_once __DIR__ . '/../ChurchSuiteOAuthClient.php';
require_once __DIR__ . '/../ChurchSuiteSyncService.php';
require_once __DIR__ . '/../ChurchSuiteDirectorySyncService.php';

class ChurchSuiteController {
    private function isRateLimitMessage($message) {
        $message = strtolower(trim((string)$message));
        return $message !== '' && strpos($message, 'rate limit') !== false;
    }

    private function isAuthorizationMessage($message) {
        $message = strtolower(trim((string)$message));
        return $message !== '' && (
            strpos($message, 'authorization has expired') !== false
            || strpos($message, 'rejected campo\'s api access') !== false
            || strpos($message, 'approval is required') !== false
            || strpos($message, 'unauthorized') !== false
        );
    }

    private function releaseSessionLock() {
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }
    }

    private function requireAuth() {
        if (!Auth::check()) {
            http_response_code(401);
            echo json_encode(['success' => false, 'message' => 'Unauthorized']);
            return false;
        }
        return true;
    }

    private function client() {
        return new ChurchSuiteOAuthClient();
    }

    private function loadCamp($campId) {
        $stmt = Database::connect()->prepare("SELECT * FROM camps WHERE id = ?");
        $stmt->execute([(int)$campId]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    private function eventReferenceForCamp(array $camp) {
        if (!empty($camp['churchsuite_event_id'])) {
            return trim((string)$camp['churchsuite_event_id']);
        }

        return trim((string)($camp['churchsuite_event_identifier'] ?? ''));
    }

    private function diagnosticStep($key, $label, callable $callback, ?callable $errorContextProvider = null) {
        try {
            $details = $callback();
            return [
                'key' => $key,
                'label' => $label,
                'ok' => true,
                'details' => is_array($details) ? $details : ['message' => (string)$details]
            ];
        } catch (Exception $e) {
            $details = ['message' => $e->getMessage()];
            if ($errorContextProvider) {
                try {
                    $extra = $errorContextProvider();
                    if (is_array($extra) && $extra) {
                        $details = array_merge($details, $extra);
                    }
                } catch (Exception $ignored) {
                    // Ignore diagnostics context failures and keep the primary error message.
                }
            }
            return [
                'key' => $key,
                'label' => $label,
                'ok' => false,
                'details' => $details
            ];
        }
    }

    private function redirectWithResult($returnTo, $status, $message = '') {
        $returnTo = trim((string)$returnTo);
        if ($returnTo === '' || strpos($returnTo, '/') !== 0) {
            $returnTo = '/camps';
        }

        $parts = parse_url($returnTo);
        $path = $parts['path'] ?? '/camps';
        $query = [];
        if (!empty($parts['query'])) {
            parse_str($parts['query'], $query);
        }
        $query['churchsuite'] = $status;
        if ($message !== '') {
            $query['churchsuite_message'] = $message;
        }

        $target = $path . '?' . http_build_query($query);
        if (!empty($parts['fragment'])) {
            $target .= '#' . $parts['fragment'];
        }

        header('Location: ' . $target, true, 302);
        exit;
    }

    public function startAuthorization() {
        if (!$this->requireAuth()) {
            return;
        }

        try {
            $state = bin2hex(random_bytes(16));
        } catch (Exception $e) {
            $state = bin2hex(openssl_random_pseudo_bytes(16));
        }

        $returnTo = trim((string)($_GET['return_to'] ?? '/camps'));
        $_SESSION['churchsuite_oauth_state'] = $state;
        $_SESSION['churchsuite_oauth_return_to'] = $returnTo !== '' ? $returnTo : '/camps';

        try {
            $url = $this->client()->buildAuthorizationUrl($state);
            header('Location: ' . $url, true, 302);
            exit;
        } catch (Exception $e) {
            $this->redirectWithResult($returnTo, 'error', $e->getMessage());
        }
    }

    public function disconnect() {
        if (!$this->requireAuth()) {
            return;
        }

        try {
            $this->client()->disconnect();
            echo json_encode(['success' => true]);
        } catch (Exception $e) {
            http_response_code(500);
            echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        }
    }

    public function status() {
        if (!$this->requireAuth()) {
            return;
        }

        echo json_encode($this->client()->getStatus());
    }

    public function events() {
        if (!$this->requireAuth()) {
            return;
        }

        $this->releaseSessionLock();

        $client = $this->client();
        $status = $client->getStatus();
        if (!$status['configured']) {
            http_response_code(503);
            echo json_encode([
                'success' => false,
                'message' => 'ChurchSuite is not configured.',
                'status' => $status
            ]);
            return;
        }

        $query = trim((string)($_GET['q'] ?? ''));
        $dateStart = trim((string)($_GET['date_start'] ?? ''));
        $dateEnd = trim((string)($_GET['date_end'] ?? ''));

        try {
            $response = $client->searchEvents($query, $dateStart !== '' ? $dateStart : null, $dateEnd !== '' ? $dateEnd : null, 1, 25);
            $events = array_map([$this, 'compactEvent'], $response['events'] ?? []);

            if ($query !== '' && count($events) === 0) {
                try {
                    $event = $client->getEvent($query);
                    if (!empty($event)) {
                        $events[] = $this->compactEvent($event);
                    }
                } catch (Exception $lookupError) {
                    // Ignore lookup failures here so normal empty search results still render.
                }
            }

            echo json_encode([
                'events' => $events
            ]);
        } catch (Exception $e) {
            error_log('ChurchSuite event search failed q=' . $query . ' error=' . $e->getMessage());
            http_response_code($this->isRateLimitMessage($e->getMessage()) ? 429 : 500);
            echo json_encode([
                'success' => false,
                'message' => $this->isRateLimitMessage($e->getMessage())
                    ? 'ChurchSuite rate limit hit. Wait a moment and try again.'
                    : 'Unable to search ChurchSuite events right now.'
            ]);
        }
    }

    public function syncCamp($campId) {
        if (!$this->requireAuth()) {
            return;
        }

        $this->releaseSessionLock();
        $payload = json_decode(file_get_contents('php://input'), true);
        $syncToken = trim((string)($payload['sync_token'] ?? ''));

        try {
            $service = new ChurchSuiteSyncService(Database::connect(), $this->client());
            $result = $service->syncCamp((int)$campId, $syncToken !== '' ? $syncToken : null);
            echo json_encode($result);
        } catch (Exception $e) {
            error_log('ChurchSuite sync failed campId=' . (int)$campId . ' error=' . $e->getMessage());
            http_response_code(
                $this->isRateLimitMessage($e->getMessage())
                    ? 429
                    : ($this->isAuthorizationMessage($e->getMessage()) ? 401 : 500)
            );
            echo json_encode([
                'success' => false,
                'message' => $e->getMessage()
            ]);
        }
    }

    public function syncDirectory() {
        if (!$this->requireAuth()) {
            return;
        }

        $this->releaseSessionLock();
        $payload = json_decode(file_get_contents('php://input'), true);
        $syncToken = trim((string)($payload['sync_token'] ?? ''));

        try {
            $service = new ChurchSuiteDirectorySyncService(Database::connect(), $this->client());
            $result = $service->sync($syncToken !== '' ? $syncToken : null);
            echo json_encode($result);
        } catch (Exception $e) {
            error_log('ChurchSuite directory sync failed error=' . $e->getMessage());
            http_response_code(
                $this->isRateLimitMessage($e->getMessage())
                    ? 429
                    : ($this->isAuthorizationMessage($e->getMessage()) ? 401 : 500)
            );
            echo json_encode([
                'success' => false,
                'message' => $e->getMessage()
            ]);
        }
    }

    public function diagnostics() {
        if (!$this->requireAuth()) {
            return;
        }

        $this->releaseSessionLock();
        $client = $this->client();
        $campId = isset($_GET['camp_id']) ? (int)$_GET['camp_id'] : 0;
        $camp = $campId > 0 ? $this->loadCamp($campId) : null;
        $steps = [];

        $status = $client->getStatus();
        $steps[] = [
            'key' => 'oauth_status',
            'label' => 'Stored OAuth status',
            'ok' => !empty($status['configured']) && !empty($status['authorized']),
            'details' => [
                'configured' => !empty($status['configured']),
                'authorized' => !empty($status['authorized']),
                'ready' => !empty($status['ready']),
                'has_refresh_token' => !empty($status['has_refresh_token']),
                'token_expires_at' => $status['token_expires_at'] ?? null,
                'issues' => $status['issues'] ?? []
            ]
        ];

        $errorContextProvider = function () use ($client) {
            if (method_exists($client, 'getLastErrorContext')) {
                $context = $client->getLastErrorContext();
                return is_array($context) ? $context : [];
            }
            return [];
        };

        $calendarStep = $this->diagnosticStep('calendar_events', 'Calendar API access', function () use ($client) {
            $response = $client->searchEvents('', null, null, 1, 1);
            $events = $response['events'] ?? [];
            $pagination = $response['pagination'] ?? [];

            return [
                'message' => 'Calendar API call succeeded.',
                'events_returned' => count($events),
                'num_results' => $pagination['num_results'] ?? null
            ];
        }, $errorContextProvider);
        $steps[] = $calendarStep;

        if ($camp) {
            $eventReference = $this->eventReferenceForCamp($camp);
            if ($eventReference === '') {
                $steps[] = [
                    'key' => 'camp_event_link',
                    'label' => 'Linked camp event',
                    'ok' => false,
                    'details' => ['message' => 'This camp is not linked to a ChurchSuite event.']
                ];
            } else {
                $eventStep = $this->diagnosticStep('camp_event', 'Linked camp event lookup', function () use ($client, $eventReference) {
                    $event = $client->getEvent($eventReference);
                    return [
                        'message' => 'Camp event lookup succeeded.',
                        'event_id' => $event['id'] ?? null,
                        'identifier' => $event['identifier'] ?? null,
                        'name' => $event['name'] ?? null
                    ];
                }, $errorContextProvider);
                $steps[] = $eventStep;

                if (!empty($eventStep['ok']) && !empty($eventStep['details']['event_id'])) {
                    $eventId = (int)$eventStep['details']['event_id'];
                    $steps[] = $this->diagnosticStep('event_signups', 'Event sign-ups access', function () use ($client, $eventId) {
                        $response = $client->listSignups($eventId, 1, 1, 'active');
                        $signups = $response['signups'] ?? [];
                        $pagination = $response['pagination'] ?? [];

                        return [
                            'message' => 'Event sign-up lookup succeeded.',
                            'signups_returned' => count($signups),
                            'num_results' => $pagination['num_results'] ?? null
                        ];
                    }, $errorContextProvider);
                }
            }
        }

        $failedSteps = array_values(array_filter($steps, function ($step) {
            return empty($step['ok']);
        }));
        $message = $failedSteps
            ? ($failedSteps[0]['details']['message'] ?? 'ChurchSuite diagnostics found a failing step.')
            : 'ChurchSuite diagnostics passed.';

        echo json_encode([
            'success' => !$failedSteps,
            'message' => $message,
            'camp' => $camp ? [
                'id' => (int)$camp['id'],
                'name' => $camp['name'] ?? null,
                'churchsuite_event_id' => $camp['churchsuite_event_id'] ?? null,
                'churchsuite_event_identifier' => $camp['churchsuite_event_identifier'] ?? null,
                'churchsuite_event_name' => $camp['churchsuite_event_name'] ?? null
            ] : null,
            'steps' => $steps
        ]);
    }

    public function oauthCallback() {
        $returnTo = $_SESSION['churchsuite_oauth_return_to'] ?? '/camps';
        $expectedState = $_SESSION['churchsuite_oauth_state'] ?? null;
        unset($_SESSION['churchsuite_oauth_return_to'], $_SESSION['churchsuite_oauth_state']);

        if (!empty($_GET['error'])) {
            $message = trim((string)($_GET['error_description'] ?? $_GET['error']));
            $this->redirectWithResult($returnTo, 'error', $message !== '' ? $message : 'ChurchSuite authorization was cancelled.');
        }

        $state = trim((string)($_GET['state'] ?? ''));
        if (!$expectedState || $state === '' || !hash_equals((string)$expectedState, $state)) {
            $this->redirectWithResult($returnTo, 'error', 'ChurchSuite authorization state did not match. Please try again.');
        }

        $code = trim((string)($_GET['code'] ?? ''));
        if ($code === '') {
            $this->redirectWithResult($returnTo, 'error', 'ChurchSuite did not return an authorization code.');
        }

        try {
            $this->client()->exchangeAuthorizationCode($code);
            $this->redirectWithResult($returnTo, 'connected', 'ChurchSuite is now connected to Campo.');
        } catch (Exception $e) {
            error_log('ChurchSuite OAuth callback failed: ' . $e->getMessage());
            $this->redirectWithResult($returnTo, 'error', $e->getMessage());
        }
    }

    private function compactEvent(array $event) {
        return [
            'id' => $event['id'] ?? null,
            'identifier' => $event['identifier'] ?? null,
            'name' => $event['name'] ?? 'Untitled event',
            'datetime_start' => $event['datetime_start'] ?? ($event['starts_at'] ?? null),
            'datetime_end' => $event['datetime_end'] ?? ($event['ends_at'] ?? null)
        ];
    }
}
