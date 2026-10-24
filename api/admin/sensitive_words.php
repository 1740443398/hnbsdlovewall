<?php
/**
 * 后台 —— 敏感词库管理接口。
 *
 * action=list          分页 + 关键词搜索
 * action=add           新增（支持一次粘贴多行，批量导入）
 * action=update        修改某条
 * action=delete        删除某条
 * action=batch_delete  批量删除
 *
 * 设计取舍：本站的敏感词判定是**子串匹配**（checkSensitiveWords 用 mb_stripos），
 * 所以过短或过于通用的词会造成大量误伤（例如把「的」加进去全站都发不出去）。
 * 因此这里强制：词长 >= 2 且 <= 30，并且**自动去重**。
 */
require_once __DIR__ . '/../../config/config.php';

$admin = requireAdmin();
$admin = checkBanned($admin);
if (!empty($admin['is_banned'])) {
    jsonError('账号已被封禁');
}

if (!verifyCSRFToken($_POST['csrf_token'] ?? '')) {
    jsonError('CSRF验证失败', 403);
}

if (!checkPermission($admin, 'manage_sensitive_words')) {
    jsonError('无权限管理敏感词库', 403);
}

$fs     = getFS();
$action = $_POST['action'] ?? 'list';

/** 词条规范化：去空白、限长、拒绝过短 */
function lwSwNormalize(string $w): string
{
    $w = trim(preg_replace('/\s+/u', ' ', $w));
    return mb_substr($w, 0, 30);
}

/** 词条合法性校验，返回错误文案或空串 */
function lwSwValidate(string $w): string
{
    $len = mb_strlen($w);
    if ($len < 2) {
        return '敏感词至少 2 个字（单字会造成大面积误伤）';
    }
    if ($len > 30) {
        return '敏感词最长 30 个字';
    }
    return '';
}

// ---------------------------------------------------------------- 列表
if ($action === 'list') {
    $page    = max(1, (int)($_POST['page'] ?? 1));
    $limit   = 50;
    $keyword = trim((string)($_POST['keyword'] ?? ''));

    $words = (array)$fs->read('sensitive_words');
    $words = array_values(array_filter($words, function ($w) {
        return !empty($w['word']);
    }));

    if ($keyword !== '') {
        $kw = mb_strtolower($keyword);
        $words = array_filter($words, function ($w) use ($kw) {
            return mb_strpos(mb_strtolower((string)$w['word']), $kw) !== false;
        });
        $words = array_values($words);
    }

    // 按 id 升序（保持加入顺序，便于管理员对照）
    usort($words, function ($a, $b) { return (int)$a['id'] <=> (int)$b['id']; });

    $total = count($words);
    $slice = array_slice($words, ($page - 1) * $limit, $limit);

    $list = array_map(function ($w) {
        $len = mb_strlen((string)$w['word']);
        return [
            'id'    => (int)$w['id'],
            'word'  => (string)$w['word'],
            'len'   => $len,
            'risky' => $len <= 2,       // 前端提示「短词易误伤」
        ];
    }, $slice);

    jsonSuccess([
        'list'        => $list,
        'total'       => $total,
        'page'        => $page,
        'total_pages' => max(1, (int)ceil($total / $limit)),
    ]);
}

// ---------------------------------------------------------------- 新增 / 批量导入
if ($action === 'add') {
    $raw = (string)($_POST['words'] ?? $_POST['word'] ?? '');
    // 支持换行 / 英文逗号 / 中文逗号 / 分号 分隔，方便直接粘贴一整份词表
    $parts = preg_split('/[\r\n,，;；]+/u', $raw);
    $parts = array_filter(array_map('lwSwNormalize', $parts), function ($w) {
        return $w !== '';
    });

    if (!$parts) {
        jsonError('请填写至少一个敏感词');
    }

    $existing = [];
    foreach ((array)$fs->read('sensitive_words') as $w) {
        if (!empty($w['word'])) {
            $existing[mb_strtolower((string)$w['word'])] = true;
        }
    }

    $added = 0;
    $skipped = [];
    $rejected = [];
    foreach ($parts as $w) {
        $err = lwSwValidate($w);
        if ($err !== '') {
            $rejected[] = $w . '（' . $err . '）';
            continue;
        }
        $key = mb_strtolower($w);
        if (isset($existing[$key])) {
            $skipped[] = $w;
            continue;
        }
        if ($fs->insert('sensitive_words', ['word' => $w])) {
            $existing[$key] = true;
            $added++;
        }
    }

    if ($added === 0) {
        $msg = $rejected ? ('全部未添加：' . implode('；', array_slice($rejected, 0, 3))) : '这些词已存在，未重复添加';
        jsonError($msg);
    }

    logOperation($admin['id'], $admin['qq'], 'add_sensitive_words', 'settings', '', '新增敏感词 ' . $added . ' 个');

    $tips = [];
    if ($skipped) {
        $tips[] = '跳过重复 ' . count($skipped) . ' 个';
    }
    if ($rejected) {
        $tips[] = '忽略不合法 ' . count($rejected) . ' 个';
    }
    jsonSuccess(['added' => $added], '已添加 ' . $added . ' 个敏感词' . ($tips ? '（' . implode('，', $tips) . '）' : ''));
}

// ---------------------------------------------------------------- 修改
if ($action === 'update') {
    $id = (int)($_POST['id'] ?? 0);
    $word = lwSwNormalize((string)($_POST['word'] ?? ''));
    if ($id <= 0) {
        jsonError('缺少词条 ID');
    }
    $err = lwSwValidate($word);
    if ($err !== '') {
        jsonError($err);
    }
    $row = $fs->findById('sensitive_words', $id);
    if (!$row) {
        jsonError('该词条不存在', 404);
    }
    // 查重（排除自己）
    foreach ((array)$fs->read('sensitive_words') as $w) {
        if ((int)$w['id'] !== $id && mb_strtolower((string)$w['word']) === mb_strtolower($word)) {
            jsonError('已存在相同的敏感词');
        }
    }
    if (!$fs->update('sensitive_words', $id, ['word' => $word])) {
        jsonError('保存失败，请稍后重试');
    }
    logOperation($admin['id'], $admin['qq'], 'update_sensitive_word', 'settings', $id, '改为：' . $word);
    jsonSuccess(['id' => $id, 'word' => $word], '已更新');
}

// ---------------------------------------------------------------- 删除
if ($action === 'delete') {
    $id = (int)($_POST['id'] ?? 0);
    if ($id <= 0) {
        jsonError('缺少词条 ID');
    }
    $row = $fs->findById('sensitive_words', $id);
    if (!$row) {
        jsonError('该词条不存在', 404);
    }
    if (!$fs->delete('sensitive_words', $id)) {
        jsonError('删除失败，请稍后重试');
    }
    logOperation($admin['id'], $admin['qq'], 'delete_sensitive_word', 'settings', $id, '删除：' . (string)$row['word']);
    jsonSuccess([], '已删除');
}

// ---------------------------------------------------------------- 批量删除
if ($action === 'batch_delete') {
    $ids = json_decode((string)($_POST['ids'] ?? '[]'), true);
    if (!is_array($ids) || !$ids) {
        jsonError('请先选择要删除的词条');
    }
    $ids = array_values(array_unique(array_map('intval', $ids)));
    if (count($ids) > 500) {
        jsonError('单次最多删除 500 条');
    }
    $ok = 0;
    foreach ($ids as $id) {
        if ($id > 0 && $fs->delete('sensitive_words', $id)) {
            $ok++;
        }
    }
    if ($ok === 0) {
        jsonError('没有删除任何词条');
    }
    logOperation($admin['id'], $admin['qq'], 'batch_delete_sensitive_words', 'settings', '', '批量删除敏感词 ' . $ok . ' 个');
    jsonSuccess(['deleted' => $ok], '已删除 ' . $ok . ' 个敏感词');
}

// ---------------------------------------------------------------- 测试器
// 输入一段文本，返回会命中的词与命中位置 —— 让管理员**发之前就能预判**，
// 不用真的发一条帖子被拦了才知道词配错了。
if ($action === 'test') {
    $text = (string)($_POST['text'] ?? '');
    if (trim($text) === '') {
        jsonSuccess(['hits' => [], 'count' => 0, 'clean' => true], '文本为空');
    }

    // 复用前台同一套判定函数，保证「后台说会拦」=「前台真的会拦」
    $found = checkSensitiveWords($text);

    $hits = [];
    foreach ($found as $word) {
        $positions = [];
        $offset = 0;
        // 找出所有出现位置（前端据此高亮）
        while (($pos = mb_stripos($text, $word, $offset)) !== false) {
            $positions[] = $pos;
            $offset = $pos + max(1, mb_strlen($word));
            if (count($positions) >= 20) {
                break;                          // 同一个词最多标 20 处，防极端文本卡死
            }
        }
        $hits[] = [
            'word'      => $word,
            'count'     => count($positions),
            'positions' => $positions,
        ];
    }

    jsonSuccess([
        'hits'  => $hits,
        'count' => count($hits),
        'clean' => count($hits) === 0,
    ], count($hits) === 0 ? '未命中任何敏感词，可以正常发布' : ('命中 ' . count($hits) . ' 个敏感词，发布将被拦截'));
}

jsonError('未知操作');
