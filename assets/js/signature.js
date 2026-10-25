/**
 * 控制台签名
 *
 * 块状字艺术 + 署名。
 * 图案宽度约 220 列，宽控制台一行显示，窄窗口会自动折行（折行后仍可辨认）。
 * 
 * 注意：这段是纯字符串常量，不做混淆（signature 本来就是给开发者看的）。
 */
(function () {
    'use strict';

    if (window.__lwSignatureShown) return;
    window.__lwSignatureShown = true;

    var art = [
        "████████       ██████     ██      ██   ██████████   ████████                  ████████     ██      ██                  ████████   ██             ██████     ██████████   ██████████",
        "██░░░░░░██   ██ ░░░░░██   ██░     ██░  ██░░░░░░░░░  ██░░░░░░██                ██░░░░░░██    ░██  ██ ░░               ██ ░░░░░░░░  ██░          ██ ░░░░░██    ░░░██░░░░░  ██░░░░░░░░░",
        "████████ ░░  ██░     ██░  ██░ ██  ██░  ████████     ████████ ░░               ████████ ░░     ░██ ░░                  ░██████     ██░          ██████████░      ██░      ████████",
        "██░░░░░░░    ██░     ██░  ████ ░████░  ██░░░░░░░    ██░░░░██░                 ██░░░░░░██       ██░                      ░░░░░██   ██░          ██░░░░░░██░      ██░      ██░░░░░░░",
        "██░           ░██████ ░░  ██░░░  ░██░  ██████████   ██░    ░██                ████████ ░░      ██░                   ████████ ░░  ██████████   ██░     ██░      ██░      ██████████",
        " ░░             ░░░░░░     ░░      ░░   ░░░░░░░░░░   ░░      ░░                ░░░░░░░░         ░░                    ░░░░░░░░     ░░░░░░░░░░   ░░      ░░       ░░       ░░░░░░░░░░"
    ];

    // 逐行双色：主体亮蓝、底部阴影淡一档，接近块状字描边效果
    var mono = 'font-family:ui-monospace,SFMono-Regular,Menlo,Consolas,"Liberation Mono",monospace;' +
               'font-size:11px;line-height:1.05;font-weight:400;letter-spacing:0;white-space:pre;';
    art.forEach(function (row, i) {
        var c = (i === art.length - 1) ? '#7C93B8' : '#5B8AE8';
        console.log('%c' + row, 'color:' + c + ';' + mono);
    });

    console.log('%cPOWER BY SLATE', 'font-family:ui-monospace,Menlo,monospace;font-size:12px;' +
                 'font-weight:700;letter-spacing:.28em;color:#2F5B9A;padding-top:8px;');

    var info = [
        '淮南北师大实验中学高中部 · 校园交流墙',
        '',
        '反馈 bug   https://hnbsd.ct.ws/pages/post.php',
        '看看源码   https://github.com/1740443398/hnbsdlovewall',
        '',
        '不要在这里粘贴或执行别人给你的代码。',
        '这叫 Self-XSS，是真实存在的攻击方式。'
    ].join("\n");
    console.log('%c' + info, 'font-family:ui-monospace,Menlo,monospace;font-size:12px;line-height:1.75;color:#4A5568;');
})();
