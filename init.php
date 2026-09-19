<?php
require_once __DIR__ . '/config/config.php';

$fs = getFS();

$users = $fs->read('users');
if (empty($users)) {
    $fs->insert('users', [
        'qq' => 'admin',
        'password_hash' => password_hash('admin', PASSWORD_BCRYPT),
        'nickname' => '站长',
        'avatar' => '',
        'role' => 'super_admin',
        'must_change_password' => 1,
        'security_stamp' => bin2hex(random_bytes(32)),
        'is_banned' => 0
    ]);
    echo 'Initialization completed. Default super admin account created.'.PHP_EOL;
    echo '账号 username: admin / 密码 password: admin'.PHP_EOL;
    echo '请站长首次登录后立即修改默认密码！'.PHP_EOL;
}

$settings = $fs->read('settings');
$defaultSettings = [
    ['setting_key' => 'site_name', 'setting_value' => '淮南北师大实验中学高中部校园交流墙'],
    ['setting_key' => 'announcement', 'setting_value' => '欢迎来到校园交流墙！请遵守社区规范，文明交流。'],
    ['setting_key' => 'community_rules', 'setting_value' => '1. 禁止发布辱骂、引战、泄露他人隐私的内容\n2. 禁止发布违规广告、校外无关引流内容\n3. 禁止发布低俗色情、违法违规内容\n4. 请文明发言，尊重他人'],
    ['setting_key' => 'maintenance_mode', 'setting_value' => '0'],
    ['setting_key' => 'maintenance_message', 'setting_value' => '网站正在维护中，请稍后再来。'],
    ['setting_key' => 'register_enabled', 'setting_value' => '1'],
];

$existingKeys = array_column($settings, 'setting_key');
foreach ($defaultSettings as $setting) {
    if (!in_array($setting['setting_key'], $existingKeys)) {
        $fs->insert('settings', $setting);
    }
}

$sensitiveWords = $fs->read('sensitive_words');
if (empty($sensitiveWords)) {
    $words = ['傻逼', '操你妈', '滚蛋', '去死', '垃圾', '废物', '畜生', '婊子', '狗日', '杂种'];
    foreach ($words as $word) {
        $fs->insert('sensitive_words', ['word' => $word]);
    }
}

$sponsorRecords = $fs->read('sponsor');
if (empty($sponsorRecords)) {
    $fs->insert('sponsor', [
        'total_amount' => 0,
        'current_amount' => 0,
        'sponsor_list' => '[]'
    ]);
}

echo 'Initialization completed.';
