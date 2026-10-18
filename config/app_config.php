<?php
/**
 * 安卓客户端（App）发布配置
 * ------------------------------------------------------------------
 * 发新版本时只需要改这个文件：
 *   1. 把新的 version_code 加 1（必须比旧版本大，App 靠它判断要不要更新）；
 *   2. 修改 version_name 与 notes；
 *   3. 把打包好的 APK 放进 /download/ 目录，并把文件名填到 apk_file；
 *   4. 把 APK 的 SHA-256 填到 sha256（App 下载后会校验，留空则接口运行时现算）。
 *
 * 站点「用户菜单 → 安装应用」与 App 的「检查更新」都以本文件为准。
 */
return [
    'version_code' => 20202,
    'version_name' => '2.2.2',
    'apk_file'     => 'lovewall-v2.2.2.apk',
    'sha256'       => 'ffed16d6b5e6318dfcd9ee160d85b8b1cd2e831bf960b89d3b6c0af161670e86',
    'notes'        => "修复冷启动/后台被系统拉起时 WorkManager 未初始化导致的闪退；头衔兑换后直达设置页",
];
