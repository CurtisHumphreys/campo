<?php

require_once __DIR__ . '/ChurchSuiteClientInterface.php';
require_once __DIR__ . '/ChurchSuiteTokenStore.php';
require_once __DIR__ . '/Database.php';

class ChurchSuiteOAuthClient implements ChurchSuiteClientInterface {
    private $config;
    private $tokenStore;
    private $accessToken = null;
    private $accessTokenExpiresAt = 0;
    private $accessTokenType = 'Bearer';
    private $lastErrorContext = null;

    public function __construct(?ChurchSuiteTokenStore $tokenStore = null) {
        $this->ensureConfigLoaded();
        $this->config = $this->buildConfig();
        $this->tokenStore = $tokenStore ?: new ChurchSuiteTokenStore(Database::connect());
    }

    private function ensureConfigLoaded() {
        static $loaded = false;

        if ($loaded) {
            return;
        }

        $configPath = __DIR__ . '/../config/config.php';
        if (file_exists($configPath)) {
            require_once $configPath;
        }

        $loaded = true;
    }

    public function getStatus() {
        $issues = $this->configurationIssues();

        $stored = $this->tokenStore->load();
        $metadata = $this->parseTokenMetadata($stored);
        $authorized = !empty($stored['access_token']) || !empty($stored['refresh_token']);
        $ready = false;
        $configured = count($issues) === 0;

        if ($configured && $authorized) {
            try {
                $this->fetchAccessToken();
                $ready = true;
            } catch (Exception $e) {
                $issues[] = $e->getMessage();
            }
        }

        return [
            'configured' => $configured,
            'authorized' => $authorized,
            'ready' => $configured && $ready,
            'connected' => $configured && $ready,
            'enabled' => $this->config['enabled'],
            'mode' => 'shared_server',
            'version' => 'v2',
            'auth_mode' => 'oauth2_authorization_code',
            'api_base' => $this->config['api_base'],
            'token_url' => $this->config['token_url'],
            'authorize_url' => $this->config['authorize_url'],
            'scope' => $this->config['scope'],
            'client_id_hint' => $this->maskClientId($this->config['client_id']),
            'redirect_uri' => $this->config['redirect_uri'],
            'connect_url' => '/api/churchsuite/oauth/start',
            'disconnect_url' => '/api/churchsuite/oauth/disconnect',
            'token_expires_at' => $stored['expires_at'] ?? null,
            'has_refresh_token' => !empty($stored['refresh_token']),
            'token_type' => $stored['token_type'] ?? ($metadata['token_type'] ?? null),
            'stored_scope' => $stored['scope'] ?? ($metadata['scope'] ?? null),
            'grant_type' => $metadata['grant_type'] ?? null,
            'issues' => $issues
        ];
    }

    public function getLastErrorContext() {
        return $this->lastErrorContext;
    }

    public function searchEvents($query = '', $dateStart = null, $dateEnd = null, $page = 1, $perPage = 25) {
        $page = max(1, (int)$page);
        $perPage = max(1, min(100, (int)$perPage));
        $query = trim((string)$query);
        $dateStart = $dateStart ? trim((string)$dateStart) : null;
        $dateEnd = $dateEnd ? trim((string)$dateEnd) : null;

        if ($query === '' && !$dateStart && !$dateEnd) {
            $payload = $this->request('/calendar/events', [
                'page' => $page,
                'per_page' => $perPage
            ]);

            return [
                'pagination' => $payload['pagination'] ?? [],
                'events' => $payload['data'] ?? []
            ];
        }

        $scanPage = 1;
        $maxScanPages = 20;
        $matches = [];

        while ($scanPage <= $maxScanPages) {
            $payload = $this->request('/calendar/events', [
                'page' => $scanPage,
                'per_page' => 50
            ]);

            $events = $payload['data'] ?? [];
            foreach ($events as $event) {
                if ($this->eventMatchesFilters($event, $query, $dateStart, $dateEnd)) {
                    $matches[] = $event;
                }
            }

            $pagination = $payload['pagination'] ?? [];
            if (empty($pagination['next_page']) || count($events) === 0) {
                break;
            }

            $scanPage++;
        }

        usort($matches, function ($a, $b) {
            $dateA = strtotime((string)($a['starts_at'] ?? $a['created_at'] ?? ''));
            $dateB = strtotime((string)($b['starts_at'] ?? $b['created_at'] ?? ''));
            $dateA = $dateA !== false ? $dateA : 0;
            $dateB = $dateB !== false ? $dateB : 0;
            if ($dateA === $dateB) {
                return (int)($b['id'] ?? 0) <=> (int)($a['id'] ?? 0);
            }
            return $dateB <=> $dateA;
        });

        $offset = ($page - 1) * $perPage;
        $slice = array_slice($matches, $offset, $perPage);
        $total = count($matches);

        return [
            'pagination' => [
                'num_results' => $total,
                'per_page' => $perPage,
                'page' => $page,
                'next_page' => ($offset + $perPage) < $total ? $page + 1 : null,
                'prev_page' => $page > 1 ? $page - 1 : null
            ],
            'events' => $slice
        ];
    }

    public function getEvent($reference) {
        $reference = trim((string)$reference);
        if ($reference === '') {
            throw new RuntimeException('ChurchSuite event reference is missing.');
        }

        $scanPage = 1;
        $maxScanPages = 20;
        $needle = strtolower($reference);

        while ($scanPage <= $maxScanPages) {
            $payload = $this->request('/calendar/events', [
                'page' => $scanPage,
                'per_page' => 50
            ]);

            foreach (($payload['data'] ?? []) as $event) {
                $eventId = (string)($event['id'] ?? '');
                $identifier = strtolower(trim((string)($event['identifier'] ?? '')));
                $name = strtolower(trim((string)($event['name'] ?? '')));

                if ($eventId === $reference || $identifier === $needle || $name === $needle) {
                    return $event;
                }
            }

            $pagination = $payload['pagination'] ?? [];
            if (empty($pagination['next_page'])) {
                break;
            }

            $scanPage++;
        }

        throw new RuntimeException('ChurchSuite event not found.');
    }

    public function listSignups($eventId, $page = 1, $perPage = 100, $status = null) {
        $query = [
            'event_id' => $eventId,
            'page' => max(1, (int)$page),
            'per_page' => max(1, min(100, (int)$perPage))
        ];
        $status = trim((string)$status);
        if ($status !== '') {
            $query['status'] = $status;
        }

        $payload = $this->request('/calendar/signups', $query);

        return [
            'pagination' => $payload['pagination'] ?? [],
            'signups' => $payload['data'] ?? []
        ];
    }

    public function listTickets($eventId, $page = 1, $perPage = 100) {
        $payload = $this->request('/calendar/tickets', [
            'event_id' => $eventId,
            'page' => max(1, (int)$page),
            'per_page' => max(1, min(100, (int)$perPage))
        ]);

        return [
            'pagination' => $payload['pagination'] ?? [],
            'tickets' => $payload['data'] ?? []
        ];
    }

    public function listContacts($page = 1, $perPage = 100) {
        return $this->listDirectoryRecords([
            '/addressbook/contacts',
            '/address-book/contacts',
            '/addressbook/people',
            '/address-book/people',
            '/contacts'
        ], $page, $perPage, 'contacts');
    }

    public function searchContacts($query, $perPage = 25) {
        $paths = [
            '/addressbook/contacts',
            '/address-book/contacts',
            '/addressbook/people',
        ];
        $q = ['q' => $query, 'per_page' => min(25, max(1, (int)$perPage)), 'page' => 1, 'show_hidden' => 1];
        foreach ($paths as $path) {
            try {
                $payload = $this->request($path, $q);
                $records = $payload['contacts'] ?? $payload['people'] ?? $payload['data'] ?? [];
                return is_array($records) ? $records : [];
            } catch (RuntimeException $e) {
                if ($this->isPathNotFoundMessage($e->getMessage())) continue;
                throw $e;
            }
        }
        return [];
    }

    public function fetchContact($id) {
        $paths = [
            '/addressbook/contacts/' . (int)$id,
            '/address-book/contacts/' . (int)$id,
        ];
        foreach ($paths as $path) {
            try {
                $payload = $this->request($path);
                return $payload['contact'] ?? $payload['person'] ?? $payload['data'] ?? $payload ?? null;
            } catch (RuntimeException $e) {
                if (strpos($e->getMessage(), '404') !== false || strpos($e->getMessage(), 'Not Found') !== false) {
                    continue;
                }
                throw $e;
            }
        }
        return null;
    }

    public function listChildren($page = 1, $perPage = 100) {
        return $this->listDirectoryRecords([
            '/children/children',
            '/children/people',
            '/children'
        ], $page, $perPage, 'children');
    }

    public function listParentCarerRelationships($page = 1, $perPage = 100) {
        $payload = $this->request('/children/parent_carer_relationships', [
            'page' => max(1, (int)$page),
            'per_page' => max(1, min(100, (int)$perPage))
        ]);

        return [
            'pagination' => $payload['pagination'] ?? [],
            'relationships' => $payload['data'] ?? []
        ];
    }

    public function buildAuthorizationUrl($state) {
        $issues = $this->configurationIssues();
        if ($issues) {
            throw new RuntimeException('ChurchSuite is not configured: ' . implode(' ', $issues));
        }

        $query = [
            'response_type' => 'code',
            'client_id' => $this->config['client_id'],
            'redirect_uri' => $this->config['redirect_uri'],
            'scope' => $this->config['scope'],
            'state' => $state
        ];

        return $this->config['authorize_url'] . '?' . http_build_query($query);
    }

    public function exchangeAuthorizationCode($code) {
        $code = trim((string)$code);
        if ($code === '') {
            throw new RuntimeException('ChurchSuite did not return an authorization code.');
        }

        $response = $this->requestToken([
            'grant_type' => 'authorization_code',
            'code' => $code,
            'redirect_uri' => $this->config['redirect_uri'],
            'client_id' => $this->config['client_id'],
            'client_secret' => $this->config['client_secret']
        ]);

        $this->tokenStore->save($response, 'authorization_code');
        $this->accessToken = trim((string)($response['access_token'] ?? ''));
        $this->accessTokenExpiresAt = isset($response['expires_in']) ? (time() + (int)$response['expires_in']) : 0;

        return $response;
    }

    public function disconnect() {
        $this->tokenStore->clear();
        $this->clearCachedAccessToken();
    }

    private function buildConfig() {
        $timeout = (int)$this->configValue('CHURCHSUITE_TIMEOUT_SECONDS', 20);
        if ($timeout <= 0) {
            $timeout = 20;
        }

        $appBaseUrl = rtrim($this->configValue('APP_BASE_URL'), '/');
        $redirectUri = $this->configValue('CHURCHSUITE_REDIRECT_URI');
        if ($redirectUri === '' && $appBaseUrl !== '') {
            $redirectUri = $appBaseUrl . '/api/churchsuite/oauth/callback';
        }

        return [
            'enabled' => $this->configBool('CHURCHSUITE_ENABLED', false),
            'api_base' => rtrim($this->configValue('CHURCHSUITE_API_BASE', 'https://api.churchsuite.com/v2'), '/'),
            'authorize_url' => $this->configValue('CHURCHSUITE_AUTHORIZE_URL', 'https://login.churchsuite.com/oauth2/authorize'),
            'token_url' => $this->configValue('CHURCHSUITE_TOKEN_URL', 'https://login.churchsuite.com/oauth2/token'),
            'client_id' => $this->configValue('CHURCHSUITE_CLIENT_ID'),
            'client_secret' => $this->configValue('CHURCHSUITE_CLIENT_SECRET'),
            'scope' => $this->configValue('CHURCHSUITE_OAUTH_SCOPE', 'full_access'),
            'redirect_uri' => $redirectUri,
            'timeout' => $timeout
        ];
    }

    private function configValue($name, $default = '') {
        if (!defined($name)) {
            return $default;
        }

        $value = constant($name);
        if (is_bool($value)) {
            return $value;
        }

        return trim((string)$value);
    }

    private function configBool($name, $default = false) {
        if (!defined($name)) {
            return $default;
        }

        $value = constant($name);
        if (is_bool($value)) {
            return $value;
        }

        $normalized = strtolower(trim((string)$value));
        return in_array($normalized, ['1', 'true', 'yes', 'on'], true);
    }

    private function eventMatchesFilters(array $event, $query, $dateStart, $dateEnd) {
        if ($query !== '') {
            $haystacks = [
                strtolower(trim((string)($event['name'] ?? ''))),
                strtolower(trim((string)($event['identifier'] ?? '')))
            ];

            $matched = false;
            foreach ($haystacks as $haystack) {
                if ($haystack !== '' && strpos($haystack, strtolower($query)) !== false) {
                    $matched = true;
                    break;
                }
            }

            if (!$matched) {
                return false;
            }
        }

        if ($dateStart || $dateEnd) {
            $startsAt = strtotime((string)($event['starts_at'] ?? ''));
            $endsAt = strtotime((string)($event['ends_at'] ?? ($event['starts_at'] ?? '')));
            if ($startsAt === false) {
                return false;
            }

            if ($dateStart) {
                $startBoundary = strtotime($dateStart . ' 00:00:00');
                if ($startBoundary !== false && $endsAt !== false && $endsAt < $startBoundary) {
                    return false;
                }
            }

            if ($dateEnd) {
                $endBoundary = strtotime($dateEnd . ' 23:59:59');
                if ($endBoundary !== false && $startsAt > $endBoundary) {
                    return false;
                }
            }
        }

        return true;
    }

    private function listDirectoryRecords($paths, $page, $perPage, $resultKey) {
        $query = [
            'page'     => max(1, (int)$page),
            'per_page' => max(1, min(100, (int)$perPage)),
        ];

        $paths = is_array($paths) ? array_values(array_filter($paths)) : [$paths];
        $lastError = null;

        foreach ($paths as $path) {
            try {
                $payload = $this->request($path, $query);
                $records = [];
                if (isset($payload[$resultKey]) && is_array($payload[$resultKey])) {
                    $records = $payload[$resultKey];
                } elseif (isset($payload['data']) && is_array($payload['data'])) {
                    $records = $payload['data'];
                } elseif (isset($payload['results']) && is_array($payload['results'])) {
                    $records = $payload['results'];
                }

                return [
                    'pagination' => $payload['pagination'] ?? [],
                    $resultKey => $records
                ];
            } catch (RuntimeException $e) {
                $lastError = $e;
                if ($this->isPathNotFoundMessage($e->getMessage())) {
                    error_log('ChurchSuite directory endpoint not found path=' . $path . ' message=' . $e->getMessage());
                    continue;
                }
                throw $e;
            }
        }

        if ($lastError) {
            throw $lastError;
        }

        throw new RuntimeException('ChurchSuite directory endpoint is unavailable.');
    }

    private function isPathNotFoundMessage($message) {
        $message = strtolower(trim((string)$message));
        if ($message === '') {
            return false;
        }

        return strpos($message, 'requested path was not found') !== false
            || strpos($message, 'path was not found') !== false
            || strpos($message, 'not found') === 0;
    }

    private function request($path, array $query = []) {
        $issues = $this->configurationIssues();
        if ($issues) {
            throw new RuntimeException('ChurchSuite is not configured: ' . implode(' ', $issues));
        }
        if (!function_exists('curl_init')) {
            throw new RuntimeException('cURL is required for ChurchSuite sync.');
        }

        $url = $this->config['api_base'] . $path;
        if ($query) {
            $url .= '?' . http_build_query($query);
        }

        $this->lastErrorContext = null;
        $maxAttempts = 3;
        $forceRefreshOnNextAttempt = false;
        $authorizationRetryUsed = false;

        for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
            $hasRefreshToken = $this->hasStoredRefreshToken();
            $responseHeaders = [];
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_CONNECTTIMEOUT => $this->config['timeout'],
                CURLOPT_TIMEOUT => $this->config['timeout'],
                CURLOPT_HTTPHEADER => [
                    'Accept: application/json',
                    'Authorization: ' . $this->fetchAuthorizationHeader($forceRefreshOnNextAttempt)
                ],
                CURLOPT_HEADERFUNCTION => function ($ch, $header) use (&$responseHeaders) {
                    $length = strlen($header);
                    $parts = explode(':', $header, 2);
                    if (count($parts) === 2) {
                        $responseHeaders[strtolower(trim($parts[0]))] = trim($parts[1]);
                    }
                    return $length;
                }
            ]);
            $forceRefreshOnNextAttempt = false;

            $body = curl_exec($ch);
            $curlError = curl_error($ch);
            $statusCode = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
            curl_close($ch);

            if ($body === false) {
                $this->lastErrorContext = [
                    'request_url' => $url,
                    'status_code' => $statusCode ?: null,
                    'curl_error' => $curlError ?: null
                ];
                throw new RuntimeException('ChurchSuite request failed: ' . ($curlError ?: 'Unknown cURL error.'));
            }

            $decoded = json_decode($body, true);

            if ($statusCode === 401) {
                $this->lastErrorContext = $this->buildErrorContext($url, $statusCode, $responseHeaders, $decoded, $body);
                if (!$authorizationRetryUsed && $hasRefreshToken) {
                    $authorizationRetryUsed = true;
                    $this->clearCachedAccessToken();
                    $forceRefreshOnNextAttempt = true;
                    continue;
                }

                throw new RuntimeException($this->authorizationRejectedMessage());
            }

            if ($statusCode === 429) {
                $this->lastErrorContext = $this->buildErrorContext($url, $statusCode, $responseHeaders, $decoded, $body);
                $delaySeconds = $this->rateLimitDelaySeconds($responseHeaders, $attempt);
                if ($attempt < $maxAttempts && $delaySeconds <= 1) {
                    usleep($delaySeconds * 1000000);
                    continue;
                }
                throw new RuntimeException($this->buildRequestErrorMessage($statusCode, $decoded, $body, $responseHeaders));
            }

            if ($statusCode >= 500 && $attempt < $maxAttempts) {
                $this->lastErrorContext = $this->buildErrorContext($url, $statusCode, $responseHeaders, $decoded, $body);
                usleep(300000);
                continue;
            }

            if ($statusCode >= 400) {
                $this->lastErrorContext = $this->buildErrorContext($url, $statusCode, $responseHeaders, $decoded, $body);
                throw new RuntimeException($this->buildRequestErrorMessage($statusCode, $decoded, $body, $responseHeaders));
            }

            if (!is_array($decoded)) {
                $this->lastErrorContext = $this->buildErrorContext($url, $statusCode, $responseHeaders, $decoded, $body);
                throw new RuntimeException('ChurchSuite returned an unexpected response.');
            }

            $this->lastErrorContext = null;
            return $decoded;
        }

        throw new RuntimeException('ChurchSuite sync failed after multiple retries.');
    }

    private function rateLimitDelaySeconds(array $headers, $attempt) {
        $retryAfter = isset($headers['retry-after']) ? (int)$headers['retry-after'] : 0;
        if ($retryAfter > 0) {
            return min(max($retryAfter, 1), 3);
        }

        return 1;
    }

    private function buildRequestErrorMessage($statusCode, $decoded, $body, array $headers = []) {
        if ((int)$statusCode === 429) {
            $retryAfter = isset($headers['retry-after']) ? (int)$headers['retry-after'] : 0;
            if ($retryAfter > 0) {
                return 'ChurchSuite rate limit hit. Wait about ' . $retryAfter . ' seconds and try again.';
            }
            return 'ChurchSuite rate limit hit. Wait a moment and try again.';
        }

        $message = 'ChurchSuite returned HTTP ' . (int)$statusCode . '.';
        if (is_array($decoded)) {
            $message = $decoded['error']['message'] ?? $decoded['message'] ?? $decoded['error'] ?? $message;
        } elseif (trim($body) !== '') {
            $message = trim($body);
        }

        return $message;
    }

    private function fetchAuthorizationHeader($forceRefresh = false) {
        $token = $this->fetchAccessToken($forceRefresh);
        $type = trim((string)$this->accessTokenType);
        if ($type === '') {
            $type = 'Bearer';
        }
        return $type . ' ' . $token;
    }

    private function fetchAccessToken($forceRefresh = false) {
        $now = time();
        if (!$forceRefresh && $this->accessToken && $this->accessTokenExpiresAt > ($now + 60)) {
            return $this->accessToken;
        }

        $issues = $this->configurationIssues();
        if ($issues) {
            throw new RuntimeException('ChurchSuite is not configured: ' . implode(' ', $issues));
        }

        $stored = $this->tokenStore->load();
        if (!$stored) {
            throw new RuntimeException($this->authorizationExpiredMessage());
        }

        $this->accessTokenType = trim((string)($stored['token_type'] ?? 'Bearer')) ?: 'Bearer';
        $storedAccessToken = trim((string)($stored['access_token'] ?? ''));
        $expiresAt = !empty($stored['expires_at']) ? strtotime((string)$stored['expires_at']) : 0;
        if (
            !$forceRefresh &&
            $storedAccessToken !== '' &&
            ($expiresAt === false || $expiresAt === 0 || $expiresAt > ($now + 60))
        ) {
            $this->accessToken = $storedAccessToken;
            $this->accessTokenExpiresAt = $expiresAt !== false ? (int)$expiresAt : 0;
            return $this->accessToken;
        }

        $refreshToken = trim((string)($stored['refresh_token'] ?? ''));
        if ($refreshToken !== '') {
            try {
            $response = $this->requestToken([
                    'grant_type' => 'refresh_token',
                    'refresh_token' => $refreshToken,
                    'client_id' => $this->config['client_id'],
                    'client_secret' => $this->config['client_secret']
                ]);
            } catch (RuntimeException $e) {
                if ($this->isAuthorizationErrorMessage($e->getMessage())) {
                    $this->forgetAuthorization();
                    throw new RuntimeException($this->authorizationExpiredMessage());
                }
                throw $e;
            }
            $this->tokenStore->save($response, 'refresh_token');
            $this->accessToken = trim((string)($response['access_token'] ?? ''));
            $this->accessTokenExpiresAt = isset($response['expires_in']) ? (time() + (int)$response['expires_in']) : 0;
            $this->accessTokenType = trim((string)($response['token_type'] ?? $this->accessTokenType)) ?: 'Bearer';
            if ($this->accessToken !== '') {
                return $this->accessToken;
            }
        }

        $this->forgetAuthorization();
        throw new RuntimeException($this->authorizationExpiredMessage());
    }

    private function requestToken(array $payload) {
        $issues = $this->configurationIssues();
        if ($issues) {
            throw new RuntimeException('ChurchSuite is not configured: ' . implode(' ', $issues));
        }

        $ch = curl_init($this->config['token_url']);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query($payload),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => $this->config['timeout'],
            CURLOPT_TIMEOUT => $this->config['timeout'],
            CURLOPT_HTTPHEADER => [
                'Accept: application/json',
                'Content-Type: application/x-www-form-urlencoded'
            ]
        ]);

        $body = curl_exec($ch);
        $curlError = curl_error($ch);
        $statusCode = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);

        if ($body === false) {
            throw new RuntimeException('ChurchSuite token request failed: ' . ($curlError ?: 'Unknown cURL error.'));
        }

        $decoded = json_decode($body, true);
        if ($statusCode >= 400) {
            $message = 'ChurchSuite token endpoint returned HTTP ' . $statusCode . '.';
            if (is_array($decoded)) {
                $message = $decoded['error_description'] ?? $decoded['message'] ?? $decoded['error'] ?? $message;
            } elseif (trim($body) !== '') {
                $message = trim($body);
            }
            if ($this->isAuthorizationErrorMessage($message)) {
                $message = $this->authorizationExpiredMessage();
            }
            throw new RuntimeException($message);
        }

        if (!is_array($decoded)) {
            throw new RuntimeException('ChurchSuite token endpoint returned an unexpected response.');
        }

        $accessToken = trim((string)($decoded['access_token'] ?? ''));
        if ($accessToken === '') {
            throw new RuntimeException('ChurchSuite token endpoint did not return an access token.');
        }

        return $decoded;
    }

    private function configurationIssues() {
        $issues = [];

        if (!$this->config['enabled']) {
            $issues[] = 'CHURCHSUITE_ENABLED is false.';
        }
        if ($this->config['api_base'] === '') {
            $issues[] = 'CHURCHSUITE_API_BASE is missing.';
        }
        if ($this->config['token_url'] === '') {
            $issues[] = 'CHURCHSUITE_TOKEN_URL is missing.';
        }
        if ($this->config['authorize_url'] === '') {
            $issues[] = 'CHURCHSUITE_AUTHORIZE_URL is missing.';
        }
        if ($this->config['client_id'] === '') {
            $issues[] = 'CHURCHSUITE_CLIENT_ID is missing.';
        }
        if ($this->config['client_secret'] === '') {
            $issues[] = 'CHURCHSUITE_CLIENT_SECRET is missing.';
        }
        if ($this->config['scope'] === '') {
            $issues[] = 'CHURCHSUITE_OAUTH_SCOPE is missing.';
        }
        if ($this->config['timeout'] <= 0) {
            $issues[] = 'CHURCHSUITE_TIMEOUT_SECONDS must be positive.';
        }

        return $issues;
    }

    private function maskClientId($clientId) {
        $clientId = trim((string)$clientId);
        if ($clientId === '') {
            return '';
        }
        if (strlen($clientId) <= 6) {
            return str_repeat('*', strlen($clientId));
        }
        return substr($clientId, 0, 4) . str_repeat('*', max(0, strlen($clientId) - 8)) . substr($clientId, -4);
    }

    private function clearCachedAccessToken() {
        $this->accessToken = null;
        $this->accessTokenExpiresAt = 0;
        $this->accessTokenType = 'Bearer';
    }

    private function forgetAuthorization() {
        $this->clearCachedAccessToken();
        $this->tokenStore->clear();
    }

    private function authorizationExpiredMessage() {
        return 'ChurchSuite authorization has expired. Reconnect Campo to ChurchSuite and try again.';
    }

    private function authorizationRejectedMessage() {
        return 'ChurchSuite rejected Campo\'s API access for this approval. Reconnect ChurchSuite and, if it still fails, check the OAuth app and authorising user permissions in ChurchSuite.';
    }

    private function isAuthorizationErrorMessage($message) {
        $message = strtolower(trim((string)$message));
        if ($message === '') {
            return false;
        }

        return strpos($message, 'unauthorized') !== false
            || strpos($message, 'invalid_grant') !== false
            || strpos($message, 'invalid grant') !== false
            || strpos($message, 'invalid_token') !== false
            || strpos($message, 'invalid token') !== false
            || strpos($message, 'expired') !== false
            || strpos($message, 'approval is required') !== false
            || strpos($message, 'authorization has expired') !== false;
    }

    private function hasStoredRefreshToken() {
        $stored = $this->tokenStore->load();
        return trim((string)($stored['refresh_token'] ?? '')) !== '';
    }

    private function parseTokenMetadata($stored) {
        if (!is_array($stored)) {
            return [];
        }

        $raw = $stored['metadata'] ?? '';
        if (!is_string($raw) || trim($raw) === '') {
            return [];
        }

        $decoded = json_decode($raw, true);
        return is_array($decoded) ? $decoded : [];
    }

    private function buildErrorContext($url, $statusCode, array $headers, $decoded, $body) {
        $excerpt = '';
        if (is_array($decoded)) {
            $excerpt = json_encode($decoded, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        } else {
            $excerpt = trim((string)$body);
        }
        if (strlen($excerpt) > 500) {
            $excerpt = substr($excerpt, 0, 500) . '...';
        }

        return [
            'request_url' => $url,
            'status_code' => (int)$statusCode,
            'retry_after' => isset($headers['retry-after']) ? $headers['retry-after'] : null,
            'response_excerpt' => $excerpt !== '' ? $excerpt : null
        ];
    }
}
