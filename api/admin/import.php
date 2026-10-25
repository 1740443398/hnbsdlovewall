<?php
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../includes/backup_pack.php';
require_once __DIR__ . '/../../includes/backup_chunk.php';

$user = requireSuperAdmin();
$user = checkBanned($user);
if ($user['is_banned']) {
    jsonError('账号已被封禁');
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonError('请求方法不允许，请使用POST请求', 405);
}

// 上传体积超过 php.ini 的 post_max_size 时，PHP 会丢掉整个请求体：
// $_POST / $_FILES 双双为空，后面只会报「CSRF验证失败」，误导性极强。这里先认出来。
if (empty($_POST) && empty($_FILES) && (int)($_SERVER['CONTENT_LENGTH'] ?? 0) > 0) {
    jsonError('上传的备份包超过了服务器允许的上限（post_max_size = ' . ini_get('post_max_size') . '），请调大该配置或改用「受限下载」分卷留档');
}

$csrfToken = $_POST['csrf_token'] ?? '';
if (!verifyCSRFToken($csrfToken)) {
    jsonError('CSRF验证失败', 403);
}

/* ── 分卷上传（大备份包专用）─────────────────────────────────────────
 *
 * 【为什么必须有这条路径】
 * 线上主机的网关会拒绝约 1.5MB 以上的 POST 请求体，返回纯文本 403，
 * PHP 根本收不到请求。而完整备份包动辄 10MB+，直接上传必然失败。
 *
 * 这和 CSRF 失败、权限不足的 403 长得一模一样，控制台只显示
 * "responded with a status of 403"，极难排查 —— 所以改成切块上传，
 * 每块 1MB，稳定通过网关。
 */
$chunkAction = (string)($_POST['action'] ?? '');
if ($chunkAction !== '') {
    $token = (string)($_POST['token'] ?? '');

    if ($chunkAction === 'init') {
        $size   = (int)($_POST['size'] ?? 0);
        $chunks = (int)($_POST['chunks'] ?? 0);
        $name   = (string)($_POST['name'] ?? 'backup.zip');
        $res = lwBackupChunkInit($name, $size, $chunks);
        if (empty($res['ok'])) {
            jsonError((string)($res['message'] ?? '初始化失败'));
        }
        jsonSuccess(['token' => $res['token']], '可以开始上传');
    }

    if ($chunkAction === 'chunk') {
        $index = (int)($_POST['index'] ?? 0);
        $tmp = (string)($_FILES['chunk']['tmp_name'] ?? '');
        if ($tmp === '') {
            jsonError('分卷内容为空');
        }
        $res = lwBackupChunkStore($token, $index, $tmp);
        if (empty($res['ok'])) {
            jsonError((string)($res['message'] ?? '分卷保存失败'));
        }
        jsonSuccess([
            'received' => (int)($res['received'] ?? 0),
            'total'    => (int)($res['total'] ?? 0),
        ], '分卷已收到');
    }

    if ($chunkAction === 'finish') {
        $res = lwBackupChunkFinish($token);
        if (empty($res['ok'])) {
            jsonError((string)($res['message'] ?? '合并失败'));
        }
        /* 合并成功 → 把路径交给下面的正常导入流程。
         *
         * 【重要：这里绝对不能删文件】
         * readFullBackup() 只是把「数据表」读进内存，图片条目只记下
         * ZIP 里的路径 —— 真正的图片还原是后面 restoreUploads()
         * 重新打开这个 zip 逐条流式解压的。
         *
         * 之前这里写了一句 @unlink($importPath) 想「用完就删」，
         * 结果 zip 在 restoreUploads 之前就没了：数据表导入成功，
         * 图片却全部报错，页面显示「N 个图片写入失败」。
         * 删文件必须挪到导入全部结束之后。
         */
        $importPath = (string)$res['path'];
        $sourceName = (string)$res['name'];
        $parsed = BackupPack::readFullBackup($importPath, $sourceName);
        $importData = $parsed['tables'];
        $uploadEntries = $parsed['uploads'];
        // 只清掉分卷目录里的残留，合并出的 merged.zip 还要用
        if (empty($importData) && empty($uploadEntries)) {
            @unlink($importPath);
            lwBackupChunkAbort($token);
            jsonError('无法从该备份包中解析出内容');
        }
        $chunkCleanupToken = $token;   // 稍后统一清理
        // 跳到统一导入逻辑
        goto lw_import_run;
    }

    if ($chunkAction === 'abort') {
        lwBackupChunkAbort($token);
        jsonSuccess([], '已取消上传');
    }

    jsonError('未知的分卷操作');
}

// 完整备份导入：覆盖式写入指定数据表 + 还原 uploads/ 图片（高风险，仅限超级管理员）
//
// 三种入参（互为兼容）：
//   1) 上传备份包  file=@xxx.zip（推荐；完整包内含数据表 + uploads/ 图片，一次还原全部）
//   2) 上传旧版数据 ZIP / 图片 ZIP（没有 uploads 条目时只还原数据）
//   3) 直接提交 JSON 文本 data=<JSON字符串>（旧路径，保留兼容）
$importData = [];
$sourceName = '';
$uploadEntries = [];
$importPath = '';
$chunkCleanupToken = '';   // 分卷合并出的临时文件，导入结束后清理

if (!empty($_FILES['file']['tmp_name']) && is_uploaded_file($_FILES['file']['tmp_name'])) {
    $size = (int)($_FILES['file']['size'] ?? 0);
    if ($size > MAX_BACKUP_IMPORT_SIZE) {
        jsonError('备份文件超过 ' . round(MAX_BACKUP_IMPORT_SIZE / 1048576, 1) . 'MB 上限，请拆分后再导入');
    }
    $sourceName = (string)($_FILES['file']['name'] ?? 'backup.zip');

    $importPath = (string)$_FILES['file']['tmp_name'];
    $parsed = BackupPack::readFullBackup($importPath, $sourceName);
    $importData = $parsed['tables'];
    $uploadEntries = $parsed['uploads'];
    if (empty($importData) && empty($uploadEntries)) {
        jsonError('无法从该文件中解析出备份内容（支持完整备份包 .zip 与 .json 备份文件）');
    }
} else {
    $payload = $_POST['data'] ?? '';
    if ($payload === '') {
        jsonError('请上传备份文件（.zip / .json）');
    }
    $decoded = json_decode($payload, true);
    if (!is_array($decoded)) {
        jsonError('导入数据格式不正确，请使用由“完整备份”导出的文件');
    }
    $importData = $decoded;
    $sourceName = 'inline-json';
}

lw_import_run:
$fs = getFS();

// 允许导入的数据表（白名单，防止写入任意文件）
$allowedTables = BackupPack::allowedTables();

$errors = [];
$skipped = [];
$done = 0;

foreach ($importData as $table => $rows) {
    if ($table === '_meta') continue;
    // 不在白名单里的表 = **有意跳过**（例如 _ip_registry / device_ips 这类环境相关表），
    // 要和「写入失败」区分开报告，否则每次导入都会显示一堆假失败吓人。
    if (!in_array($table, $allowedTables, true)) {
        $skipped[] = $table;
        continue;
    }
    if (!is_array($rows)) {
        $errors[] = $table;
        continue;
    }
    if ($fs->write($table, $rows)) {
        $done++;
    } else {
        $errors[] = $table;
    }
}

// ── 图片还原：完整备份包里的 uploads/ 条目按原相对路径写回 ────────────────
// 只接受图片后缀白名单（见 BackupPack::allowedUploadExts），路径经过 .. / 绝对路径 / 隐藏文件过滤，
// 且最终落点必须仍在 uploads/ 内，杜绝从备份包写出可执行脚本。
$uploadResult = ['written' => 0, 'skipped' => 0, 'bytes' => 0, 'errors' => []];
if ($uploadEntries && $importPath !== '') {
    $uploadResult = BackupPack::restoreUploads($importPath, $uploadEntries);
}

/* 现在才允许删合并出的 zip —— 图片已经流式写入 uploads/ 了。 */
if (!empty($chunkCleanupToken)) {
    @unlink($importPath);
    lwBackupChunkAbort((string)$chunkCleanupToken);
    $chunkCleanupToken = '';
}

logOperation($user['id'], $user['qq'], 'import_data', 'full_backup', '', '从 ' . $sourceName . ' 导入网站数据，成功表数 ' . $done . '，图片 ' . $uploadResult['written'] . ' 个' . (empty($errors) ? '' : '，失败: ' . implode(',', $errors)));

$msg = '已从 ' . $sourceName . ' 恢复 ' . $done . ' 张表';
if ($uploadResult['written'] > 0 || $uploadResult['errors']) {
    $msg .= '、' . $uploadResult['written'] . ' 个图片文件';
}
if (!empty($skipped)) {
    $msg .= '（' . count($skipped) . ' 张表按白名单跳过）';
}
if (!empty($errors)) {
    $msg .= '，' . count($errors) . ' 张表写入失败';
}
if (!empty($uploadResult['errors'])) {
    $msg .= '，' . count($uploadResult['errors']) . ' 个图片写入失败';
}

jsonSuccess([
    'imported_tables' => $done,
    'failed_tables' => $errors,
    'skipped_tables' => $skipped,
    'restored_uploads' => $uploadResult['written'],
    'skipped_uploads' => $uploadResult['skipped'],
    'failed_uploads' => count($uploadResult['errors']),
    'source' => $sourceName,
], ($done > 0 || $uploadResult['written'] > 0) ? $msg : '备份导入失败，未写入任何内容');