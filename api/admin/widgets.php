<?php
/**
 * 后台「装扮管理」接口（需求二、2）
 *
 * 动作：
 *   list_widgets   全部装扮（含下架）
 *   save_widget    新增 / 编辑（name / slot / css_class / price / days / sort / active）
 *   toggle_widget  上架 / 下架
 *   del_widget     删除
 *
 * 安全（对齐 api/admin/comments.php 范例）：POST + CSRF + 权限 + 输入白名单。
 * 槽位强校验 LW_WIDGET_SLOTS —— 非法槽位直接拒绝，绝不让脏数据进库造成渲染错位。
 */
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../includes/security.php';
require_once __DIR__ . '/../../includes/widgets.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonError('仅支持 POST 请求', 405);
}

if (!verifyCSRFToken((string)($_POST['csrf_token'] ?? ''))) {
    jsonError('CSRF验证失败', 403);
}

$admin = requireAdmin();
$admin = checkBanned($admin);
if ($admin['is_banned']) {
    jsonError('账号已被封禁');
}
if (!checkPermission($admin, 'manage_settings') && $admin['role'] !== 'super_admin') {
    jsonError('无权限管理装扮', 403);
}

$fs = getFS();
$action = (string)($_POST['action'] ?? '');

if ($action === 'list_widgets') {
    $rows = $fs->read('widgets');
    $out = [];
    foreach ((array)$rows as $r) {
        $out[] = [
            'id' => (int)$r['id'],
            'name' => (string)($r['name'] ?? ''),
            'slot' => (string)($r['slot'] ?? ''),
            'css_class' => (string)($r['css_class'] ?? ''),
            'price' => (int)($r['price'] ?? 0),
            'days' => (int)($r['days'] ?? LW_DEFAULT_WIDGET_DAYS),
            'asset' => (string)($r['asset'] ?? ''),
            'desc' => (string)($r['desc'] ?? ''),
            'active' => !empty($r['active']) ? 1 : 0,
            'sort' => (int)($r['sort'] ?? 0),
        ];
    }
    usort($out, function ($a, $b) { return ($b['sort'] <=> $a['sort']) ?: ($a['id'] <=> $b['id']); });
    jsonSuccess(['widgets' => $out]);
}

if ($action === 'upload_asset') {
    // 头像框 / 气泡的图片资源上传。
    // 干什么：把管理员选的 PNG/WebP/JPG 存进 /uploads/widgets/，回传可直接写进 asset 字段的路径。
    // 为什么单独一个动作：save_widget 走的是 urlencoded 表单，塞不下文件；
    // 前端先上传拿路径，再带着 asset 调 save_widget，两步各自可重试、可预览。
    if (!isset($_FILES['file']) || !is_array($_FILES['file'])) {
        jsonError('没有收到文件', 400);
    }
    $f = $_FILES['file'];
    if (($f['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        jsonError('上传失败（错误码 ' . (int)($f['error'] ?? -1) . '）', 400);
    }
    if (($f['size'] ?? 0) <= 0 || $f['size'] > 4 * 1024 * 1024) {
        jsonError('图片需小于 4MB', 400);
    }

    // 白名单 + 魔数双校验：只认真正解码得出来的图片，不信任扩展名
    $info = @getimagesize($f['tmp_name']);
    $allow = [
        IMAGETYPE_PNG => 'png',
        IMAGETYPE_WEBP => 'webp',
        IMAGETYPE_JPEG => 'jpg',
        IMAGETYPE_GIF => 'gif',
    ];
    if (!$info || !isset($allow[$info[2]])) {
        jsonError('只支持 PNG / WebP / JPG / GIF 图片', 400);
    }
    $ext = $allow[$info[2]];

    $dir = UPLOAD_DIR . '/widgets';
    if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
        jsonError('上传目录创建失败', 500);
    }
    // 同目录兜底禁执行：万一主机配置出错，也不能让上传目录里的文件被当脚本跑
    $ht = $dir . '/.htaccess';
    if (!file_exists($ht)) {
        @file_put_contents($ht, "php_flag engine off\nOptions -ExecCGI\nAddType text/plain .php .phtml .php5 .php7 .phar\n");
    }

    $name = 'frame_' . date('Ymd_His') . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
    $dst = $dir . '/' . $name;
    if (!@move_uploaded_file($f['tmp_name'], $dst)) {
        jsonError('保存失败，请检查 uploads 目录权限', 500);
    }
    @chmod($dst, 0644);

    $url = '/uploads/widgets/' . $name;
    logOperation($admin['id'], $admin['qq'], 'upload_widget_asset', 'widget', 0, '上传装扮图片 ' . $name);
    jsonSuccess([
        'asset' => $url,
        'width' => (int)$info[0],
        'height' => (int)$info[1],
    ], '已上传');
}

if ($action === 'save_widget') {
    $id = (int)($_POST['id'] ?? 0);
    $existing = $id > 0 ? $fs->findOne('widgets', ['id' => $id]) : null;
    if ($id > 0 && !$existing) {
        jsonError('装扮不存在', 404);
    }

    $data = [];
    // 编辑时允许只传部分字段（prompt 式编辑），新增时必填
    $nameRaw = $_POST['name'] ?? null;
    if ($nameRaw !== null) {
        $name = trim((string)$nameRaw);
        if ($name === '' || mb_strlen($name) > 40) {
            jsonError('名称需为 1-40 个字符', 400);
        }
        $data['name'] = $name;
    } elseif (!$existing) {
        jsonError('请填写名称', 400);
    }

    if (isset($_POST['slot'])) {
        $slot = (string)$_POST['slot'];
        if (!in_array($slot, LW_WIDGET_SLOTS, true)) {
            jsonError('槽位非法（只能是头像框 / 气泡 / 勋章）', 400);
        }
        $data['slot'] = $slot;
    } elseif (!$existing) {
        jsonError('请选择槽位', 400);
    }

    if (isset($_POST['css_class'])) {
        $css = trim((string)$_POST['css_class']);
        // 样式类只允许字母数字与连字符，防 CSS 注入
        if ($css !== '' && !preg_match('/^[A-Za-z0-9_-]{1,60}$/', $css)) {
            jsonError('样式类只能包含字母、数字、下划线与连字符', 400);
        }
        $data['css_class'] = $css;
    }

    if (isset($_POST['price'])) {
        $price = (int)$_POST['price'];
        if ($price < 0 || $price > 100000) {
            jsonError('价格需在 0-100000 之间', 400);
        }
        $data['price'] = $price;
    }

    if (isset($_POST['days'])) {
        $days = (int)$_POST['days'];
        if ($days < 1 || $days > 3650) {
            jsonError('有效天数需在 1-3650 之间', 400);
        }
        $data['days'] = $days;
    }

    if (isset($_POST['sort'])) {
        $data['sort'] = max(0, min(9999, (int)$_POST['sort']));
    }

    if (isset($_POST['active'])) {
        $data['active'] = ((string)$_POST['active'] === '1') ? 1 : 0;
    }

    if (isset($_POST['desc'])) {
        $data['desc'] = mb_substr(trim((string)$_POST['desc']), 0, 200);
    }

    // asset：图片资源路径。只允许站内 /uploads/ 或 https 外链 ——
    // 单引号/尖括号一律拒绝，因为渲染时会写进 background-image:url('...')
    if (isset($_POST['asset'])) {
        $asset = trim((string)$_POST['asset']);
        if ($asset !== '') {
            if (preg_match('/[\'"<>\\\\]/', $asset) || !preg_match('#^(/uploads/[\w./-]+|https://[\w./?=&%-]+)$#i', $asset)) {
                jsonError('图片路径不合法，请重新上传', 400);
            }
        }
        $data['asset'] = $asset;
    }

    try {
        if ($existing) {
            $fs->update('widgets', $id, $data);
            jsonSuccess(['id' => $id], '已保存');
        } else {
            $data = array_merge([
                'name' => '', 'slot' => 'avatar_frame', 'css_class' => '',
                'price' => 100, 'days' => LW_DEFAULT_WIDGET_DAYS, 'asset' => '',
                'desc' => '', 'active' => 1, 'sort' => 0,
            ], $data);
            $row = $fs->insert('widgets', $data);
            jsonSuccess(['id' => (int)($row['id'] ?? 0)], '已上架');
        }
    } catch (Exception $e) {
        error_log('[love_wall] 装扮保存失败：' . $e->getMessage());
        jsonError('保存失败，请稍后重试', 500);
    }
}

if ($action === 'toggle_widget') {
    $id = (int)($_POST['id'] ?? 0);
    $active = ((string)($_POST['active'] ?? '1') === '1') ? 1 : 0;
    $row = $id > 0 ? $fs->findOne('widgets', ['id' => $id]) : null;
    if (!$row) {
        jsonError('装扮不存在', 404);
    }
    $fs->update('widgets', $id, ['active' => $active]);
    jsonSuccess([], $active ? '已上架' : '已下架');
}

if ($action === 'del_widget') {
    $id = (int)($_POST['id'] ?? 0);
    $row = $id > 0 ? $fs->findOne('widgets', ['id' => $id]) : null;
    if (!$row) {
        jsonError('装扮不存在', 404);
    }
    $fs->delete('widgets', $id);
    jsonSuccess([], '已删除');
}

/**
 * 批量操作（供「商城管理」页使用）。
 *
 * 为什么需要批量：商城里现在有 70+ 件商品，站长做一次活动（比如全场下架重排、
 * 统一调价、把一批 30 天改成 90 天）如果只能一件件点，等于没法运营。
 *
 * 注意安全边界：**只接受 id 数组 + 允许的字段**，任何未知字段直接忽略，
 * 不做「把前端 JSON 整个塞进库」这种事 —— 那是脏数据与提权漏洞的温床。
 */
if ($action === 'bulk_widgets') {
    $ids = $_POST['ids'] ?? '';
    if (is_string($ids)) {
        $ids = array_filter(array_map('intval', explode(',', $ids)));
    } elseif (is_array($ids)) {
        $ids = array_filter(array_map('intval', $ids));
    } else {
        $ids = [];
    }
    $ids = array_values(array_unique($ids));
    if (!$ids) {
        jsonError('请先勾选商品', 400);
    }
    if (count($ids) > 500) {
        jsonError('一次最多操作 500 件', 400);
    }

    $op = (string)($_POST['op'] ?? '');
    $patch = [];
    switch ($op) {
        case 'on':
        case 'off':
            $patch['active'] = $op === 'on' ? 1 : 0;
            break;
        case 'price':
            $price = (int)($_POST['price'] ?? -1);
            if ($price < 0 || $price > 100000) {
                jsonError('价格需在 0-100000 之间', 400);
            }
            $patch['price'] = $price;
            break;
        case 'days':
            $days = (int)($_POST['days'] ?? 0);
            if ($days < 1 || $days > 3650) {
                jsonError('有效期需在 1-3650 天之间', 400);
            }
            $patch['days'] = $days;
            break;
        case 'del':
            break;
        default:
            jsonError('未知的批量操作', 400);
    }

    $cat = $fs->read('widgets');
    $byId = [];
    foreach ((array)$cat as $r) {
        $byId[(int)($r['id'] ?? 0)] = $r;
    }
    $done = 0;
    foreach ($ids as $id) {
        if (!isset($byId[$id])) {
            continue;
        }
        try {
            if ($op === 'del') {
                $fs->delete('widgets', $id);
            } else {
                $fs->update('widgets', $id, $patch);
            }
            $done++;
        } catch (Exception $e) {
            error_log('[love_wall] 批量商城操作失败 id=' . $id . '：' . $e->getMessage());
        }
    }
    jsonSuccess(['done' => $done], '已处理 ' . $done . ' 件');
}

jsonError('未知操作', 400);
