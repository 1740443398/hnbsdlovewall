<?php
/**
 * 自包含页面的暗色令牌 —— 只给**不加载 style.css** 的页面用
 * （login / register / gateway / forgot_password / maintenance）。
 *
 * 为什么需要单独一份：
 *   这些页面为了「首屏最轻」，各自在 <style> 里用 :root 定义了一套浅色令牌，
 *   不引入全站的 style.css，因此拿不到 style.css 里的 .dark-theme 覆盖。
 *   主题类由 includes/theme_boot.php 挂在 <html> 上，本文件用 `html.dark-theme`
 *   （特异度高于 `:root`）覆盖同名令牌，无需改动这些页面的原有样式规则。
 *
 * 用法：在 theme_boot.php 之后、页面自己的 <style> 之前 require 本文件即可。
 */
?>
<style>
html.dark-theme {
  color-scheme: dark;
  --primary: #5B8CC9;
  --primary-hover: #6E9AD3;
  --primary-dark: #3E6698;
  --primary-light: #1F2E44;
  --accent: #C9A96E;
  --accent-light: #2A2416;
  --danger: #E06A64;
  --danger-light: #331C1B;
  --success: #4CAF7D;
  --success-light: #173026;
  --warning: #D0A24C;
  --warning-light: #2E2617;
  --bg: #0F1520;
  --bg-secondary: #171E2B;
  --card-bg: #1B2434;
  --text: #E4E9F0;
  --text-secondary: #9AA7B8;
  --text-muted: #6C7A8C;
  --border: #2A3748;
  --border-light: #222E3E;
  --border-focus: #4E7FBF;
  --shadow: 0 2px 10px rgba(0, 0, 0, 0.45);
  --shadow-md: 0 10px 30px rgba(0, 0, 0, 0.5);
  --shadow-hover: 0 8px 24px rgba(0, 0, 0, 0.5);
}
/* 这些页面把浅色令牌写进 :root，html 自身没有背景；这里补上深色底与文字色 */
html.dark-theme body { background: var(--bg); color: var(--text); }
</style>