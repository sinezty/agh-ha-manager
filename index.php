<?php
/**********************
 * CONFIG
 **********************/
$CONFIG = [
    // Authentication (true: enable login screen; false: no login/logout UI)
    "auth_enabled" => false,
    // Dashboard credentials (only used when auth_enabled=true)
    "panel_user"   => "admin",
    "panel_pass"   => "admin123",

    // High Availability (true: Master+Backup UI & Sync; false: single-server UI)
    "backup_server" => true,

    // Uptime retention hours (0 disables uptime collection and hides uptime UI)
    "uptime_retention_hours" => 24,

    // Persistence file for uptime history and last sync
    "data_file" => __DIR__ . "/adguard_sync_data.json",

    // Server definitions
    "servers" => [
        "master" => [
            "name" => "Master DNS",
            "url"  => "http://192.168.1.100",
            "auth" => true,
            "user" => "adguard",
            "pass" => "adguard1"
        ],
        "backup" => [
            "name" => "Backup DNS",
            "url"  => "http://192.168.1.101:8080",
            "auth" => false,
            "user" => "adguard",
            "pass" => "adguard1"
        ]
    ],
];

$HAS_BACKUP = !empty($CONFIG['backup_server']) && isset($CONFIG['servers']['backup']);
$SERVERS = $CONFIG['servers'];
if (!$HAS_BACKUP) {
    unset($SERVERS['backup']);
}
$RETENTION_HOURS = (int)($CONFIG['uptime_retention_hours'] ?? 24);
$UPTIME_ENABLED = $RETENTION_HOURS > 0;

/**********************
 * BASIC AUTH (PANEL)
 **********************/
session_start();
if ($CONFIG['auth_enabled'] && php_sapi_name() !== 'cli') {
    if (isset($_GET['logout'])) {
        session_destroy();
        header("Location: " . basename($_SERVER['PHP_SELF']));
        exit;
    }

    if (!isset($_SESSION['logged'])) {
        if (isset($_POST['login_user'])) {
            if ($_POST['login_user'] === $CONFIG['panel_user'] && $_POST['login_pass'] === $CONFIG['panel_pass']) {
                $_SESSION['logged'] = true;
                header("Location: " . basename($_SERVER['PHP_SELF']));
                exit;
            } else {
                $login_error = "Invalid login!";
            }
        }

        ?>
        <!doctype html>
        <html lang="en" data-bs-theme="dark">
        <head>
            <meta charset="UTF-8">
            <meta name="viewport" content="width=device-width, initial-scale=1.0">
            <title>AGH HA Manager - Login</title>
            <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
            <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
            <style>
                body { background-color: #0f172a; }
                .login-container { min-height: 100vh; display: flex; align-items: center; justify-content: center; padding: 1.5rem; }
                .login-card { background-color: #1e293b; border: 1px solid #334155; border-radius: 0.75rem; }
                .login-card .card-header { background: transparent; border-bottom: 1px solid #334155; }
                .brand { font-weight: 700; letter-spacing: 0.5px; }
                .form-control { background-color: #0b1220; color: #e2e8f0; border: 1px solid #334155; }
                .form-control:focus { border-color: #64748b; box-shadow: none; }
                .btn-primary { background-color: #0ea5e9; border-color: #0ea5e9; }
            </style>
        </head>
        <body>
            <div class="login-container">
                <div class="card login-card shadow-lg" style="max-width: 420px; width: 100%;">
                    <div class="card-header text-center py-3">
                        <div class="brand text-white"><i class="fa-solid fa-shield-halved me-2 text-success"></i>AGH <span class="text-primary">HA</span> Manager</div>
                        <small class="text-secondary">Secure Login</small>
                    </div>
                    <div class="card-body p-4">
                        <?php if (!empty($login_error)) echo '<div class="alert alert-danger">'.$login_error.'</div>'; ?>
                        <form method="post" class="d-grid gap-3">
                            <div class="form-floating">
                                <input type="text" class="form-control" id="login_user" name="login_user" placeholder="Username" required>
                                <label for="login_user">Username</label>
                            </div>
                            <div class="form-floating">
                                <input type="password" class="form-control" id="login_pass" name="login_pass" placeholder="Password" required>
                                <label for="login_pass">Password</label>
                            </div>
                            <button class="btn btn-primary w-100 fw-bold"><i class="fa-solid fa-right-to-bracket me-1"></i> Login</button>
                        </form>
                    </div>
                </div>
            </div>
            <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
        </body>
        </html>
        <?php
        exit;
    }
}

/**********************
 * HELPERS
 **********************/
function api_get($url, $user = null, $pass = null) {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 5,
        CURLOPT_CONNECTTIMEOUT => 5,
    ]);
    if ($user && $pass) {
        curl_setopt($ch, CURLOPT_USERPWD, "$user:$pass");
    }
    $res = curl_exec($ch);
    $err = curl_error($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($err) return ["error" => $err];
    if ($code !== 200) return ["error" => "HTTP $code"];
    return json_decode($res, true);
}

function api_post($url, $payload, $user = null, $pass = null) {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        CURLOPT_POSTFIELDS => json_encode($payload),
        CURLOPT_TIMEOUT => 10,
    ]);
    if ($user && $pass) {
        curl_setopt($ch, CURLOPT_USERPWD, "$user:$pass");
    }
    $res = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return [$code, $res];
}

function srv_auth_args($srv) {
    return [
        !empty($srv['auth']) ? ($srv['user'] ?? null) : null,
        !empty($srv['auth']) ? ($srv['pass'] ?? null) : null,
    ];
}

function load_data($file) {
    if (!file_exists($file)) return ["uptime" => []];
    return json_decode(file_get_contents($file), true);
}

function save_data($file, $data) {
    file_put_contents($file, json_encode($data, JSON_PRETTY_PRINT));
}

function clean_rule_display($rule) {
    // Remove @@|| prefix
    $rule = preg_replace('/^@@\|\|/', '', $rule);
    // Remove ^ suffix and anything after it (options like $important)
    $rule = preg_replace('/\^.*$/', '', $rule);
    return $rule;
}

function normalize_uptime_segments($history, $maxSegments = 96) {
    if (empty($history) || !is_array($history)) return [];
    $count = count($history);
    if ($count <= $maxSegments) {
        return array_map(function($rec) { return !empty($rec['up']); }, $history);
    }
    $segments = [];
    $step = $count / $maxSegments;
    for ($i = 0; $i < $maxSegments; $i++) {
        $idx = (int)floor($i * $step);
        if ($idx >= $count) $idx = $count - 1;
        $segments[] = !empty($history[$idx]['up']);
    }
    return $segments;
}
/**********************
 * CRON / CLI MODE
 **********************/
if (php_sapi_name() === 'cli') {
    $cmd = $argv[1] ?? null;

    // 1. UPTIME CHECK
    if ($cmd === 'uptime') {
        if ($RETENTION_HOURS <= 0) {
            echo "Uptime disabled (uptime_retention_hours=0)\n";
            exit;
        }
        $data = load_data($CONFIG['data_file']);
        $ts = time();
        foreach ($SERVERS as $k => $srv) {
            $st = api_get($srv['url'] . "/control/status",
                $srv['auth'] ? $srv['user'] : null,
                $srv['auth'] ? $srv['pass'] : null
            );
            
            // Check if server is physically reachable (curl error)
            $isUp = empty($st['error']) && !empty($st['running']);
            
            // Append to history
            if (!isset($data['uptime'][$k])) $data['uptime'][$k] = [];
            $data['uptime'][$k][] = [
                "time" => $ts,
                "up"   => $isUp
            ];
            
            $limitTime = time() - ($RETENTION_HOURS * 60 * 60);
            $data['uptime'][$k] = array_filter($data['uptime'][$k], function($row) use ($limitTime) {
                return $row['time'] >= $limitTime;
            });
            $data['uptime'][$k] = array_values($data['uptime'][$k]); // Re-index
        }
        save_data($CONFIG['data_file'], $data);
        echo "Uptime check done. Data saved to {$CONFIG['data_file']}\n";
        exit;
    }

    // 2. SYNC MASTER -> BACKUP
    if ($HAS_BACKUP && strpos($cmd, 'sync=master_to_backup') !== false) {
        $src = $CONFIG['servers']['master'];
        $dst = $CONFIG['servers']['backup'];
        
        echo "Syncing Master -> Backup...\n";
        [$srcUser, $srcPass] = srv_auth_args($src);
        [$dstUser, $dstPass] = srv_auth_args($dst);

        $srcFilter = api_get($src['url'] . "/control/filtering/status", $srcUser, $srcPass);
        $rules = $srcFilter['user_rules'] ?? [];
        
        list($code, $res) = api_post($dst['url'] . "/control/filtering/set_rules", ["rules" => $rules], $dstUser, $dstPass);
        
        $data = load_data($CONFIG['data_file']);
        $data['last_sync'] = date("Y-m-d H:i:s") . " (Master -> Backup) [CLI]";
        save_data($CONFIG['data_file'], $data);
        
        echo "Done. Code: $code\n";
        exit;
    }

    // 3. SYNC BACKUP -> MASTER
    if ($HAS_BACKUP && strpos($cmd, 'sync=backup_to_master') !== false) {
        $src = $CONFIG['servers']['backup'];
        $dst = $CONFIG['servers']['master'];
        
        echo "Syncing Backup -> Master...\n";
        [$srcUser, $srcPass] = srv_auth_args($src);
        [$dstUser, $dstPass] = srv_auth_args($dst);

        $srcFilter = api_get($src['url'] . "/control/filtering/status", $srcUser, $srcPass);
        $rules = $srcFilter['user_rules'] ?? [];
        
        list($code, $res) = api_post($dst['url'] . "/control/filtering/set_rules", ["rules" => $rules], $dstUser, $dstPass);
        
        $data = load_data($CONFIG['data_file']);
        $data['last_sync'] = date("Y-m-d H:i:s") . " (Backup -> Master) [CLI]";
        save_data($CONFIG['data_file'], $data);
        
        echo "Done. Code: $code\n";
        exit;
    }
}

/**********************
 * ACTIONS
 **********************/
$self = basename($_SERVER['PHP_SELF']);
$data = load_data($CONFIG['data_file']);

if ($HAS_BACKUP && isset($_POST['sync_master_to_backup'])) {
    $src = $CONFIG['servers']['master'];
    $dst = $CONFIG['servers']['backup'];
    
    [$srcUser, $srcPass] = srv_auth_args($src);
    [$dstUser, $dstPass] = srv_auth_args($dst);

    $srcFilter = api_get($src['url'] . "/control/filtering/status", $srcUser, $srcPass);
    $rules = $srcFilter['user_rules'] ?? [];

    api_post($dst['url'] . "/control/filtering/set_rules", [
        "rules" => $rules
    ], $dstUser, $dstPass);

    $data['last_sync'] = date("Y-m-d H:i:s") . " (Master -> Backup)";
    save_data($CONFIG['data_file'], $data);
    $msg = "Master -> Backup synchronization completed.";
}

if ($HAS_BACKUP && isset($_POST['sync_backup_to_master'])) {
    $src = $CONFIG['servers']['backup'];
    $dst = $CONFIG['servers']['master'];
    
    [$srcUser, $srcPass] = srv_auth_args($src);
    [$dstUser, $dstPass] = srv_auth_args($dst);

    $srcFilter = api_get($src['url'] . "/control/filtering/status", $srcUser, $srcPass);
    $rules = $srcFilter['user_rules'] ?? [];

    api_post($dst['url'] . "/control/filtering/set_rules", [
        "rules" => $rules
    ], $dstUser, $dstPass);

    $data['last_sync'] = date("Y-m-d H:i:s") . " (Backup -> Master)";
    save_data($CONFIG['data_file'], $data);
    $msg = "Backup -> Master synchronization completed.";
}

// Protection Control
if (isset($_POST['protection_action'])) {
    $targetKey = $_POST['server_key'];
    $action = $_POST['protection_action']; // enable, disable, disable_time
    $duration = (int)($_POST['duration'] ?? 0); // minutes
    
    if (isset($SERVERS[$targetKey])) {
        $srv = $SERVERS[$targetKey];
        $url = $srv['url'] . "/control/protection";
        
        $payload = ["enabled" => true];
        if ($action === 'disable') {
            $payload = ["enabled" => false];
        } elseif ($action === 'disable_time' && $duration > 0) {
            $payload = ["enabled" => false, "duration" => $duration * 60 * 1000]; // ms
        } elseif ($action === 'enable') {
            $payload = ["enabled" => true];
        }

        [$srvUser, $srvPass] = srv_auth_args($srv);
        list($code, $res) = api_post($url, $payload, $srvUser, $srvPass);
        
        if ($code == 200) {
            $msg = "Server ($targetKey) protection settings updated: $action";
        } else {
            $login_error = "Error occurred! Code: $code, Res: $res";
        }
    }
}

// Update Whitelist (Sync Mode)
if (isset($_POST['update_whitelist'])) {
    $targetKey = $_POST['server_key'];
    $rawList = $_POST['whitelist_data'] ?? '';
    
    if (isset($SERVERS[$targetKey])) {
        $srv = $SERVERS[$targetKey];
        
        // 1. Get current rules
        [$srvUser, $srvPass] = srv_auth_args($srv);
        $current = api_get($srv['url'] . "/control/filtering/status", $srvUser, $srvPass);
        $rules = $current['user_rules'] ?? [];
        
        // 2. Filter out OLD whitelist rules (keep non-whitelist rules)
        $nonWhitelistRules = array_filter($rules, function($r) {
            // Check for whitelist pattern (@@||)
            return strpos($r, '@@||') !== 0;
        });
        
        // 3. Process NEW whitelist
        $lines = explode("\n", $rawList);
        $newWhitelistRules = [];
        foreach ($lines as $line) {
            $domain = trim($line);
            if (!empty($domain)) {
                // Ensure plain domain format (remove @@|| and ^ if user pasted full rules)
                $domain = preg_replace('/^@@\|\|/', '', $domain);
                $domain = preg_replace('/\^.*$/', '', $domain);
                
                $newWhitelistRules[] = "@@||" . $domain . "^";
            }
        }
        
        // 4. Merge and Save
        $finalRules = array_merge(array_values($nonWhitelistRules), $newWhitelistRules);
        
        list($code, $res) = api_post($srv['url'] . "/control/filtering/set_rules", ["rules" => $finalRules], $srvUser, $srvPass);
        
        if ($code == 200) {
            $msg = "Whitelist updated successfully for " . $srv['name'];
        } else {
            $login_error = "Failed to update whitelist. Code: $code";
        }
    }
}

if (isset($_GET['export'])) {
    header("Content-Type: application/json");
    echo json_encode($data, JSON_PRETTY_PRINT);
    exit;
}

/**********************
 * FETCH STATUS
 **********************/
$statuses = [];
foreach ($SERVERS as $k => $srv) {
    $statuses[$k] = api_get($srv['url'] . "/control/status",
        $srv['auth'] ? $srv['user'] : null,
        $srv['auth'] ? $srv['pass'] : null
    );
}

$stats = [];
foreach ($SERVERS as $k => $srv) {
    $stats[$k] = api_get($srv['url'] . "/control/stats",
        $srv['auth'] ? $srv['user'] : null,
        $srv['auth'] ? $srv['pass'] : null
    );
}

$filters = [];
foreach ($SERVERS as $k => $srv) {
    $filters[$k] = api_get($srv['url'] . "/control/filtering/status",
        $srv['auth'] ? $srv['user'] : null,
        $srv['auth'] ? $srv['pass'] : null
    );
}
?>
<!doctype html>
<html lang="en" data-bs-theme="dark">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>AGH HA Manager</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <style>
        body { background-color: #0f172a; font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; }
        .card { border: 1px solid #1e293b; background-color: #1e293b; box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1), 0 2px 4px -1px rgba(0, 0, 0, 0.06); }
        .card-header { background-color: #334155; border-bottom: 1px solid #475569; letter-spacing: 0.5px; }
        .stat-card { background-color: #0f172a; border: 1px solid #334155; transition: all 0.2s; }
        .stat-card:hover { border-color: #64748b; transform: translateY(-2px); }
        .btn-action { transition: all 0.2s; }
        .btn-action:hover { transform: scale(1.05); }
        .status-badge { font-size: 0.85em; padding: 0.4em 0.8em; }
        .navbar { background-color: #1e293b !important; border-bottom: 1px solid #334155; }
        .accordion-button { background-color: #334155; color: #e2e8f0; }
        .accordion-button:not(.collapsed) { background-color: #475569; color: #fff; box-shadow: inset 0 -1px 0 rgba(0,0,0,.125); }
        .accordion-button::after { filter: invert(1); }
        pre { background: #0f172a; padding: 1rem; border-radius: 0.5rem; border: 1px solid #334155; color: #10b981; }
        .uptime-bar { display: flex; gap: 2px; height: 24px; }
        .uptime-segment { flex: 1; min-width: 2px; background-color: #333; border-radius: 2px; position: relative; }
        .uptime-segment.up { background-color: #198754; }
        .uptime-segment.down { background-color: #dc3545; }
        .uptime-segment:hover { opacity: 0.8; cursor: pointer; }
    </style>
</head>
<body>

<nav class="navbar navbar-expand-lg navbar-dark mb-4">
    <div class="container">
        <a class="navbar-brand fw-bold" href="#">
            <i class="fa-solid fa-shield-halved me-2 text-success"></i>AGH <span class="text-primary">HA</span> Manager
        </a>
        <div class="d-flex">
            <?php if (!empty($CONFIG['auth_enabled'])): ?>
                <a href="?logout=1" class="btn btn-outline-danger btn-sm">
                    <i class="fa-solid fa-right-from-bracket me-1"></i> Logout
                </a>
            <?php endif; ?>
        </div>
    </div>
</nav>

<div class="container mt-5 pt-3">
    
    <?php if (!empty($msg)): ?>
        <div id="alert-msg" class="alert alert-success alert-dismissible fade show position-fixed top-0 start-50 translate-middle-x mt-3 shadow-lg" role="alert" style="z-index: 1050; min-width: 300px;">
            <i class="fa-solid fa-circle-check me-2"></i> <?= $msg ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
    <?php endif; ?>
    
    <?php if (!empty($login_error)): ?>
        <div id="alert-err" class="alert alert-danger alert-dismissible fade show position-fixed top-0 start-50 translate-middle-x mt-3 shadow-lg" role="alert" style="z-index: 1050; min-width: 300px;">
            <i class="fa-solid fa-circle-exclamation me-2"></i> <?= $login_error ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
    <?php endif; ?>

    <div class="row g-4">
        <?php foreach ($SERVERS as $k => $srv):
            $st = $statuses[$k];
            $up = empty($st['error']) && !empty($st['running']);
            $prot = $st['protection_enabled'] ?? false;
            $disabledUntil = $st['protection_disabled_until'] ?? null;
            $remainingTime = null;
            if (!$prot && $disabledUntil) {
                // AdGuard API returns milliseconds timestamp or ISO string depending on version.
                // Assuming standard AdGuard response which is usually ms timestamp or null.
                // Actually some versions return null if manually disabled without timer.
                // If it's a future timestamp:
                $now = time() * 1000;
                // Check if it looks like a timestamp (numeric) or string
                if (is_numeric($disabledUntil) && $disabledUntil > $now) {
                    $diff = ($disabledUntil - $now) / 1000; // seconds
                    $min = floor($diff / 60);
                    $sec = floor($diff % 60);
                    $remainingTime = sprintf("%d min %d sec", $min, $sec);
                }
            }

            $rules = $filters[$k]['user_rules'] ?? [];
            $cleanRules = array_map('clean_rule_display', $rules);
            // Filter out empty lines
            $cleanRules = array_filter($cleanRules, function($r) {
                return !empty(trim($r));
            });
            $srvStats = $stats[$k] ?? [];

            // Check if stats (time_series) available for charting
            // AdGuard returns `dns_queries` as array of counts
            $hasChart = !empty($srvStats['dns_queries']);
        ?>
        <div class="<?= $HAS_BACKUP ? 'col-lg-6' : 'col-lg-12' ?>">
            <div class="card h-100">
                <div class="card-header d-flex justify-content-between align-items-center py-3">
                    <h5 class="mb-0 text-white">
                        <i class="fa-solid fa-server me-2 text-secondary"></i><?= $srv['name'] ?>
                    </h5>
                    <div>
                        <span class="badge rounded-pill <?= $prot ? 'bg-success' : 'bg-warning text-dark' ?> me-1 status-badge">
                            <i class="fa-solid <?= $prot ? 'fa-shield' : 'fa-pause' ?> me-1"></i>
                            <?= $prot ? "PROTECTED" : ($remainingTime ? "PAUSED ($remainingTime)" : "PAUSED") ?>
                        </span>
                        <span class="badge rounded-pill <?= $up ? 'bg-success' : 'bg-danger' ?> status-badge">
                            <i class="fa-solid <?= $up ? 'fa-check' : 'fa-xmark' ?> me-1"></i>
                            <?= $up ? "UP" : "DOWN" ?>
                        </span>
                    </div>
                </div>
                <div class="card-body">
                    
                    <div class="mb-3">
                        <a href="<?= $srv['url'] ?>" target="_blank" class="text-decoration-none text-info fw-bold">
                            <i class="fa-solid fa-up-right-from-square me-1"></i> <?= $srv['url'] ?>
                        </a>
                    </div>

                    <div class="row g-2 mb-4">
                        <?php 
                            $numQueries = $srvStats['num_dns_queries'] ?? 0;
                            $numBlocked = $srvStats['num_blocked_filtering'] ?? 0;
                            $percentBlocked = ($numQueries > 0) ? ($numBlocked / $numQueries) * 100 : 0;
                        ?>
                        <div class="col-4 mb-3">
                            <div class="stat-card p-3 rounded bg-dark border border-secondary h-100">
                                <small class="text-secondary d-block mb-1">DNS Queries</small>
                                <h4 class="mb-0 fw-bold text-light"><i class="fa-solid fa-globe me-2 text-primary"></i><?= number_format($numQueries) ?></h4>
                            </div>
                        </div>
                        <div class="col-4 mb-3">
                            <div class="stat-card p-3 rounded bg-dark border border-secondary h-100">
                                <small class="text-secondary d-block mb-1">Blocked</small>
                                <h4 class="mb-0 fw-bold text-light"><i class="fa-solid fa-ban me-2 text-danger"></i><?= number_format($numBlocked) ?></h4>
                                <small class="text-danger fw-bold" style="font-size: 0.75rem;">%<?= number_format($percentBlocked, 2) ?></small>
                            </div>
                        </div>
                        <div class="col-4 mb-3">
                            <div class="stat-card p-3 rounded bg-dark border border-secondary h-100">
                                <small class="text-secondary d-block mb-1">Avg. Latency</small>
                                <h4 class="mb-0 fw-bold text-warning"><i class="fa-solid fa-bolt me-2"></i><?= round(($srvStats['avg_processing_time'] ?? 0) * 1000, 2) ?> ms</h4>
                            </div>
                        </div>
                    </div>

                    <?php if ($hasChart): ?>
                        <div class="mb-4">
                            <h6 class="text-secondary mb-2 small text-uppercase fw-bold"><i class="fa-solid fa-chart-area me-2"></i>Last 24 Hours Traffic</h6>
                            <div style="height: 100px;">
                                <canvas id="chart-<?= $k ?>"></canvas>
                            </div>
                            <script>
                                document.addEventListener('DOMContentLoaded', function() {
                                    // Generate last 24 hours labels
                                    const labels = [];
                                    const now = new Date();
                                    for (let i = 23; i >= 0; i--) {
                                        const d = new Date(now.getTime() - (i * 60 * 60 * 1000));
                                        labels.push(d.getHours() + ':00');
                                    }

                                    const ctx = document.getElementById('chart-<?= $k ?>').getContext('2d');
                                    new Chart(ctx, {
                                        type: 'line',
                                        data: {
                                            labels: labels,
                                            datasets: [
                                                {
                                                    label: 'Total Queries',
                                                    data: <?= json_encode(array_values($srvStats['dns_queries'])) ?>,
                                                    borderColor: '#0dcaf0',
                                                    backgroundColor: 'rgba(13, 202, 240, 0.1)',
                                                    borderWidth: 2,
                                                    fill: true,
                                                    tension: 0.4,
                                                    pointRadius: 0,
                                                    pointHitRadius: 10
                                                },
                                                {
                                                    label: 'Blocked',
                                                    data: <?= json_encode(array_values($srvStats['blocked_filtering'] ?? [])) ?>,
                                                    borderColor: '#dc3545',
                                                    backgroundColor: 'rgba(220, 53, 69, 0.1)',
                                                    borderWidth: 2,
                                                    fill: true,
                                                    tension: 0.4,
                                                    pointRadius: 0,
                                                    pointHitRadius: 10
                                                }
                                            ]
                                        },
                                        options: {
                                            plugins: { 
                                                legend: { 
                                                    display: true,
                                                    labels: { color: '#94a3b8', font: { size: 10 } } 
                                                }, 
                                                tooltip: { enabled: true, intersect: false, mode: 'index' } 
                                            },
                                            scales: { x: { display: false }, y: { display: false } },
                                            maintainAspectRatio: false,
                                            responsive: true,
                                            interaction: {
                                                mode: 'nearest',
                                                axis: 'x',
                                                intersect: false
                                            }
                                        }
                                    });
                                });
                            </script>
                        </div>
                    <?php endif; ?>
                    


                    <!-- Protection Controls -->
                    <div class="bg-dark bg-opacity-50 p-3 rounded mb-3 border border-secondary">
                        <label class="fw-bold mb-2 text-light small text-uppercase"><i class="fa-solid fa-sliders me-1"></i> Protection Management</label>
                        <form method="post" class="d-flex gap-2 flex-wrap align-items-center">
                            <input type="hidden" name="server_key" value="<?= $k ?>">
                            
                            <?php if ($prot): ?>
                                <button name="protection_action" value="disable" class="btn btn-sm btn-danger btn-action flex-grow-1">
                                    <i class="fa-solid fa-power-off me-1"></i> Disable
                                </button>
                                <div class="input-group input-group-sm" style="width: auto;">
                                    <button name="protection_action" value="disable_time" class="btn btn-warning text-dark btn-action">
                                        <i class="fa-solid fa-hourglass-start me-1"></i>
                                    </button>
                                    <select name="duration" class="form-select bg-dark text-light border-secondary">
                                        <option value="1">1 min</option>
                                        <option value="10">10 min</option>
                                        <option value="30">30 min</option>
                                        <option value="60">1 hr</option>
                                    </select>
                                </div>
                            <?php else: ?>
                                <button name="protection_action" value="enable" class="btn btn-success btn-sm w-100 btn-action">
                                    <i class="fa-solid fa-play me-1"></i> Enable Protection
                                </button>
                            <?php endif; ?>
                        </form>
                    </div>

                    <!-- Whitelist Management -->
                    <div class="accordion" id="acc-<?= $k ?>">
                        <div class="accordion-item bg-transparent border-secondary">
                            <h2 class="accordion-header">
                                <button class="accordion-button collapsed py-2 text-success" type="button" data-bs-toggle="collapse" data-bs-target="#collapse-<?= $k ?>">
                                    <i class="fa-solid fa-list-check me-2"></i> Whitelist Editor (<?= count($cleanRules) ?>)
                                </button>
                            </h2>
                            <div id="collapse-<?= $k ?>" class="accordion-collapse collapse" data-bs-parent="#acc-<?= $k ?>">
                                <div class="accordion-body bg-dark p-3">
                                    <form method="post">
                                        <input type="hidden" name="server_key" value="<?= $k ?>">
                                        <div class="mb-2 d-flex justify-content-between align-items-center">
                                            <small class="text-secondary">One domain per line (e.g. <code>google.com</code>)</small>
                                            <span class="badge bg-secondary"><?= count($cleanRules) ?> rules</span>
                                        </div>
                                        <textarea name="whitelist_data" class="form-control bg-black text-white border-secondary mb-3" rows="10" style="font-family: monospace; font-size: 0.9rem; white-space: pre;"><?= htmlspecialchars(implode("\n", $cleanRules)) ?></textarea>
                                        <button name="update_whitelist" class="btn btn-success w-100 mt-2 fw-bold shadow-sm" onclick="return confirm('This will synchronize the whitelist on <?= $srv['name'] ?> with the list above. Continue?')">
                                            <i class="fa-solid fa-save me-2"></i> SAVE CHANGES
                                        </button>
                                    </form>
                                </div>
                            </div>
                        </div>
                    </div>

                </div>
            </div>
        </div>
        <?php endforeach; ?>
    </div>

    <!-- Sync Actions -->
    <?php if ($HAS_BACKUP): ?>
        <div class="accordion mt-5 mb-4" id="accordionSync">
            <div class="accordion-item bg-transparent border-primary">
                <h2 class="accordion-header" id="headingSync">
                    <button class="accordion-button collapsed bg-primary bg-opacity-10 text-primary fw-bold" type="button" data-bs-toggle="collapse" data-bs-target="#collapseSync" aria-expanded="false" aria-controls="collapseSync">
                        <i class="fa-solid fa-rotate me-2"></i> Synchronization Center
                    </button>
                </h2>
                <div id="collapseSync" class="accordion-collapse collapse" aria-labelledby="headingSync" data-bs-parent="#accordionSync">
                    <div class="accordion-body bg-dark">
                        <div class="row align-items-center">
                            <div class="col-md-7">
                                <p class="mb-3 text-muted">Sync rules between Master and Backup servers.</p>
                                <div class="d-inline-block p-2 rounded border border-secondary bg-black bg-opacity-25">
                                    <small class="text-secondary"><i class="fa-regular fa-clock me-1"></i> Last Action:</small>
                                    <div class="text-light fw-bold"><?= $data['last_sync'] ?? 'Not performed yet' ?></div>
                                </div>
                            </div>
                            <div class="col-md-5 mt-4 mt-md-0">
                                <form method="post" action="<?= htmlspecialchars($self) ?>" class="d-grid gap-3">
                                    <button name="sync_master_to_backup" class="btn btn-primary p-3 btn-action shadow-sm" onclick="return confirm('Master rules will be written to Backup. Are you sure?')">
                                        <div class="d-flex align-items-center justify-content-center fw-bold">
                                            <span>Master</span>
                                            <i class="fa-solid fa-arrow-right mx-3"></i> 
                                            <span>Backup</span>
                                        </div>
                                        <div class="small opacity-75 fw-normal mt-1">Copies Master settings to Backup</div>
                                    </button>
                                    
                                    <button name="sync_backup_to_master" class="btn btn-outline-secondary p-3 btn-action" onclick="return confirm('Backup rules will be written to Master. Are you sure?')">
                                        <div class="d-flex align-items-center justify-content-center fw-bold text-light">
                                            <span>Backup</span>
                                            <i class="fa-solid fa-arrow-right mx-3"></i> 
                                            <span>Master</span>
                                        </div>
                                        <div class="small opacity-50 fw-normal mt-1">Copies Backup settings to Master</div>
                                    </button>

                                    <div class="text-center mt-2">
                                        <a href="?export=1" class="text-secondary text-decoration-none small hover-white">
                                            <i class="fa-solid fa-file-export me-1"></i> Download JSON Backup
                                        </a>
                                    </div>
                                </form>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    <?php endif; ?>

    <!-- Uptime Logs -->
    <?php if ($UPTIME_ENABLED): ?>
    <div class="card mb-4">
        <div class="card-header py-3">
            <h5 class="mb-0 text-white"><i class="fa-solid fa-heart-pulse me-2 text-danger"></i>Uptime History</h5>
        </div>
        <div class="card-body">
            <div class="row">
                <?php foreach ($SERVERS as $k => $v): ?>
                    <div class="<?= $HAS_BACKUP ? 'col-md-6' : 'col-md-12' ?> mb-3 mb-md-0">
                        <h6 class="text-secondary mb-2"><?= $v['name'] ?></h6>
                        <div class="uptime-bar" title="Green: Up, Red: Down">
                            <?php 
                            $historyRaw = isset($data['uptime'][$k]) ? $data['uptime'][$k] : [];
                            $limitTimeUi = time() - ($RETENTION_HOURS * 60 * 60);
                            $history = array_values(array_filter($historyRaw, function($row) use ($limitTimeUi) {
                                return isset($row['time']) && $row['time'] >= $limitTimeUi;
                            }));
                            $norm = normalize_uptime_segments($history, $HAS_BACKUP ? 96 : 144);
                            if (empty($norm)) {
                                $live = $statuses[$k] ?? [];
                                $isUpLive = empty($live['error']) && !empty($live['running']);
                                echo '<div class="uptime-segment '.($isUpLive ? 'up' : 'down').'" data-bs-toggle="tooltip" title="Now - '.($isUpLive ? 'UP' : 'DOWN').'"></div>';
                            } else {
                                foreach ($norm as $isUp) { 
                                    echo '<div class="uptime-segment '.($isUp ? 'up' : 'down').'" data-bs-toggle="tooltip" title="'.($isUp ? 'UP' : 'DOWN').'"></div>';
                                }
                            }
                            ?>
                        </div>
                        <?php if (empty($history)): ?>
                            <small class="text-secondary">No history yet. Configure cron for periodic checks.</small>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
    <?php endif; ?>

</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
    // Auto-hide alerts after 5 seconds
    setTimeout(function() {
        const alerts = document.querySelectorAll('.alert');
        alerts.forEach(function(alert) {
            const bsAlert = new bootstrap.Alert(alert);
            bsAlert.close();
        });
    }, 5000);

    // Initialize tooltips
    var tooltipTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="tooltip"]'))
    var tooltipList = tooltipTriggerList.map(function (tooltipTriggerEl) {
      return new bootstrap.Tooltip(tooltipTriggerEl)
    })
</script>
</body>
</html>
