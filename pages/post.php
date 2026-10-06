<?php

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/security.php';

$user = requireMember('发布动态需要注册账号后才能使用');
$user = checkBanned($user);

if ($user['is_banned']) {
    header('Location: ' . SITE_URL . '/');
    exit();
}

$csrfToken = generateCSRFToken();

function catSvgIcon($name) {
    $icons = [
        'lost_found' => '<svg width="18" height="18" viewBox="0 0 24 24" fill="none"><defs><linearGradient id="catLost" x1="0%" y1="0%" x2="100%" y2="100%"><stop offset="0%" stop-color="#FF9800"/><stop offset="100%" stop-color="#F57C00"/></linearGradient></defs><circle cx="12" cy="12" r="10" fill="url(#catLost)" stroke="#E65100" stroke-width="1"/><circle cx="9" cy="9" r="2" fill="#fff"/><line x1="12" y1="12" x2="16" y2="16" stroke="#fff" stroke-width="2" stroke-linecap="round"/></svg>',
        'study_help' => '<svg width="18" height="18" viewBox="0 0 24 24" fill="none"><defs><linearGradient id="catStudy" x1="0%" y1="0%" x2="100%" y2="100%"><stop offset="0%" stop-color="#4CAF50"/><stop offset="100%" stop-color="#388E3C"/></linearGradient></defs><rect x="3" y="3" width="18" height="18" rx="3" fill="url(#catStudy)" stroke="#2E7D32" stroke-width="1"/><line x1="8" y1="9" x2="16" y2="9" stroke="#fff" stroke-width="2" stroke-linecap="round"/><line x1="8" y1="13" x2="16" y2="13" stroke="#fff" stroke-width="2" stroke-linecap="round"/><line x1="8" y1="17" x2="12" y2="17" stroke="#fff" stroke-width="2" stroke-linecap="round"/></svg>',
        'social_chat' => '<svg width="18" height="18" viewBox="0 0 24 24" fill="none"><defs><linearGradient id="catSocial" x1="0%" y1="0%" x2="100%" y2="100%"><stop offset="0%" stop-color="#2196F3"/><stop offset="100%" stop-color="#1565C0"/></linearGradient></defs><circle cx="12" cy="12" r="10" fill="url(#catSocial)" stroke="#0D47A1" stroke-width="1"/><circle cx="8" cy="10" r="2" fill="#fff"/><circle cx="12" cy="10" r="2" fill="#fff"/><circle cx="16" cy="10" r="2" fill="#fff"/></svg>',
        'confession' => '<svg width="18" height="18" viewBox="0 0 24 24" fill="none"><defs><linearGradient id="catConfess" x1="0%" y1="0%" x2="100%" y2="100%"><stop offset="0%" stop-color="#E91E63"/><stop offset="100%" stop-color="#AD1457"/></linearGradient></defs><path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2z" fill="url(#catConfess)" stroke="#880E4F" stroke-width="1"/><path d="M12 7c-2.76 0-5 2.24-5 5s2.24 5 5 5 5-2.24 5-5-2.24-5-5-5z" fill="#fff" opacity="0.5"/></svg>',
        'school_info' => '<svg width="18" height="18" viewBox="0 0 24 24" fill="none"><defs><linearGradient id="catSchool" x1="0%" y1="0%" x2="100%" y2="100%"><stop offset="0%" stop-color="#9C27B0"/><stop offset="100%" stop-color="#6A1B9A"/></linearGradient></defs><rect x="2" y="6" width="20" height="16" rx="2" fill="url(#catSchool)" stroke="#4A148C" stroke-width="1"/><polygon points="12,2 4,8 12,8 20,8" fill="url(#catSchool)" stroke="#4A148C" stroke-width="1"/><rect x="8" y="12" width="8" height="3" fill="#fff" opacity="0.5"/></svg>',
        'other' => '<svg width="18" height="18" viewBox="0 0 24 24" fill="none"><defs><linearGradient id="catOther" x1="0%" y1="0%" x2="100%" y2="100%"><stop offset="0%" stop-color="#607D8B"/><stop offset="100%" stop-color="#37474F"/></linearGradient></defs><circle cx="12" cy="12" r="10" fill="url(#catOther)" stroke="#263238" stroke-width="1"/><circle cx="8" cy="12" r="2" fill="#fff"/><circle cx="12" cy="12" r="2" fill="#fff"/><circle cx="16" cy="12" r="2" fill="#fff"/></svg>',
    ];
    return isset($icons[$name]) ? $icons[$name] : '';
}

$categories = [
    'lost_found' => ['name' => '寻物/失物招领', 'icon' => catSvgIcon('lost_found')],
    'study_help' => ['name' => '学习求助', 'icon' => catSvgIcon('study_help')],
    'social_chat' => ['name' => '交友闲聊', 'icon' => catSvgIcon('social_chat')],
    'confession' => ['name' => '表白', 'icon' => catSvgIcon('confession')],
    'school_info' => ['name' => '校园打听', 'icon' => catSvgIcon('school_info')],
    'other' => ['name' => '其他', 'icon' => catSvgIcon('other')],
];

$isAdmin = in_array($user['role'] ?? '', ['admin', 'super_admin']);

if ($isAdmin) {
    $categories = array_merge(['announcement' => ['name' => '全站公告', 'icon' => '<svg width="18" height="18" viewBox="0 0 24 24" fill="none"><defs><linearGradient id="catAnnounce" x1="0%" y1="0%" x2="100%" y2="100%"><stop offset="0%" stop-color="#FF5252"/><stop offset="100%" stop-color="#D32F2F"/></linearGradient></defs><rect x="2" y="2" width="20" height="20" rx="3" fill="url(#catAnnounce)" stroke="#B71C1C" stroke-width="1"/><line x1="12" y1="8" x2="12" y2="12" stroke="#fff" stroke-width="2" stroke-linecap="round"/><line x1="12" y1="16" x2="12.01" y2="16" stroke="#fff" stroke-width="2" stroke-linecap="round"/></svg>']], $categories);
}

$editPostId = intval($_REQUEST['edit'] ?? 0);
$editPost = null;
$isEditMode = false;
if ($editPostId > 0) {
    $fs = getFS();
    $editPost = $fs->findById('posts', $editPostId);
    if ($editPost && ($editPost['user_id'] == $user['id'] || $isAdmin)) {
        $isEditMode = true;
    } else {
        $editPost = null;
    }
}
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <?php require_once __DIR__ . '/../includes/pwa_head.php'; ?>
    <link rel="icon" href="/icon.ico" type="image/x-icon">
    <title>发布帖子 - <?= SITE_NAME ?></title>
    <link rel="stylesheet" href="<?= asset_url('/assets/css/style.css') ?>?v=<?= asset_ver('/assets/css/style.css') ?>">
    <link rel="stylesheet" href="<?= asset_url('/assets/css/enhancements.css') ?>?v=<?= asset_ver('/assets/css/enhancements.css') ?>">
    <style>
        .post-page {
            max-width: 700px;
            margin: 0 auto;
            padding: 24px 16px;
        }
        .post-card {
            background: var(--card-bg);
            border: 1px solid var(--border-glass);
            border-radius: var(--radius-card);
            padding: 32px;
            box-shadow: var(--shadow);
            border-top: 3px solid #C9A96E;
        }
        .post-card h1 {
            font-size: 1.5rem;
            margin-bottom: 24px;
            text-align: center;
            color: var(--primary);
            font-weight: 700;
        }
        .category-selector {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
            margin-bottom: 20px;
        }
        .cat-option {
            display: flex;
            align-items: center;
            gap: 6px;
            padding: 10px 16px;
            border: 2px solid var(--border);
            border-radius: var(--radius-sm);
            cursor: pointer;
            transition: all 0.15s ease;
            font-size: 0.875rem;
            background: var(--bg);
            white-space: nowrap;
            font-weight: 500;
        }
        .cat-option:hover { border-color: var(--primary); background: var(--primary-light); }
        .cat-option.active { border-color: var(--primary); background: var(--primary); color: #fff; font-weight: 600; }
        .form-group { margin-bottom: 18px; }
        .form-group label {
            display: block;
            font-size: 0.85rem;
            font-weight: 600;
            color: var(--text);
            margin-bottom: 6px;
        }
        .form-input {
            width: 100%;
            padding: 10px 14px;
            border: 1px solid var(--border);
            border-radius: var(--radius-sm);
            font-size: 0.9375rem;
            background: var(--bg);
            color: var(--text);
            outline: none;
            min-height: 44px;
        }
        .form-input:focus { border-color: var(--border-focus); box-shadow: 0 0 0 3px rgba(74,144,217,0.15); }
        .form-textarea {
            min-height: 160px;
            resize: vertical;
            line-height: 1.6;
        }
        .char-count {
            text-align: right;
            font-size: 0.75rem;
            color: var(--text-muted);
            margin-top: 4px;
        }
        .char-count.warn { color: var(--warning); }
        .char-count.over { color: var(--danger); }

        /* 智能成稿：入口按钮 + 内联面板（液态玻璃，与站内卡片同一套令牌） */
        .label-with-action {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 10px;
        }
        .compose-trigger {
            display: inline-flex;
            align-items: center;
            gap: 5px;
            padding: 4px 10px;
            font-size: 0.75rem;
            font-weight: 600;
            font-family: inherit;
            color: var(--primary);
            background: var(--primary-light);
            border: 1px solid transparent;
            border-radius: 999px;
            cursor: pointer;
            transition: all 0.2s ease;
        }
        .compose-trigger:hover { border-color: var(--primary); transform: translateY(-1px); }
        .compose-trigger:disabled { opacity: 0.5; cursor: not-allowed; transform: none; }
        .compose-panel {
            padding: 14px;
            border: 1px solid var(--border-glass);
            border-radius: var(--radius-sm);
            background: var(--card-bg);
            -webkit-backdrop-filter: blur(var(--glass-blur, 20px));
            backdrop-filter: blur(var(--glass-blur, 20px));
            box-shadow: var(--shadow-sm, 0 1px 3px rgba(0,0,0,0.04));
        }
        .compose-panel-head {
            display: flex;
            flex-wrap: wrap;
            align-items: baseline;
            gap: 8px;
            margin-bottom: 10px;
        }
        .compose-panel-head strong { font-size: 0.9rem; }
        .compose-panel-head span { font-size: 0.75rem; color: var(--text-muted); }
        .compose-idea {
            min-height: 92px;
            resize: vertical;
            line-height: 1.6;
        }
        .compose-panel-tip {
            margin-top: 6px;
            font-size: 0.75rem;
            color: var(--text-muted);
            line-height: 1.5;
        }
        .compose-panel-tip.warn { color: var(--warning); }
        .compose-panel-actions {
            display: flex;
            justify-content: flex-end;
            gap: 8px;
            margin-top: 10px;
        }
        .anon-toggle {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 12px 16px;
            background: var(--bg);
            border-radius: var(--radius-sm);
            border: 1px solid var(--border);
        }
        .anon-toggle label { margin-bottom: 0; cursor: pointer; }
        .anon-toggle input[type="checkbox"] { width: 18px; height: 18px; accent-color: var(--primary); }
        .image-upload-area {
            border: 2px dashed var(--border);
            border-radius: var(--radius-sm);
            padding: 24px;
            text-align: center;
            cursor: pointer;
            transition: all 0.15s ease;
            background: var(--bg);
        }
        .image-upload-area:hover { border-color: var(--primary); background: var(--primary-light); }
        .image-upload-area input { display: none; }
        .image-preview-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(100px, 1fr));
            gap: 8px;
            margin-top: 12px;
        }
        .image-preview-item {
            position: relative;
            aspect-ratio: 1;
            border-radius: var(--radius-sm);
            overflow: hidden;
            border: 1px solid var(--border);
        }
        .image-preview-item img {
            width: 100%; height: 100%;
            object-fit: cover;
        }
        .image-preview-item .remove-btn {
            position: absolute;
            top: 4px; right: 4px;
            background: var(--danger);
            color: #fff;
            border: none;
            border-radius: 50%;
            width: 24px; height: 24px;
            cursor: pointer;
            font-size: 0.8rem;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        .btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 6px;
            padding: 10px 20px;
            border: none;
            border-radius: var(--radius-sm);
            font-size: 0.875rem;
            font-weight: 600;
            cursor: pointer;
            min-height: 44px;
            font-family: inherit;
            transition: all 0.2s ease;
        }
        .btn-primary { background: var(--primary); color: #fff; }
        .btn-primary:hover { background: var(--primary-hover); transform: translateY(-1px); box-shadow: 0 4px 16px rgba(47,91,154,0.3); }
        .btn-outline { background: transparent; color: var(--text); border: 1px solid var(--border); }
        .btn-outline:hover { border-color: var(--primary); color: var(--primary); }
        .btn-full { width: 100%; }
        .btn:disabled { opacity: 0.5; cursor: not-allowed; }
        .spinner {
            display: inline-block;
            width: 16px; height: 16px;
            border: 2px solid rgba(255,255,255,0.3);
            border-top-color: #fff;
            border-radius: 50%;
            animation: spin 0.6s linear infinite;
        }
        @keyframes spin { to { transform: rotate(360deg); } }
        .alert {
            padding: 12px 16px;
            border-radius: var(--radius-sm);
            font-size: 0.875rem;
            margin-bottom: 16px;
            display: none;
        }
        .alert.show { display: block; }
        .alert-error { background: var(--danger-light); color: var(--danger); border: 1px solid #f5c6cb; }
        .alert-success { background: var(--success-light); color: var(--success); }
        .toast {
            position: fixed;
            top: 20px; left: 50%;
            transform: translateX(-50%);
            padding: 12px 24px;
            border-radius: var(--radius-sm);
            color: #fff;
            font-size: 0.875rem;
            z-index: 2000;
            animation: toastIn 0.3s ease;
            box-shadow: 0 4px 16px rgba(0,0,0,0.15);
        }
        .toast-error { background: var(--danger); }
        .toast-success { background: var(--success); }
        @keyframes toastIn {
            from { opacity: 0; transform: translateX(-50%) translateY(-20px); }
            to { opacity: 1; transform: translateX(-50%) translateY(0); }
        }
        .preview-box {
            background: var(--bg);
            border: 1px solid var(--border);
            border-radius: var(--radius-sm);
            padding: 16px;
            margin-top: 12px;
            display: none;
        }
        .preview-box.show { display: block; }
        .preview-box .preview-title { font-size: 1.1rem; font-weight: 600; margin-bottom: 8px; }
        .preview-box .preview-content { font-size: 0.9rem; color: var(--text-secondary); white-space: pre-wrap; word-break: break-word; }
        .visibility-selector {
            display: flex;
            gap: 8px;
            flex-wrap: wrap;
        }
        .vis-option {
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 4px;
            padding: 12px 16px;
            border: 2px solid var(--border);
            border-radius: var(--radius-sm);
            cursor: pointer;
            transition: all 0.15s ease;
            background: var(--bg);
            flex: 1;
            min-width: 100px;
            text-align: center;
        }
        .vis-option:hover { border-color: var(--primary); }
        .vis-option.active { border-color: var(--primary); background: var(--primary-light); }
        .vis-option input[type="radio"] { display: none; }
        .vis-option .vis-label { font-size: 0.875rem; font-weight: 600; color: var(--text); }
        .vis-option small { font-size: 0.7rem; color: var(--text-muted); }
        .vis-option.active .vis-label { color: var(--primary); }
        .poll-editor {
            border: 1px solid var(--border);
            border-radius: var(--radius-sm);
            padding: 16px;
            background: var(--bg);
            margin-top: 10px;
        }
        .poll-editor-head {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 12px;
        }
        .poll-editor-head .poll-title-tag {
            font-size: 0.9rem;
            font-weight: 700;
            color: var(--primary);
            display: inline-flex;
            align-items: center;
            gap: 6px;
        }
        .poll-editor-head .poll-title-tag svg { flex-shrink: 0; }
        .poll-editor-head .btn-remove-poll {
            background: none;
            border: 1px solid var(--border);
            color: var(--danger);
            border-radius: var(--radius-sm);
            padding: 6px 12px;
            font-size: 0.78rem;
            font-weight: 600;
            cursor: pointer;
            min-height: 36px;
            font-family: inherit;
        }
        .poll-editor-head .btn-remove-poll:hover { background: var(--danger-light); border-color: var(--danger); }
        .poll-question-input {
            width: 100%;
            padding: 10px 14px;
            border: 1px solid var(--border);
            border-radius: var(--radius-sm);
            font-size: 0.875rem;
            background: var(--card-bg);
            color: var(--text);
            outline: none;
            min-height: 44px;
            margin-bottom: 12px;
        }
        .poll-question-input:focus { border-color: var(--border-focus); box-shadow: 0 0 0 3px rgba(74,144,217,0.15); }
        .poll-options-editor { display: flex; flex-direction: column; gap: 8px; }
        .poll-option-row {
            display: flex;
            align-items: center;
            gap: 8px;
        }
        .poll-option-row .form-input { flex: 1 1 auto; min-width: 0; }
        .poll-option-row .btn-del-opt {
            flex-shrink: 0;
            width: 40px;
            height: 44px;
            border: 1px solid var(--border);
            background: none;
            color: var(--text-muted);
            border-radius: var(--radius-sm);
            cursor: pointer;
            font-size: 1rem;
            line-height: 1;
        }
        .poll-option-row .btn-del-opt:hover { color: var(--danger); border-color: var(--danger); background: var(--danger-light); }
        .poll-add-option {
            margin-top: 10px;
        }
        .poll-editor-tip {
            font-size: 0.75rem;
            color: var(--text-muted);
            margin-top: 10px;
        }
        .poll-toggle-row {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 12px 16px;
            background: var(--bg);
            border-radius: var(--radius-sm);
            border: 1px solid var(--border);
            cursor: pointer;
        }
        .poll-toggle-row input[type="checkbox"] { width: 18px; height: 18px; accent-color: var(--primary); }
        @media (max-width: 480px) {
            .post-card { padding: 20px; }
            .category-selector { gap: 6px; }
            .cat-option { padding: 8px 12px; font-size: 0.8rem; }
        }
    </style>
</head>
<body>
    <?php
    $headerBackHref = SITE_URL . '/';
    require __DIR__ . '/../includes/site_header.php';
    ?>

    <?php require __DIR__ . '/../includes/ai_widget.php'; ?>

    <div class="post-page">
        <div class="post-card">
            <h1><?= $isEditMode ? '编辑帖子' : '发布帖子' ?></h1>

            <div class="alert alert-error" id="alertError"></div>

            <form id="postForm" enctype="multipart/form-data" autocomplete="off">
                <input type="hidden" name="csrf_token" value="<?= $csrfToken ?>">

                <div class="form-group">
                    <label>选择分类</label>
                    <div class="category-selector" id="categorySelector">
                        <?php foreach ($categories as $key => $cat): ?>
                        <div class="cat-option" data-cat="<?= $key ?>">
                            <?= $cat['icon'] ?> <?= $cat['name'] ?>
                        </div>
                        <?php endforeach; ?>
                    </div>
                    <input type="hidden" name="category" id="categoryInput" value="">
                </div>

                <div class="form-group">
                    <label for="title" class="label-with-action">
                        <span>标题 <small>(选填)</small></span>
                        <?php if (!$isEditMode): ?>
                        <button type="button" class="compose-trigger" id="composeTrigger">
                            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 3l1.7 4.3L18 9l-4.3 1.7L12 15l-1.7-4.3L6 9l4.3-1.7z"/><path d="M18.5 15.5l.7 1.8 1.8.7-1.8.7-.7 1.8-.7-1.8-1.8-.7 1.8-.7z"/></svg>
                            智能成稿
                        </button>
                        <?php endif; ?>
                    </label>
                    <input type="text" id="title" name="title" class="form-input" placeholder="给帖子起个标题..." maxlength="200">
                </div>

                <div class="form-group" id="composePanel" hidden>
                    <div class="compose-panel">
                        <div class="compose-panel-head">
                            <strong>智能成稿</strong>
                            <span>说清要点即可，自动整理成标题与正文</span>
                        </div>
                        <textarea id="composeIdea" class="form-input compose-idea" maxlength="800"
                            placeholder="例如：昨天下午在图书馆三楼丢了张饭卡，卡号后四位是 8823，捡到请联系我，谢谢。"></textarea>
                        <div class="compose-panel-tip" id="composeCategoryTip"></div>
                        <div class="compose-panel-actions">
                            <button type="button" class="btn btn-outline btn-sm" id="composeCancel">取消</button>
                            <button type="button" class="btn btn-primary btn-sm" id="composeRun">生成</button>
                        </div>
                    </div>
                </div>

                <div class="form-group">
                    <label for="content">内容</label>
                    <textarea id="content" name="content" class="form-input form-textarea" placeholder="写下你想说的..." maxlength="5000"></textarea>
                    <div class="char-count" id="charCount">0 / 5000</div>
                </div>

                <div class="form-group">
                    <div class="poll-toggle-row">
                        <input type="checkbox" id="enablePoll">
                        <label for="enablePoll" style="margin-bottom:0;cursor:pointer;font-weight:600;font-size:0.9rem;">附带一个投票</label>
                    </div>
                    <div class="poll-editor" id="pollEditor" style="display:none;">
                        <div class="poll-editor-head">
                            <span class="poll-title-tag">
                                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M18 20V10"/><path d="M12 20V4"/><path d="M6 20v-6"/></svg>
                                投票设置
                            </span>
                            <button type="button" class="btn-remove-poll" id="removePollBtn">移除投票</button>
                        </div>
                        <input type="text" id="pollQuestion" class="poll-question-input" placeholder="投票标题（必填）" maxlength="200">
                        <div class="poll-options-editor" id="pollOptionsEditor"></div>
                        <button type="button" class="btn btn-outline btn-sm poll-add-option" id="addPollOptionBtn">+ 添加选项</button>
                        <div class="poll-editor-tip">至少2个选项，最多10个，单个选项不超过50字</div>
                    </div>
                </div>

                <div class="form-group">
                    <div class="anon-toggle">
                        <input type="checkbox" id="isAnonymous" name="is_anonymous" value="1">
                        <label for="isAnonymous">匿名发布</label>
                    </div>
                </div>

                <div class="form-group">
                    <label>可见权限 <small>(谁可以看到这条动态)</small></label>
                    <div class="visibility-selector" id="visibilitySelector">
                        <label class="vis-option active">
                            <input type="radio" name="visibility" value="public" checked>
                            <span class="vis-radio"></span>
                            <span class="vis-label">公开</span>
                            <small>所有人可见</small>
                        </label>
                        <label class="vis-option">
                            <input type="radio" name="visibility" value="visible_to">
                            <span class="vis-radio"></span>
                            <span class="vis-label">给谁看</span>
                            <small>仅指定QQ号可见</small>
                        </label>
                        <label class="vis-option">
                            <input type="radio" name="visibility" value="exclude_to">
                            <span class="vis-radio"></span>
                            <span class="vis-label">不给谁看</span>
                            <small>排除指定QQ号</small>
                        </label>
                    </div>
                    <div class="visibility-extra" id="visibleToInput" style="display:none;margin-top:10px;">
                        <label style="font-size:0.8rem;">允许查看的QQ号（多个用逗号分隔）</label>
                        <input type="text" id="visibleTo" name="visible_to" class="form-input" placeholder="如：123456789,987654321">
                    </div>
                    <div class="visibility-extra" id="excludeToInput" style="display:none;margin-top:10px;">
                        <label style="font-size:0.8rem;">排除的QQ号（多个用逗号分隔）</label>
                        <input type="text" id="excludeTo" name="exclude_to" class="form-input" placeholder="如：123456789,987654321">
                    </div>
                </div>

                <div class="form-group">
                    <label>图片 <small>(最多9张，每张不超过2MB)</small></label>
                    <div class="image-upload-area" id="uploadArea">
                        <div>点击上传图片 或 拖拽图片到此处</div>
                        <input type="file" id="imageInput" name="images[]" accept="image/jpeg,image/png,image/gif,image/webp" multiple>
                    </div>
                    <div class="image-preview-grid" id="imagePreview"></div>
                </div>

                <div class="form-group">
                    <button type="button" class="btn btn-outline" id="previewBtn">预览</button>
                    <div class="preview-box" id="previewBox">
                        <div class="preview-title" id="previewTitle"></div>
                        <div class="preview-content" id="previewContent"></div>
                    </div>
                </div>

                <button type="submit" class="btn btn-primary btn-full" id="submitBtn">
                    <span id="submitBtnText">发布帖子</span>
                    <span class="spinner" id="submitBtnSpinner" style="display:none"></span>
                </button>
            </form>
        </div>
    </div>

    <footer class="site-footer">
        <div class="container">
            <p>本平台为学生自发搭建交流平台，不属于淮南市北师大实验中学官方平台</p>
            <p>&copy; 2026 <?= htmlspecialchars(SITE_NAME) ?> · 蕭遞版权所有</p>
            <p>GitHub 开源地址（可点击跳转）：<a href="<?= htmlspecialchars(GITHUB_REPO_URL) ?>" target="_blank" rel="noopener noreferrer"><?= htmlspecialchars(GITHUB_REPO_NAME) ?></a></p>
        </div>
    </footer>

    <?php
    $mobileNavActive = 'compose';
    require __DIR__ . '/../includes/mobile_bottom_nav.php';
    ?>

    <script>
    (function() {
        const SITE_URL = '<?= SITE_URL ?>';
        const CSRF_TOKEN = '<?= $csrfToken ?>';
        // 顶栏兜底脚本（includes/site_header.php）在全局作用域读取 CSRF_TOKEN，
        // 本页不加载 main.js，故需显式暴露给 window，否则退出登录 / 切换主题会缺 token。
        window.CSRF_TOKEN = CSRF_TOKEN;
        const IS_EDIT_MODE = <?= $isEditMode ? 'true' : 'false' ?>;
        const EDIT_POST_ID = <?= $editPostId ?>;
        const EDIT_POST_DATA = <?= $editPost ? json_encode(['category' => $editPost['category'], 'title' => $editPost['title'], 'content' => $editPost['content'], 'is_anonymous' => $editPost['is_anonymous'], 'visibility' => $editPost['visibility'] ?? 'public', 'visible_to' => $editPost['visible_to'] ?? '', 'exclude_to' => $editPost['exclude_to'] ?? '', 'poll' => $editPost['poll'] ?? null], JSON_HEX_TAG | JSON_UNESCAPED_UNICODE) : 'null' ?>;
        let selectedCategory = '';
        let selectedFiles = [];

        function showToast(msg, type) {
            const existing = document.querySelector('.toast');
            if (existing) existing.remove();
            const t = document.createElement('div');
            t.className = 'toast toast-' + type;
            t.textContent = msg;
            document.body.appendChild(t);
            setTimeout(() => { t.style.opacity = '0'; t.style.transition = 'opacity 0.3s'; setTimeout(() => t.remove(), 300); }, 3000);
        }

        function showAlert(msg) {
            const el = document.getElementById('alertError');
            el.textContent = msg;
            el.classList.add('show');
        }

        function hideAlert() {
            document.getElementById('alertError').classList.remove('show');
        }

        function setLoading(btn, textEl, spinnerEl, loading) {
            btn.disabled = loading;
            textEl.style.display = loading ? 'none' : '';
            spinnerEl.style.display = loading ? 'inline-block' : 'none';
        }

        if (IS_EDIT_MODE && EDIT_POST_DATA) {
            selectedCategory = EDIT_POST_DATA.category;
            document.getElementById('categoryInput').value = selectedCategory;
            document.getElementById('title').value = EDIT_POST_DATA.title || '';
            document.getElementById('content').value = EDIT_POST_DATA.content || '';
            if (EDIT_POST_DATA.is_anonymous) {
                document.getElementById('isAnonymous').checked = true;
            }

            const len = EDIT_POST_DATA.content ? EDIT_POST_DATA.content.length : 0;
            document.getElementById('charCount').textContent = len + ' / 5000';

            const vis = EDIT_POST_DATA.visibility || 'public';
            const visRadio = document.querySelector('input[name="visibility"][value="' + vis + '"]');
            if (visRadio) {
                visRadio.checked = true;
                document.querySelectorAll('.vis-option').forEach(o => o.classList.remove('active'));
                visRadio.closest('.vis-option').classList.add('active');
                document.getElementById('visibleToInput').style.display = vis === 'visible_to' ? 'block' : 'none';
                document.getElementById('excludeToInput').style.display = vis === 'exclude_to' ? 'block' : 'none';
                if (vis === 'visible_to') document.getElementById('visibleTo').value = EDIT_POST_DATA.visible_to || '';
                if (vis === 'exclude_to') document.getElementById('excludeTo').value = EDIT_POST_DATA.exclude_to || '';
            }

            document.getElementById('submitBtnText').textContent = '保存修改';

            setTimeout(() => {
                document.querySelectorAll('.cat-option').forEach(o => {
                    o.classList.toggle('active', o.dataset.cat === selectedCategory);
                });
            }, 50);
        }

        document.querySelectorAll('.cat-option').forEach(opt => {
            opt.addEventListener('click', function() {
                document.querySelectorAll('.cat-option').forEach(o => o.classList.remove('active'));
                this.classList.add('active');
                selectedCategory = this.dataset.cat;
                document.getElementById('categoryInput').value = selectedCategory;
            });
        });

        document.querySelectorAll('.vis-option').forEach(opt => {
            opt.addEventListener('click', function() {
                document.querySelectorAll('.vis-option').forEach(o => o.classList.remove('active'));
                this.classList.add('active');
                const radio = this.querySelector('input[type="radio"]');
                radio.checked = true;
                document.getElementById('visibleToInput').style.display = radio.value === 'visible_to' ? 'block' : 'none';
                document.getElementById('excludeToInput').style.display = radio.value === 'exclude_to' ? 'block' : 'none';
            });
        });

        const contentEl = document.getElementById('content');
        const charCount = document.getElementById('charCount');
        contentEl.addEventListener('input', function() {
            const len = this.value.length;
            charCount.textContent = len + ' / 5000';
            charCount.className = 'char-count';
            if (len > 4500) charCount.classList.add('warn');
            if (len > 5000) charCount.classList.add('over');
        });

        contentEl.addEventListener('keydown', function(e) {
            if ((e.ctrlKey || e.metaKey) && e.key === 'Enter') {
                e.preventDefault();
                document.getElementById('postForm').dispatchEvent(new Event('submit'));
            }
        });

        const uploadArea = document.getElementById('uploadArea');
        const imageInput = document.getElementById('imageInput');
        const imagePreview = document.getElementById('imagePreview');

        uploadArea.addEventListener('click', () => imageInput.click());
        uploadArea.addEventListener('dragover', e => { e.preventDefault(); uploadArea.style.borderColor = 'var(--primary)'; });
        uploadArea.addEventListener('dragleave', () => { uploadArea.style.borderColor = 'var(--border)'; });
        uploadArea.addEventListener('drop', e => {
            e.preventDefault();
            uploadArea.style.borderColor = 'var(--border)';
            handleFiles(e.dataTransfer.files);
        });
        imageInput.addEventListener('change', () => handleFiles(imageInput.files));

        function handleFiles(files) {
            const validTypes = ['image/jpeg', 'image/jpg', 'image/png', 'image/gif', 'image/webp'];
            const MAX_SINGLE = 5 * 1024 * 1024;
            const MAX_TOTAL = 10 * 1024 * 1024;
            let totalSize = 0;
            for (const file of files) {
                if (!validTypes.includes(file.type)) { showToast('不支持的图片格式：' + file.name, 'error'); continue; }
                if (file.size > MAX_SINGLE) { showToast('图片超过5MB：' + file.name, 'error'); continue; }
                totalSize += file.size;
                if (totalSize > MAX_TOTAL) { showToast('图片总大小超过10MB', 'error'); break; }
                if (selectedFiles.length >= 9) { showToast('最多上传9张图片', 'error'); break; }
                selectedFiles.push(file);
            }
            renderPreviews();
        }

        function renderPreviews() {
            imagePreview.innerHTML = '';
            selectedFiles.forEach((file, idx) => {
                const reader = new FileReader();
                reader.onload = function(e) {
                    const div = document.createElement('div');
                    div.className = 'image-preview-item';
                    // 必须显式 type="button"：在 <form> 内默认是 submit，
                    // 点「×」删图会顺带把整张帖子提交出去（发布未写完的内容）。
                    div.innerHTML = '<img src="' + e.target.result + '" alt="preview"><button type="button" class="remove-btn" data-idx="' + idx + '">×</button>';
                    imagePreview.appendChild(div);
                };
                reader.readAsDataURL(file);
            });
        }

        imagePreview.addEventListener('click', function(e) {
            if (e.target.classList.contains('remove-btn')) {
                const idx = parseInt(e.target.dataset.idx);
                selectedFiles.splice(idx, 1);
                renderPreviews();
            }
        });

        document.getElementById('previewBtn').addEventListener('click', function() {
            const previewBox = document.getElementById('previewBox');
            const title = document.getElementById('title').value || '(无标题)';
            const content = document.getElementById('content').value;
            if (!content) { showToast('请先输入内容', 'error'); return; }
            previewBox.classList.toggle('show');
            document.getElementById('previewTitle').textContent = title;
            document.getElementById('previewContent').textContent = content;
        });

        // ===== 投票编辑器 =====
        const enablePollEl = document.getElementById('enablePoll');
        const pollEditorEl = document.getElementById('pollEditor');
        const pollOptionsEditor = document.getElementById('pollOptionsEditor');
        const MAX_POLL_OPTIONS = 10;

        function addPollOptionRow(value) {
            const rows = pollOptionsEditor.querySelectorAll('.poll-option-row');
            if (rows.length >= MAX_POLL_OPTIONS) return false;
            const row = document.createElement('div');
            row.className = 'poll-option-row';
            row.innerHTML =
                '<input type="text" class="form-input poll-option-input" placeholder="选项 ' +
                (rows.length + 1) +
                '" maxlength="50" value="' +
                (value ? value.replace(/"/g, '&quot;') : '') +
                '">' +
                '<button type="button" class="btn-del-opt" aria-label="删除选项">×</button>';
            row.querySelector('.btn-del-opt').addEventListener('click', function() {
                const cur = pollOptionsEditor.querySelectorAll('.poll-option-row');
                if (cur.length <= 2) { showToast('投票至少需要2个选项', 'error'); return; }
                row.remove();
                renumberPollOptions();
            });
            pollOptionsEditor.appendChild(row);
            return true;
        }

        function renumberPollOptions() {
            pollOptionsEditor.querySelectorAll('.poll-option-input').forEach(function(inp, idx) {
                inp.placeholder = '选项 ' + (idx + 1);
            });
        }

        addPollOptionRow();
        addPollOptionRow();

        document.getElementById('addPollOptionBtn').addEventListener('click', function() {
            if (!addPollOptionRow()) showToast('投票最多支持10个选项', 'error');
        });

        enablePollEl.addEventListener('change', function() {
            pollEditorEl.style.display = this.checked ? 'block' : 'none';
            if (!this.checked) clearPollEditor();
        });

        document.getElementById('removePollBtn').addEventListener('click', function() {
            enablePollEl.checked = false;
            pollEditorEl.style.display = 'none';
            clearPollEditor();
        });

        function clearPollEditor() {
            document.getElementById('pollQuestion').value = '';
            pollOptionsEditor.innerHTML = '';
            addPollOptionRow();
            addPollOptionRow();
        }

        function collectPollData() {
            if (!enablePollEl.checked) return null;
            const question = document.getElementById('pollQuestion').value.trim();
            if (!question) { showAlert('请填写投票标题'); return null; }
            const options = [];
            pollOptionsEditor.querySelectorAll('.poll-option-input').forEach(function(inp) {
                const v = inp.value.trim();
                if (v) options.push(v);
            });
            if (options.length < 2) { showAlert('投票至少需要2个有效选项'); return null; }
            for (const o of options) {
                if (o.length > 50) { showAlert('单个投票选项不能超过50字'); return null; }
            }
            return { question: question, options: options };
        }

        // 编辑模式：恢复已存在的投票
        if (IS_EDIT_MODE && EDIT_POST_DATA && EDIT_POST_DATA.poll && EDIT_POST_DATA.poll.question) {
            const edPoll = EDIT_POST_DATA.poll;
            enablePollEl.checked = true;
            pollEditorEl.style.display = 'block';
            document.getElementById('pollQuestion').value = edPoll.question;
            pollOptionsEditor.innerHTML = '';
            if (Array.isArray(edPoll.options) && edPoll.options.length) {
                edPoll.options.forEach(function(o) { addPollOptionRow(o); });
            }
        }

        document.getElementById('postForm').addEventListener('submit', function(e) {
            e.preventDefault();
            hideAlert();

            if (!selectedCategory) { showAlert('请选择分类'); return; }
            const content = contentEl.value.trim();
            if (!content) { showAlert('请输入帖子内容'); return; }
            if (content.length > 5000) { showAlert('帖子内容不能超过5000字'); return; }

            const pollData = collectPollData();
            if (pollData === null && enablePollEl.checked) return;

            setLoading(document.getElementById('submitBtn'), document.getElementById('submitBtnText'), document.getElementById('submitBtnSpinner'), true);

            const formData = new FormData();
            formData.append('csrf_token', CSRF_TOKEN);
            formData.append('category', selectedCategory);
            formData.append('title', document.getElementById('title').value.trim());
            formData.append('content', content);
            formData.append('is_anonymous', document.getElementById('isAnonymous').checked ? '1' : '0');
            formData.append('visibility', document.querySelector('input[name="visibility"]:checked').value);
            formData.append('visible_to', document.getElementById('visibleTo').value.trim());
            formData.append('exclude_to', document.getElementById('excludeTo').value.trim());

            if (pollData) {
                formData.append('poll_question', pollData.question);
                formData.append('poll_options', pollData.options.join('\n'));
            }

            if (IS_EDIT_MODE) {
                formData.append('post_id', EDIT_POST_ID);
            }

            selectedFiles.forEach(file => {
                formData.append('images[]', file);
            });

            const apiUrl = IS_EDIT_MODE ? '/api/posts/edit.php' : '/api/posts/create.php';

            fetch(SITE_URL + apiUrl, {
                method: 'POST',
                body: formData,
                headers: { 'X-Requested-With': 'XMLHttpRequest' }
            })
                .then(r => r.json())
                .then(d => {
                    setLoading(document.getElementById('submitBtn'), document.getElementById('submitBtnText'), document.getElementById('submitBtnSpinner'), false);
                    if (d.success) {
                        showToast(d.message, 'success');
                        clearDraft();
                        setTimeout(() => { window.location.href = SITE_URL + '/'; }, 1000);
                    } else {
                        showAlert(d.message);
                    }
                })
                .catch(() => {
                    setLoading(document.getElementById('submitBtn'), document.getElementById('submitBtnText'), document.getElementById('submitBtnSpinner'), false);
                    showAlert('网络错误，请重试');
                });
        });

        function saveDraft() {
            if (!selectedCategory && !document.getElementById('content').value.trim()) return;
            const draft = {
                category: selectedCategory,
                title: document.getElementById('title').value.trim(),
                content: document.getElementById('content').value,
                isAnonymous: document.getElementById('isAnonymous').checked,
                visibility: document.querySelector('input[name="visibility"]:checked').value,
                visibleTo: document.getElementById('visibleTo').value.trim(),
                excludeTo: document.getElementById('excludeTo').value.trim(),
                pollEnabled: enablePollEl.checked,
                pollQuestion: document.getElementById('pollQuestion').value,
                pollOptions: Array.from(pollOptionsEditor.querySelectorAll('.poll-option-input')).map(function(inp) { return inp.value; }),
                savedAt: new Date().toISOString()
            };
            try {
                localStorage.setItem('post_draft', JSON.stringify(draft));
            } catch(e) {}
        }

        function loadDraft() {
            try {
                const raw = localStorage.getItem('post_draft');
                if (!raw) return false;
                const draft = JSON.parse(raw);
                if (!draft || !draft.content) return false;
                if (draft.category) {
                    selectedCategory = draft.category;
                    document.getElementById('categoryInput').value = selectedCategory;
                    document.querySelectorAll('.cat-option').forEach(o => {
                        o.classList.toggle('active', o.dataset.cat === selectedCategory);
                    });
                }
                document.getElementById('title').value = draft.title || '';
                document.getElementById('content').value = draft.content || '';
                document.getElementById('isAnonymous').checked = !!draft.isAnonymous;
                if (draft.pollEnabled) {
                    enablePollEl.checked = true;
                    pollEditorEl.style.display = 'block';
                    document.getElementById('pollQuestion').value = draft.pollQuestion || '';
                    pollOptionsEditor.innerHTML = '';
                    if (Array.isArray(draft.pollOptions) && draft.pollOptions.length) {
                        draft.pollOptions.forEach(function(v) { addPollOptionRow(v); });
                    }
                }
                const cnt = (draft.content || '').length;
                document.getElementById('charCount').textContent = cnt + ' / 5000';
                if (draft.visibility) {
                    const visRadio = document.querySelector('input[name="visibility"][value="' + draft.visibility + '"]');
                    if (visRadio) {
                        visRadio.checked = true;
                        document.querySelectorAll('.vis-option').forEach(o => o.classList.remove('active'));
                        visRadio.closest('.vis-option').classList.add('active');
                        document.getElementById('visibleToInput').style.display = draft.visibility === 'visible_to' ? 'block' : 'none';
                        document.getElementById('excludeToInput').style.display = draft.visibility === 'exclude_to' ? 'block' : 'none';
                        if (draft.visibility === 'visible_to') document.getElementById('visibleTo').value = draft.visibleTo || '';
                        if (draft.visibility === 'exclude_to') document.getElementById('excludeTo').value = draft.excludeTo || '';
                    }
                }
                return true;
            } catch(e) { return false; }
        }

        function clearDraft() {
            try { localStorage.removeItem('post_draft'); } catch(e) {}
        }

        setInterval(saveDraft, 30000);

        let draftTimer = null;
        document.querySelectorAll('#postForm input, #postForm textarea').forEach(function(el) {
            el.addEventListener('input', function() {
                clearTimeout(draftTimer);
                draftTimer = setTimeout(saveDraft, 2000);
            });
        });
        document.querySelectorAll('.cat-option').forEach(el => {
            el.addEventListener('click', function() {
                clearTimeout(draftTimer);
                draftTimer = setTimeout(saveDraft, 1000);
            });
        });
        document.querySelectorAll('.vis-option').forEach(el => {
            el.addEventListener('click', function() {
                clearTimeout(draftTimer);
                draftTimer = setTimeout(saveDraft, 1000);
            });
        });

        if (!IS_EDIT_MODE) {
            var hasDraft = loadDraft();
            if (hasDraft) {
                var draftInfo = document.createElement('div');
                draftInfo.style.cssText = 'background:var(--primary-light);padding:8px 14px;border-radius:var(--radius-sm);margin-bottom:16px;display:flex;align-items:center;justify-content:space-between;font-size:0.85rem;color:var(--primary);';
                // 「清除草稿」按钮同样必须 type="button"：它被插在 #postForm 内部，
                // 默认 submit 会在丢弃草稿的同时把草稿当成新帖发布出去。
                draftInfo.innerHTML = '<span>已恢复上次未发布的草稿</span><button type="button" class="btn btn-outline btn-sm" style="font-size:0.75rem;padding:4px 10px;" onclick="localStorage.removeItem(\'post_draft\');location.reload();">清除草稿</button>';
                document.getElementById('postForm').insertBefore(draftInfo, document.getElementById('postForm').firstChild);
            }
        }

        // ===== 智能成稿 =====
        // 只把「素材」发给服务端，标题与正文由服务端提示词约束后回填；
        // 已有内容时先征求同意再覆盖，避免把用户写了一半的东西冲掉。
        (function initCompose() {
            var trigger = document.getElementById('composeTrigger');
            var panel = document.getElementById('composePanel');
            if (!trigger || !panel) return;

            var ideaEl = document.getElementById('composeIdea');
            var tipEl = document.getElementById('composeCategoryTip');
            var runBtn = document.getElementById('composeRun');
            var cancelBtn = document.getElementById('composeCancel');
            var categoryNames = <?= json_encode(array_map(function ($c) { return $c['name']; }, $categories), JSON_UNESCAPED_UNICODE) ?>;
            var running = false;

            function refreshTip() {
                var name = selectedCategory ? categoryNames[selectedCategory] : '';
                if (name) {
                    tipEl.textContent = '将按「' + name + '」的语气来写。';
                    tipEl.classList.remove('warn');
                } else {
                    tipEl.textContent = '建议先在上面选好分类，成稿会更贴合。';
                    tipEl.classList.add('warn');
                }
            }

            trigger.addEventListener('click', function () {
                panel.hidden = !panel.hidden;
                if (!panel.hidden) {
                    refreshTip();
                    ideaEl.focus();
                }
            });

            cancelBtn.addEventListener('click', function () {
                panel.hidden = true;
            });

            document.querySelectorAll('.cat-option').forEach(function (o) {
                o.addEventListener('click', function () {
                    if (!panel.hidden) refreshTip();
                });
            });

            runBtn.addEventListener('click', function () {
                if (running) return;
                var idea = ideaEl.value.trim();
                if (!idea) { showToast('先用一句话说说你想写什么', 'error'); return; }

                var titleEl = document.getElementById('title');
                var contentEl = document.getElementById('content');
                var hasText = titleEl.value.trim() !== '' || contentEl.value.trim() !== '';
                if (hasText && !confirm('标题或正文已有内容，生成后会替换掉，继续吗？')) return;

                running = true;
                runBtn.disabled = true;
                var oldLabel = runBtn.textContent;
                runBtn.textContent = '生成中…';

                fetch(SITE_URL + '/api/ai_compose.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    credentials: 'same-origin',
                    body: JSON.stringify({ idea: idea, category: selectedCategory || '', csrf_token: CSRF_TOKEN })
                })
                .then(function (r) { return r.json(); })
                .then(function (res) {
                    if (!res || !res.success) {
                        showToast((res && res.message) ? res.message : '生成失败，请稍后再试', 'error');
                        return;
                    }
                    if (res.data.title) titleEl.value = res.data.title;
                    if (res.data.content) contentEl.value = res.data.content;
                    // 手动派发 input 事件，让字数统计与草稿保存照常跟上
                    titleEl.dispatchEvent(new Event('input', { bubbles: true }));
                    contentEl.dispatchEvent(new Event('input', { bubbles: true }));
                    panel.hidden = true;
                    showToast('已生成，可以再手动改一改', 'success');
                })
                .catch(function () { showToast('生成失败，请检查网络后重试', 'error'); })
                .finally(function () {
                    running = false;
                    runBtn.disabled = false;
                    runBtn.textContent = oldLabel;
                });
            });
        })();
    })();
    </script>

    <script>
    (function(){
      // #postFab / #backToTop 已从本页移除（发帖入口在顶栏与移动端底部导航，
      // 回到顶部由 polish.js 的统一浮动按钮提供），对应的绑定逻辑一并删除。
    })();
    </script>
    <?php require_once __DIR__ . '/../includes/lang_ui.php'; ?>
</body>
</html>
