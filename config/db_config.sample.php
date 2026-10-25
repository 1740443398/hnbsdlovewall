<?php
/**
 * 数据库连接配置（示例）
 *
 * 复制本文件为 db_config.php 并填入自己的信息。
 * db_config.php 已被 .gitignore 排除，不会提交。
 *
 * 如果不想用数据库：把 enabled 设为 false，
 * 站点会自动回退到 includes/filestorage.php 的 JSON 平文件存储。
 */
return [
    "enabled" => false,
    "host" => "localhost",
    "port" => 3306,
    "user" => "your_user",
    "pass" => "your_password",
    "name" => "your_database",
    "prefix" => "lw_",
];