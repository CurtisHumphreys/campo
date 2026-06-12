<?php

require_once __DIR__ . '/../vendor/phpmailer/phpmailer/src/Exception.php';
require_once __DIR__ . '/../vendor/phpmailer/phpmailer/src/PHPMailer.php';
require_once __DIR__ . '/../vendor/phpmailer/phpmailer/src/SMTP.php';

use PHPMailer\PHPMailer\Exception;
use PHPMailer\PHPMailer\PHPMailer;

class CampoMailer {
    private static function configValue($name, $default = '') {
        return defined($name) ? trim((string)constant($name)) : $default;
    }

    private static function normaliseTransport($value) {
        $value = strtolower(trim((string)$value));
        return $value === '' ? 'smtp' : $value;
    }

    private static function normaliseEncryption($value) {
        $value = strtolower(trim((string)$value));
        if ($value === 'starttls') return 'tls';
        return in_array($value, ['', 'tls', 'ssl'], true) ? $value : '';
    }

    public static function config($server = []) {
        $port = (int)self::configValue('MAIL_PORT', '587');
        if ($port <= 0) $port = 587;

        return [
            'transport' => self::normaliseTransport(self::configValue('MAIL_TRANSPORT', 'smtp')),
            'host' => self::configValue('MAIL_HOST'),
            'port' => $port,
            'encryption' => self::normaliseEncryption(self::configValue('MAIL_ENCRYPTION', 'tls')),
            'username' => self::configValue('MAIL_USERNAME'),
            'password' => defined('MAIL_PASSWORD') ? (string)constant('MAIL_PASSWORD') : '',
            'from_name' => self::configValue('MAIL_FROM_NAME', 'Campo'),
            'from_email' => self::configValue('MAIL_FROM_EMAIL'),
            'app_base_url' => self::appBaseUrl($server)
        ];
    }

    public static function appBaseUrl($server = []) {
        $configured = self::configValue('APP_BASE_URL');
        if ($configured !== '') return rtrim($configured, '/');

        $forwardedProto = strtolower((string)($server['HTTP_X_FORWARDED_PROTO'] ?? ''));
        $https = (!empty($server['HTTPS']) && $server['HTTPS'] !== 'off')
            || ((int)($server['SERVER_PORT'] ?? 0) === 443)
            || $forwardedProto === 'https';
        $scheme = $https ? 'https' : 'http';
        $host = $server['HTTP_HOST'] ?? 'localhost';
        return $scheme . '://' . $host;
    }

    public static function status($server = []) {
        $config = self::config($server);
        $issues = [];

        if ($config['transport'] !== 'smtp') {
            $issues[] = 'MAIL_TRANSPORT must be set to smtp.';
        }
        if ($config['host'] === '') {
            $issues[] = 'MAIL_HOST is missing.';
        }
        if ($config['port'] <= 0) {
            $issues[] = 'MAIL_PORT must be a positive number.';
        }
        if ($config['from_email'] === '' || !filter_var($config['from_email'], FILTER_VALIDATE_EMAIL)) {
            $issues[] = 'MAIL_FROM_EMAIL must be a valid email address.';
        }
        if ($config['password'] !== '' && $config['username'] === '') {
            $issues[] = 'MAIL_USERNAME is required when MAIL_PASSWORD is set.';
        }

        $configured = count($issues) === 0;
        $hostLabel = $config['host'] !== ''
            ? $config['host'] . ':' . $config['port'] . ($config['encryption'] !== '' ? " ({$config['encryption']})" : '')
            : 'Not set';
        $fromLabel = $config['from_email'] !== ''
            ? trim(($config['from_name'] !== '' ? $config['from_name'] . ' ' : '') . '<' . $config['from_email'] . '>')
            : 'Not set';

        return [
            'transport' => $config['transport'],
            'transport_label' => strtoupper($config['transport']),
            'configured' => $configured,
            'summary' => $configured
                ? 'SMTP is configured for new intranet submission emails.'
                : 'SMTP settings need attention before notification emails can be sent.',
            'from_name' => $config['from_name'],
            'from_email' => $config['from_email'],
            'from_label' => $fromLabel,
            'host' => $config['host'],
            'port' => $config['port'],
            'encryption' => $config['encryption'],
            'host_label' => $hostLabel,
            'app_base_url' => $config['app_base_url'],
            'issues' => $issues
        ];
    }

    public static function configFromDb(PDO $db, $server = []) {
        $rows = [];
        try {
            $stmt = $db->query("SELECT setting_key, setting_value FROM app_settings WHERE setting_key LIKE 'mail_%' OR setting_key = 'app_base_url'");
            if ($stmt) {
                $rows = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
            }
        } catch (Throwable $e) {}

        $base = self::config($server);

        $stringMap = [
            'mail_host'       => 'host',
            'mail_username'   => 'username',
            'mail_password'   => 'password',
            'mail_from_name'  => 'from_name',
            'mail_from_email' => 'from_email',
        ];
        foreach ($stringMap as $key => $field) {
            if (isset($rows[$key])) $base[$field] = $rows[$key];
        }
        if (isset($rows['mail_port'])) {
            $port = (int)$rows['mail_port'];
            if ($port > 0) $base['port'] = $port;
        }
        if (isset($rows['mail_encryption'])) {
            $base['encryption'] = self::normaliseEncryption($rows['mail_encryption']);
        }
        if (!empty($rows['app_base_url'])) {
            $base['app_base_url'] = rtrim(trim($rows['app_base_url']), '/');
        }

        return $base;
    }

    public static function statusFromConfig(array $config) {
        $issues = [];
        if ($config['transport'] !== 'smtp')          $issues[] = 'MAIL_TRANSPORT must be smtp.';
        if ($config['host'] === '')                    $issues[] = 'Mail host is not set.';
        if ($config['port'] <= 0)                      $issues[] = 'Mail port must be a positive number.';
        if ($config['from_email'] === '' || !filter_var($config['from_email'], FILTER_VALIDATE_EMAIL))
                                                       $issues[] = 'From address is missing or invalid.';
        if ($config['password'] !== '' && $config['username'] === '')
                                                       $issues[] = 'Username is required when a password is set.';

        $configured = count($issues) === 0;
        $hostLabel  = $config['host'] !== ''
            ? $config['host'] . ':' . $config['port'] . ($config['encryption'] !== '' ? " ({$config['encryption']})" : '')
            : 'Not set';
        $fromLabel  = $config['from_email'] !== ''
            ? trim(($config['from_name'] !== '' ? $config['from_name'] . ' ' : '') . '<' . $config['from_email'] . '>')
            : 'Not set';

        return [
            'configured'  => $configured,
            'issues'      => $issues,
            'host_label'  => $hostLabel,
            'from_label'  => $fromLabel,
            'app_base_url'=> $config['app_base_url'],
        ];
    }

    public static function sendTextWithConfig($to, $subject, $body, array $config) {
        $to = trim((string)$to);
        if ($to === '' || !filter_var($to, FILTER_VALIDATE_EMAIL)) {
            throw new RuntimeException('Recipient email address is not valid.');
        }
        if ($config['host'] === '') {
            throw new RuntimeException('SMTP is not configured: mail host is missing.');
        }
        if ($config['from_email'] === '' || !filter_var($config['from_email'], FILTER_VALIDATE_EMAIL)) {
            throw new RuntimeException('SMTP is not configured: from address is missing or invalid.');
        }

        try {
            $mail = new PHPMailer(true);
            $mail->isSMTP();
            $mail->Host        = $config['host'];
            $mail->Port        = $config['port'];
            $mail->Timeout     = 20;
            $mail->CharSet     = 'UTF-8';
            $mail->Encoding    = 'base64';
            $mail->SMTPAutoTLS = $config['encryption'] !== '';

            if ($config['username'] !== '') {
                $mail->SMTPAuth = true;
                $mail->Username = $config['username'];
                $mail->Password = $config['password'];
            } else {
                $mail->SMTPAuth = false;
            }

            if ($config['encryption'] === 'ssl') {
                $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
            } elseif ($config['encryption'] === 'tls') {
                $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
            }

            $mail->setFrom($config['from_email'], $config['from_name']);
            $mail->addAddress($to);
            $mail->Subject = $subject;
            $mail->Body    = $body;
            $mail->AltBody = $body;
            $mail->isHTML(false);
            $mail->send();
            return true;
        } catch (Exception $e) {
            throw new RuntimeException('SMTP send failed: ' . $e->getMessage());
        }
    }

    public static function sendText($to, $subject, $body, $server = []) {
        $to = trim((string)$to);
        if ($to === '' || !filter_var($to, FILTER_VALIDATE_EMAIL)) {
            throw new RuntimeException('Recipient email address is not valid.');
        }

        $config = self::config($server);
        $status = self::status($server);
        if ($status['transport'] !== 'smtp') {
            throw new RuntimeException('Notification mail transport is not set to SMTP.');
        }
        if (!$status['configured']) {
            throw new RuntimeException('SMTP is not configured: ' . implode(' ', $status['issues']));
        }

        try {
            $mail = new PHPMailer(true);
            $mail->isSMTP();
            $mail->Host = $config['host'];
            $mail->Port = $config['port'];
            $mail->Timeout = 20;
            $mail->CharSet = 'UTF-8';
            $mail->Encoding = 'base64';
            $mail->SMTPAutoTLS = $config['encryption'] !== '';

            if ($config['username'] !== '') {
                $mail->SMTPAuth = true;
                $mail->Username = $config['username'];
                $mail->Password = $config['password'];
            } else {
                $mail->SMTPAuth = false;
            }

            if ($config['encryption'] === 'ssl') {
                $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
            } elseif ($config['encryption'] === 'tls') {
                $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
            }

            $mail->setFrom($config['from_email'], $config['from_name']);
            $mail->addAddress($to);
            $mail->Subject = $subject;
            $mail->Body = $body;
            $mail->AltBody = $body;
            $mail->isHTML(false);
            $mail->send();
            return true;
        } catch (Exception $e) {
            throw new RuntimeException('SMTP send failed: ' . $e->getMessage());
        }
    }
}
