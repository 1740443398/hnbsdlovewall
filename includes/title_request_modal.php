<?php
/**
 * 「申请头衔」交互弹窗 —— 必须渲染在 <header class="site-header"> 之外。
 *
 * 为什么单独成文件、且由 site_header.php 在 </header> 之后 require：
 *   .site-header 在桌面端带 backdrop-filter，而 backdrop-filter 会让元素成为其内部
 *   position:fixed 后代的**包含块**（CSS 规范：filter/backdrop-filter/transform/perspective/
 *   contain:paint 都会创建 containing block）。本弹窗的 .modal-overlay 是 position:fixed;inset:0，
 *   一旦放在 header 内，它的“视口”就变成 header 那个窄框 → 弹窗被关进顶栏、位置错乱。
 *   （移动端过去是靠 ≤768px 时把 header 的 backdrop-filter 置 none 侥幸正常，桌面端一直错位。）
 *   放到 body 层后，fixed 相对真正的视口定位，桌面/移动端一致。参见 site_header.php 顶部注释
 *   对 .sheet-backdrop 的同类处理（同样的原因）。
 *
 * 交互逻辑在 assets/js/main.js 的 initTitleRequest()（按 #titleRequestBtn 挂载）。
 * 未登录用户顶栏没有入口按钮，这里也一并跳过输出。
 */
if (empty($user) || !is_array($user)) {
    return;
}
?>
<div class="modal-overlay" id="titleRequestModal" style="display:none;">
    <div class="modal" style="max-width:460px;">
        <div class="modal-header">
            <h3 class="modal-title"><?= t('tr.title') ?></h3>
            <button type="button" class="modal-close" id="titleReqClose">&times;</button>
        </div>
        <div class="modal-body">
            <p style="font-size:0.85rem;color:var(--text-secondary);margin-bottom:12px;"><?= t('tr.desc') ?></p>
            <div class="form-group">
                <label class="form-label" for="trTitle"><?= t('tr.field') ?></label>
                <input type="text" id="trTitle" class="form-input" maxlength="20" placeholder="<?= t('tr.ph') ?>">
            </div>
            <div class="form-group">
                <label class="form-label" for="trReason"><?= t('tr.reason') ?></label>
                <textarea id="trReason" class="form-input" maxlength="200" rows="3" placeholder="<?= t('tr.reason_ph') ?>"></textarea>
            </div>
        </div>
        <div class="modal-footer">
            <button type="button" class="btn btn-outline" id="trCancel"><?= t('tr.cancel') ?></button>
            <button type="button" class="btn btn-primary" id="trSubmit"><?= t('tr.submit') ?></button>
        </div>
    </div>
</div>
