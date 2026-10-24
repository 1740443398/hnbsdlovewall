<?php
/**
 * 多语言（i18n）加载器。
 * 支持简体中文(zh_CN 默认)、繁体中文(zh_TW)、英语(en)。
 * 语言来源优先级：?lang= 查询参数（语言切换时用） > lovewall_lang Cookie > 会话 > 默认 zh_CN。
 *
 * 提供：
 *   $LANG_CODE  当前语言代码（如 'zh_CN' / 'zh_TW' / 'en'）
 *   $LANG       当前语言的翻译数组
 *   t($key)     取当前语言下的翻译；缺失时回退到简体中文，再缺失返回键名本身
 */

function get_lang_code() {
    $map = [
        'zh-cn' => 'zh_CN', 'zh_cn' => 'zh_CN', 'zh-cn-hans' => 'zh_CN', 'zh-hans' => 'zh_CN', 'zh' => 'zh_CN',
        'zh-tw' => 'zh_TW', 'zh_tw' => 'zh_TW', 'zh-hant' => 'zh_TW', 'zh-hk' => 'zh_TW',
        'en' => 'en', 'en-us' => 'en', 'en-gb' => 'en',
    ];
    $q = strtolower((string)($_GET['lang'] ?? ''));
    if ($q !== '' && isset($map[$q])) {
        $code = $map[$q];
    } elseif (isset($_COOKIE['lovewall_lang']) && isset($map[strtolower((string)$_COOKIE['lovewall_lang'])])) {
        $code = $map[strtolower((string)$_COOKIE['lovewall_lang'])];
    } elseif (isset($_SESSION['lang']) && isset($map[strtolower((string)$_SESSION['lang'])])) {
        $code = $map[strtolower((string)$_SESSION['lang'])];
    } elseif (!empty($_SERVER['HTTP_ACCEPT_LANGUAGE'])) {
        // 根据浏览器首选语言粗略推断（仅当浏览器明确是繁中/英文才切换，其余默认简体）
        $al = strtolower($_SERVER['HTTP_ACCEPT_LANGUAGE']);
        if (strpos($al, 'zh-tw') !== false || strpos($al, 'zh-hk') !== false || strpos($al, 'zh-hant') !== false) {
            $code = 'zh_TW';
        } elseif (strpos($al, 'en') !== false && strpos($al, 'zh') === false) {
            $code = 'en';
        } else {
            $code = 'zh_CN';
        }
    } else {
        $code = 'zh_CN';
    }

    // 若本次通过 ?lang= 指定，则写回 cookie 与会话，让下次请求沿用
    if ($q !== '' && isset($map[$q])) {
        $_SESSION['lang'] = $code;
        setcookie('lovewall_lang', $code, time() + 31536000, '/', '', defined('IS_SECURE') && IS_SECURE, true);
    }
    return $code;
}

$LANG_CODE = get_lang_code();

$langFiles = [
    'zh_CN' => __DIR__ . '/../lang/zh_CN.php',
    'zh_TW' => __DIR__ . '/../lang/zh_TW.php',
    'en'     => __DIR__ . '/../lang/en.php',
];
$LANG = is_file($langFiles[$LANG_CODE]) ? require $langFiles[$LANG_CODE] : require $langFiles['zh_CN'];

// 简体中文作为兜底
$LANG_FALLBACK = is_file($langFiles['zh_CN']) ? require $langFiles['zh_CN'] : [];

/**
 * 翻译函数。用法：<?= t('nav.profile') ?>
 */
function t($key) {
    global $LANG, $LANG_FALLBACK;
    if (isset($LANG[$key])) {
        return $LANG[$key];
    }
    if (isset($LANG_FALLBACK[$key])) {
        return $LANG_FALLBACK[$key];
    }
    return $key;
}

// 暴露给 JS：页面头部内联脚本会读取这些全局常量
$GLOBALS['LANG_CODE'] = $LANG_CODE;
