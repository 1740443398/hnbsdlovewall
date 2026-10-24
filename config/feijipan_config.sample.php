<?php
/**
 * 小飞机网盘配置（示例）
 *
 * 复制成 config/feijipan_config.php 再填自己的账号。
 *   cp config/feijipan_config.sample.php config/feijipan_config.php
 *
 * 这个文件是用来干什么的：
 *   文件传输助手里超过 20MB 的文件，会临时放到这个小飞机网盘账号，
 *   传完立刻删掉，不在网盘长期留存。
 *
 * 没有网盘账号也能用：小文件（≤20MB）走你自己的服务器中转，
 * 不需要这个配置。只有大文件才会用到。
 *
 * 怎么拿到 folder_id：
 *   浏览器登录 https://www.feejii.com/，进「我的文件」，
 *   点进你要放中转文件的目录，地址栏最后的数字就是 folder_id。
 *   建议单独建一个「服务器文件中转」目录，别用根目录。
 *
 * 怎么拿到 user_id：
 *   登录后按 F12 打开控制台，执行：
 *     fetch('/app/user/info/map')
 *   或者直接看后台「测试连接」的提示。
 *   不知道就填 0，程序会自动去查。
 */
return [
    // 是否启用网盘中转（false 就只支持 20MB 以内的小文件）
    'enabled'  => false,

    // 小飞机网盘登录手机号
    'account'  => '',

    // 登录密码
    'password' => '',

    // 用户 ID（填 0 会自动查）
    'user_id'  => 0,

    // 目标目录 ID（必须是具体目录，别留空）
    'folder_id' => '',
];
