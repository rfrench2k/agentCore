<?php
/**
 * AgentCore — Web Auth Gate
 *
 * Shared access-control check for every web/ endpoint. Behavior is driven by
 * config.web.auth_mode:
 *
 *   local — REMOTE_ADDR must be 127.0.0.1, ::1, or 192.168.* (LAN-only). The
 *           default. Note: REMOTE_ADDR is the immediate peer; behind a reverse
 *           proxy this will always be the proxy, so this mode is only safe
 *           when the web server is NOT proxied.
 *
 *   token — Require ?token=<shared-secret>, an X-AgentCore-Token header, or an
 *           Authorization: Bearer <secret> header. config.web.token must be set.
 *           Suitable when the UI is reachable from outside the LAN.
 *
 *   open  — No auth. Only safe if the web/ directory is behind your own auth
 *           layer (HTTP basic, OAuth proxy, VPN-only). Never use this when the
 *           endpoint is reachable from the public internet.
 *
 * Calls exit() if the request is not allowed. Include this from any endpoint
 * that should be access-controlled.
 */

require_once __DIR__ . '/../src/AgentCore.php';

(function () {
    $core = AgentCore::getInstance();
    $mode = $core->config('web.auth_mode') ?: 'local';

    $deny = function (int $code, string $msg): void {
        http_response_code($code);
        // Respond in whatever shape the caller probably wants — JSON for the API,
        // plain text for HTML pages. Sniff via Accept header.
        $accept = $_SERVER['HTTP_ACCEPT'] ?? '';
        if (stripos($accept, 'application/json') !== false) {
            header('Content-Type: application/json');
            echo json_encode(['ok' => false, 'error' => $msg]);
        } else {
            header('Content-Type: text/plain; charset=utf-8');
            echo $msg;
        }
        exit;
    };

    if ($mode === 'open') {
        return;
    }

    if ($mode === 'local') {
        $remoteIP = $_SERVER['REMOTE_ADDR'] ?? '';
        $isLocal = in_array($remoteIP, ['127.0.0.1', '::1', ''], true)
            || str_starts_with($remoteIP, '192.168.')
            || str_starts_with($remoteIP, '10.')
            || preg_match('/^172\.(1[6-9]|2[0-9]|3[0-1])\./', $remoteIP);
        if (!$isLocal) {
            $deny(403, 'Access denied: local network only');
        }
        return;
    }

    if ($mode === 'token') {
        $expected = (string)$core->config('web.token');
        if ($expected === '') {
            $deny(500, 'Server misconfigured: auth_mode=token but no web.token set');
        }
        $provided = $_GET['token']
            ?? $_SERVER['HTTP_X_AGENTCORE_TOKEN']
            ?? '';
        if ($provided === '' && !empty($_SERVER['HTTP_AUTHORIZATION'])) {
            if (preg_match('/Bearer\s+(\S+)/i', $_SERVER['HTTP_AUTHORIZATION'], $m)) {
                $provided = $m[1];
            }
        }
        if (!hash_equals($expected, (string)$provided)) {
            $deny(401, 'Access denied: invalid or missing token');
        }
        return;
    }

    $deny(500, "Server misconfigured: unknown web.auth_mode '{$mode}'");
})();
