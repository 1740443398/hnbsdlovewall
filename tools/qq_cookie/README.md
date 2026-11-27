# QQ 空间 Cookie 自动续期助手

服务器无法凭空生成 skey/p_skey（必须 QQ 登录签发），这个助手用你本机的 Edge
保持 QQ 空间登录，定时把 Cookie 推送到站点（加密落盘）。

## 准备（只做一次）

1. 后台 → QQ 空间配置 → 点「生成 cron_token」，复制到本目录 config.json
2. 填站点地址：

```json
{
  "site": "https://hnbsd.ct.ws",
  "token": "后台生成的 cron_token",
  "uin": "你的QQ号",
  "profile": "C:/Users/你/qq_qzone_profile"
}
```

3. 双击 `login.bat`（首次）：会打开一个独立 Edge 窗口，登录 QQ 空间（扫码），
   登录完成后关闭窗口即可（配置会保留在 profile 目录）。

## 定时推送

- 双击 `push.bat`：读取该 profile 的 QQ Cookie 并 POST 到站点
- 任务计划程序里加一条「每 6 小时运行 push.bat」即可自动续期
- 站点返回 `ok:true` 即成功；失败时会打印原因（未登录/token 错）

## 说明

- Cookie 只会以 AES 加密形式存在服务器上，明文不落盘
- 助手不保存你的 QQ 密码；登录态维护在 Edge 的独立 profile 目录
