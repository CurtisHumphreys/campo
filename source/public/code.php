<?php
// CampOffice — AI Code Assistant
// Opens a Claude Code terminal session pre-loaded with the feature request context.

$appDir = '/opt/forgebox/apps/campoffice/php';
require_once $appDir . '/config/config.php';

spl_autoload_register(function ($class) use ($appDir) {
    $paths = [
        $appDir . '/src/' . $class . '.php',
        $appDir . '/src/Controllers/' . $class . '.php',
    ];
    foreach ($paths as $p) { if (file_exists($p)) { require_once $p; return; } }
});

if (session_status() !== PHP_SESSION_ACTIVE) session_start();

if (empty($_SESSION['user_id'])) {
    header('Location: /');
    exit;
}

$id      = (int)($_GET['id'] ?? 0);
$request = null;

if ($id > 0) {
    $db   = Database::connect();
    $stmt = $db->prepare("SELECT * FROM feature_requests WHERE id = ? LIMIT 1");
    $stmt->execute([$id]);
    $request = $stmt->fetch(PDO::FETCH_ASSOC);
}

$typeLabel   = ($request['type'] ?? '') === 'bug' ? 'Bug Fix' : 'Feature Request';
$title       = htmlspecialchars($request['title']       ?? 'Unknown request', ENT_QUOTES);
$description = htmlspecialchars($request['description'] ?? '', ENT_QUOTES);

// Build the initial prompt that will be auto-typed into Claude
$promptParts = [];
$promptParts[] = "I have a " . (($request['type'] ?? 'feature') === 'bug' ? 'bug report' : 'feature request') . " from the CampOffice admin panel:";
$promptParts[] = "";
$promptParts[] = "**Title:** " . ($request['title'] ?? 'Unknown');
if (!empty($request['description'])) {
    $promptParts[] = "**Description:** " . $request['description'];
}
if (!empty($request['submitter'])) {
    $promptParts[] = "**Submitted by:** " . $request['submitter'];
}
$promptParts[] = "";
$promptParts[] = "CampOffice is a Vue 3 SPA (Vite) + PHP 8.4 flat router app at /opt/forgebox/apps/campoffice/. Please review this request and ask any clarifying questions you need before implementing.";

$initialPrompt = implode("\n", $promptParts);
$initialPromptJson = json_encode($initialPrompt);
$requestId = (int)($request['id'] ?? 0);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Code Assistant — <?= $title ?></title>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/xterm@5.3.0/css/xterm.min.css">
<style>
*, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
html, body { height: 100%; }
body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif; background: #0d1117; color: #c9d1d9; display: flex; flex-direction: column; height: 100vh; overflow: hidden; }

.top-bar {
    background: #161b22;
    border-bottom: 1px solid #21262d;
    padding: 10px 16px;
    display: flex;
    align-items: center;
    gap: 10px;
    flex-none;
    flex-shrink: 0;
}
.back-link { color: #8b949e; text-decoration: none; font-size: 13px; flex-none; }
.back-link:hover { color: #c9d1d9; }
.request-badge { font-size: 11px; font-weight: 600; padding: 2px 8px; border-radius: 20px; flex-none; }
.badge-feature { background: rgba(56,189,248,.15); color: #38bdf8; }
.badge-bug     { background: rgba(248,81,73,.15);  color: #f85149; }
.request-title { font-size: 14px; font-weight: 600; color: #f0f6fc; flex: 1; min-width: 0; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.status-dot { width: 8px; height: 8px; border-radius: 50%; background: #484f58; flex-none; transition: background .3s; }
.status-dot.connected { background: #3fb950; }
.status-dot.error     { background: #f85149; }
.status-label { font-size: 12px; color: #8b949e; flex-none; }
.complete-btn { background: #238636; color: #fff; border: none; border-radius: 6px; padding: 5px 12px; font-size: 12px; font-weight: 600; cursor: pointer; flex-none; }
.complete-btn:hover { background: #2ea043; }
.reconnect-btn { background: none; border: 1px solid #30363d; color: #8b949e; border-radius: 6px; padding: 4px 10px; font-size: 12px; cursor: pointer; flex-none; }
.reconnect-btn:hover { color: #c9d1d9; border-color: #484f58; }

#terminal-container {
    flex: 1;
    overflow: hidden;
    padding: 8px;
    background: #0d0d0d;
}
.xterm { height: 100%; }
.xterm-viewport { overflow-y: auto !important; }
</style>
</head>
<body>

<div class="top-bar">
  <a href="/feature-requests" class="back-link">← Back</a>
  <span class="request-badge <?= ($request['type'] ?? '') === 'bug' ? 'badge-bug' : 'badge-feature' ?>">
    <?= $typeLabel ?>
  </span>
  <span class="request-title"><?= $title ?></span>
  <span class="status-dot" id="status-dot"></span>
  <span class="status-label" id="status-label">Connecting…</span>
  <?php if ($request && ($request['status'] ?? '') !== 'completed'): ?>
  <button class="complete-btn" onclick="markComplete()">✓ Mark Complete</button>
  <?php endif; ?>
  <button class="reconnect-btn" onclick="reconnect()">Reconnect</button>
</div>

<div id="terminal-container"></div>

<script src="https://cdn.jsdelivr.net/npm/xterm@5.3.0/lib/xterm.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/xterm-addon-fit@0.8.0/lib/xterm-addon-fit.min.js"></script>
<script>
const REQUEST_ID     = <?= $requestId ?>;
const INITIAL_PROMPT = <?= $initialPromptJson ?>;
const WS_URL = `${location.protocol === 'https:' ? 'wss' : 'ws'}://${location.host}/ws/claude-code`;

const term = new Terminal({
    cursorBlink: true,
    fontSize: 14,
    fontFamily: '"Cascadia Code", "Fira Code", "JetBrains Mono", monospace',
    theme: {
        background:    '#0d0d0d',
        foreground:    '#e6edf3',
        cursor:        '#e6edf3',
        black:         '#0d0d0d',
        red:           '#f85149',
        green:         '#3fb950',
        yellow:        '#d29922',
        blue:          '#58a6ff',
        magenta:       '#bc8cff',
        cyan:          '#39d1bd',
        white:         '#e6edf3',
        brightBlack:   '#6e7681',
        brightRed:     '#ff7b72',
        brightGreen:   '#56d364',
        brightYellow:  '#e3b341',
        brightBlue:    '#79c0ff',
        brightMagenta: '#d2a8ff',
        brightCyan:    '#56d4dd',
        brightWhite:   '#f0f6fc',
    },
    scrollback: 5000,
    allowProposedApi: true,
});

const fitAddon = new FitAddon.FitAddon();
term.loadAddon(fitAddon);
term.open(document.getElementById('terminal-container'));
fitAddon.fit();

let ws = null;
let promptSent = false;

function setStatus(state) {
    document.getElementById('status-dot').className   = 'status-dot ' + state;
    document.getElementById('status-label').textContent = { connected: 'Connected', error: 'Disconnected' }[state] ?? 'Connecting…';
}

function connect() {
    if (ws && ws.readyState < 2) ws.close();
    promptSent = false;

    ws = new WebSocket(WS_URL);
    ws.binaryType = 'arraybuffer';

    ws.onopen = () => {
        setStatus('connected');
        ws.send(JSON.stringify({ type: 'resize', rows: term.rows, cols: term.cols }));
        term.focus();

        // Wait for Claude to finish starting up, then send the initial prompt
        if (INITIAL_PROMPT && REQUEST_ID && !promptSent) {
            setTimeout(() => {
                if (ws && ws.readyState === WebSocket.OPEN && !promptSent) {
                    promptSent = true;
                    ws.send(INITIAL_PROMPT + '\n');
                }
            }, 3000);
        }
    };

    ws.onmessage = (e) => {
        const data = typeof e.data === 'string' ? e.data : new TextDecoder().decode(e.data);
        term.write(data);
    };

    ws.onclose = () => {
        setStatus('error');
        term.writeln('\r\n\x1b[31m--- Session closed. Click Reconnect to start a new session. ---\x1b[0m');
    };

    ws.onerror = () => setStatus('error');
}

function reconnect() {
    term.clear();
    connect();
}

term.onData(data => {
    if (ws && ws.readyState === WebSocket.OPEN) ws.send(data);
});

const resizeObserver = new ResizeObserver(() => {
    fitAddon.fit();
    if (ws && ws.readyState === WebSocket.OPEN) {
        ws.send(JSON.stringify({ type: 'resize', rows: term.rows, cols: term.cols }));
    }
});
resizeObserver.observe(document.getElementById('terminal-container'));

function markComplete() {
    if (!confirm('Mark this request as completed?')) return;
    fetch(`/api/feature-request/complete?id=${REQUEST_ID}`, { method: 'POST' })
        .then(() => {
            window.close();
            setTimeout(() => { location.href = '/feature-requests'; }, 300);
        });
}

connect();
</script>
</body>
</html>
