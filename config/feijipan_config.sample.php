<?php
/**
 * 小飞机网盘配置（示例）
 *
 * 复制成 config/feijipan_config.php 再填自己的账号：
 *   cp config/feijipan_config.sample.php config/feijipan_config.php
 *
 * 这个文件是干什么的
 *   文件传输助手里超过 20MB 的文件，会临时放进这个小飞机网盘账号，
 *   传完立刻删掉，不在网盘长期留存。
 *
 *   没有网盘账号也能用：≤20MB 的小文件走你自己的服务器中转，
 *   不需要这个配置。只有大文件才会用到网盘。
 *
 * folder_id 怎么填
 *   浏览器登录 https://www.feejii.com/ ，进「我的文件」，
 *   点进要放中转文件的目录，地址栏最后那串数字就是 folder_id。
 *   建议单独建一个「服务器文件中转」目录，别直接用根目录。
 *
 * user_id 怎么填
 *   填 0 即可，程序登录后会自动查。
 *
 * 安全提醒
 *   建议单独注册一个小号专用，不要用主账号。
 */
return [
    // 是否启用网盘中转（false 时只支持 20MB 以内的小文件）
    'enabled'  => false,

    // 小飞机网盘登录手机号
    'account'  => '',

    // 登录密码
    'password' => '',

    // 用户 ID（填 0 自动查询）
    'user_id'  => 0,

    // 目标目录 ID（必须是具体目录，留空会导致上传后无法落库）
    'folder_id' => '',
];
