<?php
/**
 * LoveWall 静态检查器（项目专用）
 *
 * ==========================================================================
 * 为什么自己写而不是用 phpstan
 * ==========================================================================
 * phpstan 是通用类型检查器，需要完整类型标注才能发挥价值 —— 而这个项目
 * 全是无类型的过程式代码，phpstan 开 level 5 以上会报几千条噪音，
 * 实际有用的可能不到十条。
 *
 * 这个检查器反过来：只查「这个项目真实踩过的坑」。
 * 每一条规则都对应一次线上事故或一次难查的 bug。
 *
 * 用法：php tools/lw_lint.php [目录]
 * ==========================================================================
 */

$root = $argv[1] ?? (__DIR__ . '/../');
$root = rtrim($root, '/\\');

$issues = [];
$files = [];
$scanned = 0;

function walk($dir, &$files, $root) {
    $skip = ['data', 'uploads', 'node_modules', '.git', '_tools', 'download'];
    foreach (scandir($dir) as $e) {
        if ($e === '.' || $e === '..') continue;
        $p = $dir . '/' . $e;
        $rel = ltrim(str_replace($root, '', $p), '/');
        if (is_dir($p)) {
            if (in_array($e, $skip, true)) continue;
            walk($p, $files, $root);
        } elseif (substr($e, -4) === '.php') {
            $files[] = ['path' => $p, 'rel' => $rel];
        }
    }
}

walk($root, $files, $root);

$add = function ($rel, $line, $level, $rule, $msg) use (&$issues) {
    $issues[] = ['file' => $rel, 'line' => $line, 'level' => $level, 'rule' => $rule, 'msg' => $msg];
};

// ==========================================================================
foreach ($files as $f) {
    $scanned++;
    $src = @file_get_contents($f['path']);
    if ($src === false) continue;
    $lines = explode("\n", $src);
    $rel = $f['rel'];

    // ── 规则 1：语法（用 php -l 的等价判断：token 级）──
    // 这里不重复做，交给调用方跑 php -l

    // ── 规则 2：$_REQUEST / $_GET / $_POST 未给默认值 ──
    // 这是本项目最常见的 Warning 来源（Undefined array key）
    for ($i = 0; $i < count($lines); $i++) {
        $ln = $lines[$i];
        if (preg_match_all('/\$_(?:GET|POST|REQUEST)\s*\[\s*[\'"]([^\'"]+)[\'"]\s*\]/', $ln, $m)) {
            foreach ($m[1] as $k) {
                // 同一行里有 ?? 或 isset 就算处理了
                if (strpos($ln, '??') !== false || strpos($ln, 'isset') !== false
                    || strpos($ln, 'empty(') !== false || strpos($ln, 'array_key_exists') !== false) {
                    continue;
                }
                $add($rel, $i + 1, 'warn', 'undef-key',
                    "\$_['$k'] 未给默认值，未传参时会产生 Warning");
            }
        }
    }

    // ── 规则 3：json_encode 未带 JSON_UNESCAPED_UNICODE（中文会变 \uXXXX）──
    for ($i = 0; $i < count($lines); $i++) {
        if (preg_match('/json_encode\s*\(/', $lines[$i])
            && strpos($lines[$i], 'JSON_UNESCAPED_UNICODE') === false
            && strpos($lines[$i], 'JSON_HEX') === false
            && strpos($lines[$i], 'JSON_PRETTY') === false
            && strpos($lines[$i], 'JSON_THROW') === false
            && strpos($lines[$i], '//') === false) {
            // 只在明显是「返回给前端」的场景提示
            if (preg_match('/jsonSuccess|jsonError|echo|\$result\[|return/', $lines[$i])) {
                $add($rel, $i + 1, 'info', 'json-cn',
                    'json_encode 无 JSON_UNESCAPED_UNICODE，中文变转义序列');
            }
        }
    }

    // ── 规则 4：直接比较密码（应该用 password_verify）──
    for ($i = 0; $i < count($lines); $i++) {
        if (preg_match('/\$(?:pass|password|pwd)\w*\s*===?\s*\$/', $lines[$i])
            && strpos($lines[$i], 'hash') === false
            && strpos($lines[$i], 'password_verify') === false
            && strpos($lines[$i], '//') === false) {
            $add($rel, $i + 1, 'error', 'plain-pwd',
                '疑似明文比较密码，应使用 password_verify()');
        }
    }

    // ── 规则 5：SQL 拼接（本项目无 SQL，但防止将来引入）──
    for ($i = 0; $i < count($lines); $i++) {
        if (preg_match('/(?:SELECT|INSERT|UPDATE|DELETE)\s+.*\$_(?:GET|POST|REQUEST)/i', $lines[$i])) {
            $add($rel, $i + 1, 'error', 'sql-inject', 'SQL 语句直接拼接用户输入');
        }
    }

    // ── 规则 6：echo 未转义（XSS）──
    for ($i = 0; $i < count($lines); $i++) {
        $ln = $lines[$i];
        if (preg_match('/<\?=\s*\$([a-zA-Z_]\w*)\s*\?>/', $ln, $m)) {
            if (strpos($ln, 'htmlspecialchars') === false
                && strpos($ln, 'xss_clean') === false
                && strpos($ln, 'e(') === false
                && strpos($ln, 'json_encode') === false
                && strpos($ln, 'intval') === false
                && strpos($ln, '(int)') === false
                && strpos($ln, 'lw_icon') === false
                && strpos($ln, 'adminHeader') === false
                && strpos($ln, 't(') === false
                && strpos($ln, 'SITE_') === false
                && strpos($ln, 'csrfToken') === false) {
                $add($rel, $i + 1, 'warn', 'xss-echo',
                    "<?= \$" . $m[1] . " ?> 未转义，若含用户输入则可能 XSS");
            }
        }
    }

    // ── 规则 7：file_put_contents 未加 LOCK_EX（并发写坏 JSON）──
    for ($i = 0; $i < count($lines); $i++) {
        if (strpos($lines[$i], 'file_put_contents') !== false
            && strpos($lines[$i], 'LOCK_EX') === false
            && strpos($lines[$i], '//') === false
            && !preg_match('/\$\w+\s*=\s*@?file_put_contents/', $lines[$i])) {
            // 允许几行内出现 LOCK_EX
            $window = implode("\n", array_slice($lines, $i, 3));
            if (strpos($window, 'LOCK_EX') === false) {
                $add($rel, $i + 1, 'warn', 'no-lock',
                    'file_put_contents 未加 LOCK_EX，并发写可能损坏文件');
            }
        }
    }

    // ── 规则 8：require 用相对路径（易受 cwd 影响）──
    for ($i = 0; $i < count($lines); $i++) {
        if (preg_match('/(?:require|include)(?:_once)?\s+[\'"](?!\/|\.\.\/)[^\'"]+\.php[\'"]/', $lines[$i])) {
            $add($rel, $i + 1, 'warn', 'rel-require',
                'require 使用相对路径，建议 __DIR__ . ...');
        }
    }

    // ── 规则 9：根目录文件的 require 路径不能有 /../config ──
    if (strpos($rel, '/') === false && substr($rel, -4) === '.php') {
        if (strpos($src, "__DIR__ . '/../config/") !== false) {
            for ($i = 0; $i < count($lines); $i++) {
                if (strpos($lines[$i], "__DIR__ . '/../config/") !== false) {
                    $add($rel, $i + 1, 'error', 'root-path',
                        '根目录文件不能用 /../config/，会指到站点外');
                }
            }
        }
    }

    // ── 规则 10：后台页面缺 adminHeader ──
    if (strpos($rel, 'admin/') === 0 && substr($rel, -4) === '.php') {
        $skipAdmin = ['layout.php', 'index.php', 'login.php'];
        $base = basename($rel);
        if (!in_array($base, $skipAdmin, true)) {
            if (strpos($src, 'adminHeader(') === false && strpos($src, '<!DOCTYPE') === false) {
                $add($rel, 1, 'warn', 'no-admin-header',
                    '后台页面既没有 adminHeader() 也没有完整 HTML，可能样式脱队');
            }
        }
    }

    // ── 规则 11：session_start 之外的 session_regenerate 检查 ──
    if (strpos($src, 'session_regenerate_id(true)') !== false
        && strpos($src, 'lwMarkSessionCookieWritten') === false
        && strpos($rel, 'config/') !== 0) {
        $add($rel, 1, 'info', 'session-regen',
            'session_regenerate_id 后未调 lwMarkSessionCookieWritten，响应可能被缓存');
    }

    // ── 规则 12：TODO / FIXME / XXX 遗留 ──
    for ($i = 0; $i < count($lines); $i++) {
        if (preg_match('/\/\/\s*(TODO|FIXME|XXX|HACK)\b/i', $lines[$i], $m)) {
            $add($rel, $i + 1, 'info', 'todo',
                '遗留 ' . strtoupper($m[1]) . '：' . trim(substr($lines[$i], stripos($lines[$i], $m[1]) + strlen($m[1]))));
        }
    }

    // ── 规则 13：硬编码域名 ──
    if (preg_match_all('/[\'"]https?:\/\/([a-z0-9.-]+)[\'"]/i', $src, $m)) {
        foreach (array_unique($m[1]) as $d) {
            if (in_array($d, ['hnbsd.ct.ws', 'www.hnbsd.ct.ws', 'localhost', '127.0.0.1'], true)) continue;
            // 白名单：第三方 API
            if (preg_match('/qq\.com|bilibili|github|googleapis|jsdelivr|hitokoto|ipapi|qrserver|w3\.org|schema\.org/', $d)) continue;
            $add($rel, 1, 'info', 'hardcoded-host', '硬编码域名：' . $d);
        }
    }

    // ── 规则 14：未闭合的 <?php 标签（在纯 PHP 文件里）──
    if (substr($rel, -4) === '.php' && strpos($src, '?>') === false) {
        // 纯 PHP 文件不闭合是正确的，跳过
    }
}

// ==========================================================================
// 汇总输出
// ==========================================================================
$byLevel = ['error' => 0, 'warn' => 0, 'info' => 0];
$byRule = [];
foreach ($issues as $i) {
    $byLevel[$i['level']]++;
    $byRule[$i['rule']] = ($byRule[$i['rule']] ?? 0) + 1;
}

echo "LoveWall 静态检查\n";
echo str_repeat('=', 62) . "\n";
printf("扫描 %d 个 PHP 文件\n", $scanned);
printf("发现 %d 处问题： %d 错误 / %d 警告 / %d 提示\n\n",
    count($issues), $byLevel['error'], $byLevel['warn'], $byLevel['info']);

arsort($byRule);
echo "按规则统计：\n";
foreach ($byRule as $r => $n) {
    printf("  %-18s %3d\n", $r, $n);
}

// 只显示 error 和 warn 的详情（info 太多）
$show = array_filter($issues, function ($i) { return $i['level'] !== 'info'; });
if ($show) {
    echo "\n" . str_repeat('=', 62) . "\n";
    echo "错误与警告详情：\n\n";
    $curFile = '';
    foreach ($show as $i) {
        if ($i['file'] !== $curFile) {
            $curFile = $i['file'];
            echo $curFile . "\n";
        }
        printf("  %s:%d  [%s] %s\n",
            '', $i['line'], $i['level'] === 'error' ? '错误' : '警告', $i['msg']);
    }
}

exit($byLevel['error'] > 0 ? 1 : 0);
