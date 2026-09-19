<?php
require_once __DIR__ . '/../config/config.php';
$adminUser = requireAdmin();
$adminUser = checkBanned($adminUser);
if ($adminUser['is_banned']) {
    header('Location: /pages/403.php');
    exit();
}

$page = 'music.php';
$csrfToken = generateCSRFToken();
$musicDir = __DIR__ . '/../music/';
$error = '';
$success = '';

if (!is_dir($musicDir)) {
    mkdir($musicDir, 0755, true);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if (!verifyCSRFToken($_POST['csrf_token'] ?? '')) {
        $error = 'CSRF验证失败，请刷新页面重试。';
    } else {
        if ($_POST['action'] === 'upload' && isset($_FILES['music_file'])) {
            $file = $_FILES['music_file'];
            $allowedTypes = ['audio/mpeg', 'audio/mp3', 'audio/wav', 'audio/ogg', 'audio/mp4', 'audio/aac', 'audio/flac', 'audio/x-m4a'];
            $maxSize = 20 * 1024 * 1024;

            if ($file['error'] !== UPLOAD_ERR_OK) {
                $error = '文件上传失败，错误代码：' . $file['error'];
            } elseif ($file['size'] > $maxSize) {
                $error = '文件大小超过限制（最大20MB）';
            } elseif (!in_array($file['type'], $allowedTypes) && !preg_match('/\.(mp3|wav|ogg|m4a|aac|flac)$/i', $file['name'])) {
                $error = '不支持的文件格式，仅支持 mp3, wav, ogg, m4a, aac, flac';
            } else {

                $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
                $safeName = bin2hex(random_bytes(16)) . '.' . $ext;
                $targetPath = $musicDir . $safeName;

                if (move_uploaded_file($file['tmp_name'], $targetPath)) {
                    $success = '音乐文件 "' . htmlspecialchars($safeName) . '" 上传成功！';

                    $fs = getFS();
                    try {
                        $fs->insert('operation_logs', [
                            'admin_id' => $adminUser['id'],
                            'action' => 'upload_music',
                            'detail' => '上传音乐文件: ' . $safeName,
                            'time' => date('Y-m-d H:i:s'),
                        ]);
                    } catch (Exception $e) {}
                } else {
                    $error = '文件保存失败，请检查目录权限。';
                }
            }
        } elseif ($_POST['action'] === 'delete' && !empty($_POST['filename'])) {
            $filename = basename($_POST['filename']);
            $filePath = $musicDir . $filename;
            if (file_exists($filePath) && strpos(realpath($filePath), realpath($musicDir)) === 0) {
                if (unlink($filePath)) {
                    $success = '音乐文件 "' . htmlspecialchars($filename) . '" 已删除。';

                    $fs = getFS();
                    try {
                        $fs->insert('operation_logs', [
                            'admin_id' => $adminUser['id'],
                            'action' => 'delete_music',
                            'detail' => '删除音乐文件: ' . $filename,
                            'time' => date('Y-m-d H:i:s'),
                        ]);
                    } catch (Exception $e) {}
                } else {
                    $error = '文件删除失败。';
                }
            } else {
                $error = '文件不存在或路径非法。';
            }
        }
    }
}

$musicFiles = [];
if (is_dir($musicDir)) {
    $files = scandir($musicDir);
    foreach ($files as $file) {
        if ($file === '.' || $file === '..') continue;
        $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
        $mimeMap = [
            'mp3' => 'audio/mpeg',
            'wav' => 'audio/wav',
            'ogg' => 'audio/ogg',
            'm4a' => 'audio/mp4',
            'aac' => 'audio/aac',
            'flac' => 'audio/flac',
        ];
        if (in_array($ext, ['mp3', 'wav', 'ogg', 'm4a', 'aac', 'flac'])) {
            $filePath = $musicDir . $file;
            $musicFiles[] = [
                'name' => $file,
                'size' => filesize($filePath),
                'modified' => date('Y-m-d H:i:s', filemtime($filePath)),
                'mime' => $mimeMap[$ext] ?? 'audio/mpeg',
            ];
        }
    }

    usort($musicFiles, function($a, $b) {
        return strcmp($b['modified'], $a['modified']);
    });
}

function formatSize($bytes) {
    if ($bytes >= 1048576) return round($bytes / 1048576, 2) . ' MB';
    if ($bytes >= 1024) return round($bytes / 1024, 2) . ' KB';
    return $bytes . ' B';
}

$currentMusic = getSetting('current_music', '');
$autoPlay = getSetting('music_autoplay', '1') === '1';
$musicVolume = getSetting('music_volume', '0.3');

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'save_settings') {
    if (!verifyCSRFToken($_POST['csrf_token'] ?? '')) {
        $error = 'CSRF验证失败。';
    } else {
        updateSetting('music_autoplay', isset($_POST['autoplay']) ? '1' : '0');
        updateSetting('music_volume', strval(max(0, min(1, floatval($_POST['volume'] ?? 0.3)))));
        if (!empty($_POST['current_music'])) {
            updateSetting('current_music', $_POST['current_music']);
        }
        $success = '设置已保存。';
        $autoPlay = isset($_POST['autoplay']) ? '1' : '0';
        $musicVolume = $_POST['volume'] ?? '0.3';
        $currentMusic = $_POST['current_music'] ?? '';
    }
}

require_once __DIR__ . '/layout.php';
adminHeader('音乐管理', $adminUser, $csrfToken);
?>

<div class="card">
    <div class="card-header">
        <h2>音乐管理</h2>
        <p class="card-desc">管理网站前端播放的背景音乐，音乐文件存储在 /music/ 目录</p>
    </div>
    <div class="card-body">
        <?php if ($error): ?>
        <div class="alert alert-error"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>
        <?php if ($success): ?>
        <div class="alert alert-success"><?= htmlspecialchars($success) ?></div>
        <?php endif; ?>

        <div class="card">
            <div class="card-header"><h3>播放设置</h3></div>
            <div class="card-body">
                <form method="POST" enctype="multipart/form-data">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
                    <input type="hidden" name="action" value="save_settings">

                    <div class="form-group">
                        <label class="checkbox-label">
                            <input type="checkbox" name="autoplay" value="1" <?= $autoPlay ? 'checked' : '' ?>>
                            <span>打开网站后延迟3秒自动播放音乐</span>
                        </label>
                    </div>

                    <div class="form-group">
                        <label>音量设置 (<?= round(floatval($musicVolume) * 100) ?>%)</label>
                        <input type="range" name="volume" min="0" max="1" step="0.05" value="<?= htmlspecialchars($musicVolume) ?>" style="width:100%;max-width:300px;" oninput="this.nextElementSibling.textContent=Math.round(this.value*100)+'%'">
                        <span style="margin-left:8px;color:var(--text-secondary)"><?= round(floatval($musicVolume) * 100) ?>%</span>
                    </div>

                    <?php if (!empty($musicFiles)): ?>
                    <div class="form-group">
                        <label>默认播放音乐</label>
                        <select name="current_music" style="max-width:400px;">
                            <option value="">随机播放</option>
                            <?php foreach ($musicFiles as $m): ?>
                            <option value="<?= htmlspecialchars($m['name']) ?>" <?= $currentMusic === $m['name'] ? 'selected' : '' ?>>
                                <?= htmlspecialchars($m['name']) ?> (<?= formatSize($m['size']) ?>)
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <?php endif; ?>

                    <button type="submit" class="btn btn-primary">保存设置</button>
                </form>
            </div>
        </div>

        <div class="card" style="margin-top:20px;">
            <div class="card-header"><h3>上传音乐</h3></div>
            <div class="card-body">
                <form method="POST" enctype="multipart/form-data">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
                    <input type="hidden" name="action" value="upload">

                    <div class="form-group">
                        <label>选择音乐文件</label>
                        <input type="file" name="music_file" accept=".mp3,.wav,.ogg,.m4a,.aac,.flac" required>
                        <small style="color:var(--text-muted);display:block;margin-top:4px;">支持 mp3, wav, ogg, m4a, aac, flac 格式，最大20MB</small>
                    </div>

                    <button type="submit" class="btn btn-primary">上传音乐</button>
                </form>
            </div>
        </div>

        <div class="card" style="margin-top:20px;">
            <div class="card-header"><h3>音乐列表 <span class="badge"><?= count($musicFiles) ?> 首</span></h3></div>
            <div class="card-body">
                <?php if (empty($musicFiles)): ?>
                <div class="empty-state">
                    <div class="empty-icon">🎵</div>
                    <p>还没有上传任何音乐文件</p>
                    <p style="color:var(--text-muted);font-size:0.85rem;">上传音乐后，用户可以在网站前端听到背景音乐</p>
                </div>
                <?php else: ?>
                <div class="table-responsive">
                    <table class="table">
                        <thead>
                            <tr>
                                <th>文件名</th>
                                <th>大小</th>
                                <th>修改时间</th>
                                <th>操作</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($musicFiles as $m): ?>
                            <tr>
                                <td>
                                    <span style="display:flex;align-items:center;gap:8px;">
                                        <span style="font-size:1.2rem;">🎵</span>
                                        <span><?= htmlspecialchars($m['name']) ?></span>
                                        <?php if ($m['name'] === $currentMusic): ?>
                                        <span class="badge badge-primary">默认</span>
                                        <?php endif; ?>
                                    </span>
                                </td>
                                <td><?= formatSize($m['size']) ?></td>
                                <td><?= $m['modified'] ?></td>
                                <td>
                                    <div style="display:flex;gap:8px;align-items:center;">
                                        <audio controls style="width:250px;height:32px;" preload="none">
                                            <source src="/music/<?= rawurlencode($m['name']) ?>" type="<?= $m['mime'] ?>">
                                        </audio>
                                        <form method="POST" style="display:inline;" onsubmit="return confirm('确定要删除音乐文件 ' + <?= json_encode($m['name']) ?> + ' 吗？此操作不可恢复。')">
                                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
                                            <input type="hidden" name="action" value="delete">
                                            <input type="hidden" name="filename" value="<?= htmlspecialchars($m['name']) ?>">
                                            <button type="submit" class="btn btn-danger btn-sm">删除</button>
                                        </form>
                                    </div>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<?php adminFooter(); ?>
