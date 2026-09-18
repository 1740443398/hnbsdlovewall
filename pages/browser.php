<?php

require_once __DIR__ . '/../config/config.php';

$pageTitle = '网页导航 - ' . (getSetting('site_name', '校园交流墙'));
$currentUser = getCurrentUser();
$isLoggedIn = $currentUser !== null;
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($pageTitle) ?></title>
    <link rel="icon" href="/favicon.svg" type="image/svg+xml">
    <style>

        :root {
            --browser-sidebar-w: 280px;
            --browser-toolbar-h: 52px;
            --browser-search-h: 48px;

            --bg: #F8F9FA;
            --bg-secondary: #F0F2F5;
            --card-bg: #FFFFFF;
            --card-bg-hover: #F5F7FA;
            --text: #1B2A3A;
            --text-secondary: #5A6B7A;
            --text-muted: #8B95A5;
            --border: #DDE2E8;
            --border-light: #EEF1F4;
            --primary: #1B3A5C;
            --primary-hover: #2A5078;
            --primary-light: #E8EFF5;
            --primary-dark: #142B44;
            --shadow-sm: 0 1px 3px rgba(0,0,0,0.06);
            --shadow: 0 4px 16px rgba(0,0,0,0.08);
            --shadow-md: 0 8px 24px rgba(0,0,0,0.10);
            --shadow-lg: 0 16px 48px rgba(0,0,0,0.12);
            --tooltip-bg: #1B2A3A;
            --tooltip-text: #FFFFFF;
            --overlay-bg: rgba(0,0,0,0.4);
            --scrollbar-thumb: #C4CDD5;
            --scrollbar-track: transparent;
        }

        [data-theme="dark"] {
            --bg: #0F1620;
            --bg-secondary: #151D2B;
            --card-bg: #1A2535;
            --card-bg-hover: #1F2D42;
            --text: #E2E8F0;
            --text-secondary: #94A3B8;
            --text-muted: #64748B;
            --border: #2A3A52;
            --border-light: #1F2E42;
            --primary: #4A7FB5;
            --primary-hover: #5A8FC5;
            --primary-light: rgba(74,127,181,0.12);
            --primary-dark: #3A6FA5;
            --shadow-sm: 0 1px 3px rgba(0,0,0,0.3);
            --shadow: 0 4px 16px rgba(0,0,0,0.4);
            --shadow-md: 0 8px 24px rgba(0,0,0,0.5);
            --shadow-lg: 0 16px 48px rgba(0,0,0,0.6);
            --tooltip-bg: #E2E8F0;
            --tooltip-text: #0F1620;
            --overlay-bg: rgba(0,0,0,0.7);
            --scrollbar-thumb: #3A4A6A;
            --scrollbar-track: transparent;
        }

        *{margin:0;padding:0;box-sizing:border-box;}
        html,body{height:100%;overflow:hidden;}
        body.browser-page{
            font-family:'Noto Sans SC','PingFang SC','Microsoft YaHei',sans-serif;
            background:var(--bg);
            color:var(--text);
            transition:background 0.3s,color 0.3s;
            -webkit-font-smoothing:antialiased;
        }
        ::-webkit-scrollbar{width:5px;}
        ::-webkit-scrollbar-track{background:var(--scrollbar-track);}
        ::-webkit-scrollbar-thumb{background:var(--scrollbar-thumb);border-radius:10px;}
        ::-webkit-scrollbar-thumb:hover{background:var(--text-muted);}

        .browser-layout{
            display:flex;
            height:100vh;
            height:100dvh;
        }
        .browser-sidebar-overlay{
            display:none;
            position:fixed;
            inset:0;
            background:var(--overlay-bg);
            z-index:99;
            opacity:0;
            transition:opacity 0.3s;
        }
        .browser-sidebar-overlay.show{
            display:block;
            opacity:1;
        }

        .browser-sidebar{
            width:var(--browser-sidebar-w);
            background:var(--card-bg);
            border-right:1px solid var(--border);
            display:flex;
            flex-direction:column;
            flex-shrink:0;
            overflow:hidden;
            transition:background 0.3s,border-color 0.3s;
            z-index:2;
        }
        .browser-sidebar-header{
            padding:14px 16px;
            border-bottom:1px solid var(--border);
            display:flex;
            align-items:center;
            gap:10px;
            flex-shrink:0;
            transition:border-color 0.3s;
        }
        .browser-logo{
            width:38px;height:38px;
            border-radius:10px;
            background:linear-gradient(135deg, #1B3A5C, #C9A96E);
            display:flex;align-items:center;justify-content:center;
            flex-shrink:0;
            box-shadow:0 2px 8px rgba(102,126,234,0.3);
        }
        .browser-logo svg{width:20px;height:20px;}
        .browser-sidebar-title{
            font-size:1rem;font-weight:700;
            color:var(--text);
            white-space:nowrap;
        }
        .browser-sidebar-actions{
            margin-left:auto;
            display:flex;gap:6px;
        }
        .browser-icon-btn{
            width:30px;height:30px;
            border-radius:6px;
            border:1px solid var(--border);
            background:var(--bg);
            cursor:pointer;
            display:flex;align-items:center;justify-content:center;
            color:var(--text-secondary);
            transition:all 0.2s;
            flex-shrink:0;
            padding:0;
        }
        .browser-icon-btn:hover{
            border-color:var(--primary);
            color:var(--primary);
            background:var(--primary-light);
        }
        .browser-icon-btn svg{width:14px;height:14px;}

        .browser-search-wrap{
            padding:10px 12px;
            border-bottom:1px solid var(--border);
            flex-shrink:0;
            transition:border-color 0.3s;
        }
        .browser-search-input{
            width:100%;
            height:34px;
            padding:0 12px 0 34px;
            border:1px solid var(--border);
            border-radius:17px;
            font-size:0.8rem;
            background:var(--bg);
            color:var(--text);
            outline:none;
            font-family:inherit;
            transition:all 0.2s;
        }
        .browser-search-input:focus{
            border-color:var(--primary);
            box-shadow:0 0 0 3px var(--primary-light);
        }
        .browser-search-input::placeholder{color:var(--text-muted);}
        .browser-search-icon{
            position:absolute;
            left:22px;top:50%;
            transform:translateY(-50%);
            color:var(--text-muted);
            pointer-events:none;
        }
        .browser-search-wrap{position:relative;}
        .browser-search-clear{
            position:absolute;
            right:18px;top:50%;
            transform:translateY(-50%);
            width:18px;height:18px;
            border-radius:50%;
            border:none;
            background:var(--text-muted);
            color:var(--card-bg);
            cursor:pointer;
            display:none;
            align-items:center;justify-content:center;
            font-size:10px;line-height:1;
            padding:0;
        }
        .browser-search-clear.visible{display:flex;}

        .browser-recent-wrap{
            padding:6px 12px;
            border-bottom:1px solid var(--border);
            flex-shrink:0;
            transition:border-color 0.3s;
        }
        .browser-recent-header{
            display:flex;align-items:center;justify-content:space-between;
            font-size:0.7rem;
            color:var(--text-muted);
            text-transform:uppercase;
            letter-spacing:0.5px;
            margin-bottom:4px;
        }
        .browser-recent-clear{
            background:none;border:none;
            color:var(--text-muted);
            cursor:pointer;
            font-size:0.7rem;
            font-family:inherit;
            padding:2px 6px;
            border-radius:4px;
            transition:all 0.15s;
        }
        .browser-recent-clear:hover{color:var(--danger,#d9534f);background:var(--danger-light,#fdecec);}
        .browser-recent-list{
            display:flex;flex-wrap:wrap;gap:4px;
        }
        .browser-recent-tag{
            display:flex;align-items:center;gap:4px;
            padding:3px 8px;
            border-radius:12px;
            font-size:0.7rem;
            background:var(--bg);
            color:var(--text-secondary);
            cursor:pointer;
            border:1px solid var(--border);
            transition:all 0.15s;
            white-space:nowrap;
            max-width:130px;
            overflow:hidden;
            text-overflow:ellipsis;
        }
        .browser-recent-tag:hover{
            background:var(--primary-light);
            color:var(--primary);
            border-color:var(--primary);
        }
        .browser-recent-tag .recent-favicon{
            width:14px;height:14px;border-radius:2px;flex-shrink:0;
        }
        .browser-recent-empty{
            font-size:0.7rem;
            color:var(--text-muted);
            text-align:center;
            padding:4px 0;
        }

        .browser-sidebar-nav{
            flex:1;
            overflow-y:auto;
            padding:4px 0;
        }
        .browser-cat-group{margin-bottom:2px;}
        .browser-cat-header{
            display:flex;align-items:center;gap:8px;
            padding:10px 16px;
            cursor:pointer;
            font-size:0.8rem;font-weight:600;
            color:var(--text-secondary);
            transition:all 0.15s;
            border:none;background:none;
            width:100%;text-align:left;
            font-family:inherit;
            user-select:none;
        }
        .browser-cat-header:hover{background:var(--bg);color:var(--text);}
        .browser-cat-header .cat-icon{
            width:18px;height:18px;
            display:flex;align-items:center;justify-content:center;
            flex-shrink:0;
        }
        .browser-cat-header .cat-arrow{
            margin-left:auto;
            transition:transform 0.25s cubic-bezier(0.4,0,0.2,1);
            color:var(--text-muted);
            flex-shrink:0;
        }
        .browser-cat-group.open .cat-arrow{transform:rotate(90deg);}
        .browser-cat-sites{
            display:grid;
            grid-template-rows:0fr;
            transition:grid-template-rows 0.3s cubic-bezier(0.4,0,0.2,1);
        }
        .browser-cat-group.open .browser-cat-sites{grid-template-rows:1fr;}
        .browser-cat-sites-inner{overflow:hidden;padding:0 8px;}
        .browser-cat-group.open .browser-cat-sites-inner{padding-bottom:6px;}
        .browser-site-item{
            display:flex;align-items:center;gap:10px;
            padding:8px 12px;border-radius:8px;
            cursor:pointer;
            transition:all 0.15s;
            font-size:0.8rem;color:var(--text);
            border:none;background:none;
            width:100%;text-align:left;
            font-family:inherit;
        }
        .browser-site-item:hover{
            background:var(--bg);
            transform:translateX(2px);
        }
        .browser-site-item.active{
            background:var(--primary-light);
            color:var(--primary);
            font-weight:600;
        }
        .browser-site-item .site-favicon{
            width:26px;height:26px;border-radius:6px;
            display:flex;align-items:center;justify-content:center;
            flex-shrink:0;
            background:var(--bg);
            overflow:hidden;
            position:relative;
        }
        .browser-site-item .site-favicon img{
            width:18px;height:18px;
            object-fit:contain;
        }
        .browser-site-item .site-favicon-letter{
            position:absolute;
            top:0;left:0;
            width:26px;height:26px;
            border-radius:6px;
            display:none;
            align-items:center;
            justify-content:center;
            font-size:12px;
            font-weight:700;
            color:#fff;
        }
        .browser-site-info{flex:1;min-width:0;}
        .browser-site-name{
            font-size:0.8rem;
            white-space:nowrap;overflow:hidden;text-overflow:ellipsis;
        }
        .browser-site-desc{
            font-size:0.7rem;color:var(--text-muted);
            white-space:nowrap;overflow:hidden;text-overflow:ellipsis;
        }
        .browser-no-results{
            text-align:center;
            padding:20px;
            color:var(--text-muted);
            font-size:0.8rem;
        }

        .browser-main{
            flex:1;
            display:flex;flex-direction:column;
            min-width:0;
            background:var(--bg);
        }

        .browser-toolbar{
            height:var(--browser-toolbar-h);
            display:flex;align-items:center;gap:6px;
            padding:0 12px;
            background:var(--card-bg);
            border-bottom:1px solid var(--border);
            flex-shrink:0;
            transition:background 0.3s,border-color 0.3s;
        }
        .browser-nav-btn{
            width:34px;height:34px;
            border-radius:8px;
            border:1px solid var(--border);
            background:var(--bg);
            cursor:pointer;
            display:flex;align-items:center;justify-content:center;
            color:var(--text-secondary);
            transition:all 0.15s;
            flex-shrink:0;
            padding:0;
            position:relative;
        }
        .browser-nav-btn:hover:not(:disabled){
            background:var(--card-bg-hover);
            color:var(--text);
            border-color:var(--text-muted);
        }
        .browser-nav-btn:disabled{opacity:0.3;cursor:not-allowed;}
        .browser-nav-btn svg{width:16px;height:16px;}

        .browser-url-bar-wrap{
            flex:1;position:relative;
        }
        .browser-url-bar{
            width:100%;height:34px;
            padding:0 40px 0 14px;
            border:1px solid var(--border);
            border-radius:17px;
            font-size:0.8rem;
            background:var(--bg);
            color:var(--text);
            outline:none;
            font-family:'SF Mono','Fira Code','Consolas',monospace;
            transition:all 0.2s;
        }
        .browser-url-bar:focus{
            border-color:var(--primary);
            background:var(--card-bg);
            box-shadow:0 0 0 3px var(--primary-light);
        }
        .browser-url-lock{
            position:absolute;
            right:12px;top:50%;
            transform:translateY(-50%);
            color:var(--text-muted);
            pointer-events:none;
        }
        .browser-url-lock svg{width:14px;height:14px;}

        .browser-progress{
            position:absolute;
            top:0;left:0;right:0;
            height:3px;
            background:var(--primary);
            z-index:10;
            transform:scaleX(0);
            transform-origin:left;
            opacity:0;
            transition:opacity 0.2s;
        }
        .browser-progress.loading{
            opacity:1;
            animation:browserProgress 2s ease-in-out infinite;
        }
        @keyframes browserProgress{
            0%{transform:scaleX(0);}
            30%{transform:scaleX(0.4);}
            60%{transform:scaleX(0.75);}
            90%{transform:scaleX(0.92);}
            100%{transform:scaleX(0.98);}
        }
        .browser-progress.done{
            opacity:1;
            transform:scaleX(1);
            animation:none;
            transition:transform 0.3s,opacity 0.3s 0.3s;
        }
        .browser-progress.done.hide{opacity:0;}

        .browser-sidebar-toggle{
            display:none;
            width:34px;height:34px;
            border-radius:8px;
            border:1px solid var(--border);
            background:var(--bg);
            cursor:pointer;
            align-items:center;justify-content:center;
            color:var(--text-secondary);
            flex-shrink:0;
            padding:0;
        }

        .browser-frame-wrap{
            flex:1;position:relative;
            background:#fff;
        }
        [data-theme="dark"] .browser-frame-wrap{background:#111;}
        .browser-frame{
            width:100%;height:100%;
            border:none;display:block;
        }
        .browser-empty{
            display:flex;
            flex-direction:column;
            align-items:center;justify-content:center;
            height:100%;
            color:var(--text-muted);
            gap:16px;
            position:absolute;
            inset:0;
            background:var(--bg);
            z-index:1;
            transition:background 0.3s;
        }
        .browser-empty-logo{
            width:90px;height:90px;
            border-radius:22px;
            background:linear-gradient(135deg, #1B3A5C, #C9A96E);
            display:flex;align-items:center;justify-content:center;
            box-shadow:0 8px 32px rgba(102,126,234,0.25);
            animation:floatLogo 3s ease-in-out infinite;
        }
        @keyframes floatLogo{
            0%,100%{transform:translateY(0);}
            50%{transform:translateY(-8px);}
        }
        .browser-empty-logo svg{width:42px;height:42px;}
        .browser-empty-title{
            font-size:1.2rem;font-weight:700;
            color:var(--text);
        }
        .browser-empty-hint{
            font-size:0.85rem;text-align:center;line-height:1.8;
            color:var(--text-secondary);
        }
        .browser-empty-hint kbd{
            display:inline-block;
            padding:2px 7px;
            border-radius:4px;
            background:var(--bg-secondary);
            color:var(--text-secondary);
            font-size:0.75rem;
            font-family:'SF Mono','Fira Code','Consolas',monospace;
            border:1px solid var(--border);
        }

        .browser-empty-popular{
            margin-top:8px;
            width:100%;
            max-width:520px;
            padding:0 16px;
        }
        .browser-empty-popular .popular-title{
            font-size:0.75rem;
            font-weight:600;
            color:var(--text-muted);
            text-align:center;
            margin-bottom:10px;
            text-transform:uppercase;
            letter-spacing:0.5px;
        }
        .browser-empty-popular .popular-grid{
            display:grid;
            grid-template-columns:repeat(4,1fr);
            gap:8px;
        }
        .browser-empty-popular .popular-card{
            display:flex;
            flex-direction:column;
            align-items:center;
            gap:6px;
            padding:12px 8px;
            border-radius:12px;
            border:1px solid var(--border);
            background:var(--card-bg);
            cursor:pointer;
            transition:all 0.2s;
            text-decoration:none;
            color:var(--text);
        }
        .browser-empty-popular .popular-card:hover{
            border-color:var(--primary);
            background:var(--primary-light);
            transform:translateY(-2px);
            box-shadow:var(--shadow-sm);
        }
        .browser-empty-popular .popular-card .popular-favicon-wrap{
            width:40px;height:40px;
            border-radius:10px;
            display:flex;
            align-items:center;
            justify-content:center;
            flex-shrink:0;
            overflow:hidden;
            position:relative;
        }
        .browser-empty-popular .popular-card .popular-favicon-wrap img{
            width:24px;height:24px;
            object-fit:contain;
        }
        .browser-empty-popular .popular-card .popular-favicon-letter{
            position:absolute;
            top:0;left:0;
            width:40px;height:40px;
            border-radius:10px;
            display:none;
            align-items:center;
            justify-content:center;
            font-size:16px;
            font-weight:700;
            color:#fff;
        }
        .browser-empty-popular .popular-card .popular-name{
            font-size:0.72rem;
            font-weight:500;
            color:var(--text);
            text-align:center;
            white-space:nowrap;
            overflow:hidden;
            text-overflow:ellipsis;
            max-width:100%;
        }
        @media (max-width:768px){
            .browser-empty-popular .popular-grid{
                grid-template-columns:repeat(3,1fr);
                gap:6px;
            }
            .browser-empty-popular .popular-card{
                padding:10px 6px;
            }
            .browser-empty-popular .popular-card .popular-favicon-wrap{
                width:34px;height:34px;
                border-radius:8px;
            }
            .browser-empty-popular .popular-card .popular-favicon-wrap img{
                width:20px;height:20px;
            }
            .browser-empty-popular .popular-card .popular-favicon-letter{
                width:34px;height:34px;
                border-radius:8px;
                font-size:14px;
            }
            .browser-empty-popular .popular-card .popular-name{
                font-size:0.68rem;
            }
        }
        @media (max-width:480px){
            .browser-empty-popular .popular-grid{
                grid-template-columns:repeat(3,1fr);
            }
        }

        .browser-frame-error{
            position:absolute;
            top:50%;left:50%;
            transform:translate(-50%,-50%);
            text-align:center;
            display:none;
            background:var(--card-bg);
            padding:32px 40px;
            border-radius:16px;
            box-shadow:var(--shadow-lg);
            z-index:2;
            max-width:400px;
            width:90%;
        }
        .browser-frame-error .error-icon{
            width:56px;height:56px;
            border-radius:50%;
            background:var(--danger-light,#fdecec);
            display:flex;align-items:center;justify-content:center;
            margin:0 auto 16px;
        }
        .browser-frame-error .error-icon svg{width:24px;height:24px;color:var(--danger,#d9534f);}
        .browser-frame-error .error-title{
            font-size:1.05rem;font-weight:700;
            color:var(--text);
            margin-bottom:6px;
        }
        .browser-frame-error .error-hint{
            font-size:0.8rem;color:var(--text-muted);
            margin-bottom:16px;line-height:1.5;
        }
        .browser-frame-error .error-btn{
            padding:8px 20px;
            background:var(--primary);
            color:#fff;
            border:none;border-radius:8px;
            cursor:pointer;
            font-size:0.8rem;
            font-family:inherit;
            font-weight:600;
            transition:all 0.2s;
        }
        .browser-frame-error .error-btn:hover{
            background:var(--primary-hover);
            transform:translateY(-1px);
            box-shadow:0 4px 12px rgba(74,144,217,0.3);
        }

        .browser-kbd-hint{
            position:absolute;
            bottom:6px;right:6px;
            font-size:0.65rem;
            color:var(--text-muted);
            background:var(--card-bg);
            padding:2px 8px;
            border-radius:4px;
            pointer-events:none;
            opacity:0;
            transition:opacity 0.2s;
            z-index:1;
        }
        .browser-nav-btn:hover .browser-kbd-hint,
        .browser-nav-btn:focus-visible .browser-kbd-hint{
            opacity:0;
        }
        .browser-toolbar .browser-kbd-hint{
            position:absolute;
            bottom:-18px;
            left:50%;
            transform:translateX(-50%);
            white-space:nowrap;
            opacity:0;
            transition:opacity 0.15s;
            font-size:0.6rem;
        }
        .browser-nav-btn:hover .browser-kbd-hint{opacity:1;}

        .dark-toggle{
            position:relative;
            width:44px;height:24px;
            border-radius:12px;
            background:var(--bg-secondary);
            border:1px solid var(--border);
            cursor:pointer;
            transition:all 0.3s;
            flex-shrink:0;
            padding:0;
        }
        .dark-toggle-thumb{
            position:absolute;
            top:2px;left:2px;
            width:18px;height:18px;
            border-radius:50%;
            background:var(--primary);
            transition:all 0.3s cubic-bezier(0.4,0,0.2,1);
            display:flex;align-items:center;justify-content:center;
        }
        .dark-toggle-thumb svg{width:10px;height:10px;color:#fff;}
        [data-theme="dark"] .dark-toggle-thumb{
            left:22px;
            background:#f0ad4e;
        }

        .browser-toast{
            position:fixed;
            bottom:24px;left:50%;
            transform:translateX(-50%) translateY(100px);
            background:var(--tooltip-bg);
            color:var(--tooltip-text);
            padding:10px 20px;
            border-radius:20px;
            font-size:0.8rem;
            z-index:200;
            transition:transform 0.3s cubic-bezier(0.4,0,0.2,1);
            pointer-events:none;
            box-shadow:var(--shadow-md);
        }
        .browser-toast.show{transform:translateX(-50%) translateY(0);}

        @media (max-width:768px){
            :root{--browser-sidebar-w:280px;--browser-toolbar-h:48px;}
            .browser-sidebar{
                position:fixed;left:0;top:0;bottom:0;
                z-index:100;
                transform:translateX(-100%);
                transition:transform 0.3s cubic-bezier(0.4,0,0.2,1);
                box-shadow:var(--shadow-lg);
            }
            .browser-sidebar.mobile-open{transform:translateX(0);}
            .browser-sidebar-toggle{display:flex;}
            .browser-toolbar{gap:4px;padding:0 8px;}
            .browser-nav-btn{width:32px;height:32px;}
            .browser-url-bar{font-size:0.75rem;height:32px;}
            .browser-empty-logo{width:70px;height:70px;border-radius:18px;}
            .browser-empty-logo svg{width:32px;height:32px;}
            .browser-empty-title{font-size:1rem;}
            .browser-empty-hint{font-size:0.75rem;}
            .browser-kbd-hint{display:none;}
        }
        @media (max-width:480px){
            :root{--browser-sidebar-w:260px;}
            .browser-toolbar{gap:2px;padding:0 4px;}
            .browser-nav-btn{width:30px;height:30px;border-radius:6px;}
            .browser-url-bar{font-size:0.7rem;}
        }
    </style>
</head>
<body class="browser-page">

<div class="browser-layout">

    <div class="browser-sidebar-overlay" id="sidebarOverlay"></div>

    <aside class="browser-sidebar" id="browserSidebar">
        <div class="browser-sidebar-header">
            <div class="browser-logo">
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none">
                    <circle cx="12" cy="12" r="10" fill="none" stroke="#fff" stroke-width="2"/>
                    <path d="M2 12h20M12 2a15.3 15.3 0 014 10 15.3 15.3 0 01-4 10 15.3 15.3 0 01-4-10 15.3 15.3 0 014-10z" fill="none" stroke="#fff" stroke-width="1.5" stroke-linejoin="round"/>
                    <circle cx="12" cy="12" r="3" fill="rgba(255,255,255,0.4)"/>
                </svg>
            </div>
            <span class="browser-sidebar-title">网页导航</span>
            <div class="browser-sidebar-actions">
                <button class="dark-toggle" id="darkToggle" title="切换深色模式" aria-label="切换深色模式">
                    <span class="dark-toggle-thumb">
                        <svg viewBox="0 0 24 24" fill="none"><path d="M21 12.79A9 9 0 1111.21 3 7 7 0 0021 12.79z" fill="currentColor"/></svg>
                    </span>
                </button>
                <a href="/index.php" class="browser-icon-btn" title="返回首页">
                    <svg viewBox="0 0 24 24" fill="none"><path d="M3 9l9-7 9 7v11a2 2 0 01-2 2H5a2 2 0 01-2-2z" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/><polyline points="9 22 9 12 15 12 15 22" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>
                </a>
            </div>
        </div>

        <div class="browser-search-wrap">
            <span class="browser-search-icon">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none"><circle cx="11" cy="11" r="8" fill="none" stroke="currentColor" stroke-width="2"/><line x1="21" y1="21" x2="16.65" y2="16.65" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg>
            </span>
            <input class="browser-search-input" id="siteSearch" type="text" placeholder="搜索网站...">
            <button class="browser-search-clear" id="searchClear" title="清除">✕</button>
        </div>

        <div class="browser-recent-wrap" id="recentWrap">
            <div class="browser-recent-header">
                <span>最近访问</span>
                <button class="browser-recent-clear" id="recentClear">清除</button>
            </div>
            <div class="browser-recent-list" id="recentList"></div>
        </div>

        <nav class="browser-sidebar-nav" id="browserNav"></nav>
    </aside>

    <main class="browser-main">

        <div class="browser-toolbar">
            <button class="browser-sidebar-toggle" id="sidebarToggle" title="菜单 (Ctrl+B)">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none"><line x1="3" y1="6" x2="21" y2="6" stroke="currentColor" stroke-width="2" stroke-linecap="round"/><line x1="3" y1="12" x2="21" y2="12" stroke="currentColor" stroke-width="2" stroke-linecap="round"/><line x1="3" y1="18" x2="21" y2="18" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg>
            </button>
            <button class="browser-nav-btn" id="btnBack" title="后退 (Alt+←)" disabled>
                <svg viewBox="0 0 24 24" fill="none"><polyline points="15 18 9 12 15 6" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"/></svg>
            </button>
            <button class="browser-nav-btn" id="btnForward" title="前进 (Alt+→)" disabled>
                <svg viewBox="0 0 24 24" fill="none"><polyline points="9 18 15 12 9 6" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"/></svg>
            </button>
            <button class="browser-nav-btn" id="btnReload" title="刷新 (Ctrl+R)">
                <svg viewBox="0 0 24 24" fill="none"><polyline points="23 4 23 10 17 10" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/><path d="M20.49 15a9 9 0 11-2.12-9.36L23 10" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg>
            </button>
            <button class="browser-nav-btn" id="btnHome" title="主页 (Ctrl+H)">
                <svg viewBox="0 0 24 24" fill="none"><path d="M3 9l9-7 9 7v11a2 2 0 01-2 2H5a2 2 0 01-2-2z" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>
            </button>
            <div class="browser-url-bar-wrap">
                <div class="browser-progress" id="browserProgress"></div>
                <input class="browser-url-bar" id="urlBar" type="text" placeholder="输入网址或从左侧选择网站..." value="">
                <span class="browser-url-lock" id="urlLock" style="display:none;">
                    <svg viewBox="0 0 24 24" fill="none"><rect x="3" y="11" width="18" height="11" rx="2" fill="none" stroke="currentColor" stroke-width="2"/><path d="M7 11V7a5 5 0 0110 0v4" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg>
                </span>
            </div>
            <button class="browser-nav-btn" id="btnNewWindow" title="新窗口打开 (Ctrl+Shift+O)">
                <svg viewBox="0 0 24 24" fill="none"><path d="M18 13v6a2 2 0 01-2 2H5a2 2 0 01-2-2V8a2 2 0 012-2h6" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/><polyline points="15 3 21 3 21 9" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/><line x1="10" y1="14" x2="21" y2="3" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg>
            </button>
        </div>

        <div class="browser-frame-wrap" id="frameWrap">
            <div class="browser-empty" id="browserEmpty">
                <div class="browser-empty-logo">
                    <svg viewBox="0 0 24 24" fill="none">
                        <circle cx="12" cy="12" r="10" fill="none" stroke="#fff" stroke-width="2"/>
                        <line x1="2" y1="12" x2="22" y2="12" stroke="#fff" stroke-width="1.5"/>
                        <path d="M12 2a15.3 15.3 0 014 10 15.3 15.3 0 01-4 10 15.3 15.3 0 01-4-10 15.3 15.3 0 014-10z" fill="none" stroke="#fff" stroke-width="1.5"/>
                    </svg>
                </div>
                <div class="browser-empty-title">内置浏览器</div>
                <div class="browser-empty-hint">
                    从左侧选择一个网站开始浏览<br>
                    或在上方地址栏输入网址<br>
                    <small>快捷键：<kbd>Ctrl</kbd>+<kbd>L</kbd> 聚焦地址栏 · <kbd>Alt</kbd>+<kbd>←</kbd>/<kbd>→</kbd> 前进后退</small>
                </div>
                <div class="browser-empty-popular" id="popularSites"></div>
            </div>
            <iframe class="browser-frame" id="browserFrame"
                    sandbox="allow-scripts allow-same-origin allow-forms allow-popups allow-popups-to-escape-sandbox allow-presentation"
                    referrerpolicy="no-referrer"
                    loading="lazy"></iframe>
            <div class="browser-frame-error" id="frameError">
                <div class="error-icon">
                    <svg viewBox="0 0 24 24" fill="none"><circle cx="12" cy="12" r="10" fill="none" stroke="currentColor" stroke-width="2"/><line x1="15" y1="9" x2="9" y2="15" stroke="currentColor" stroke-width="2" stroke-linecap="round"/><line x1="9" y1="9" x2="15" y2="15" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg>
                </div>
                <div class="error-title">无法加载此页面</div>
                <div class="error-hint">该网站可能禁止了iframe嵌入，请尝试在新窗口中打开</div>
                <button class="error-btn" id="btnOpenExternal">在新窗口打开</button>
            </div>
        </div>
    </main>
</div>

<div class="browser-toast" id="browserToast"></div>

<script>
(function() {
    'use strict';

    var _svgGradCounter = 0;
    function svgGradient(id, color) {
        _svgGradCounter++;
        var uid = id + '_' + _svgGradCounter;
        return { id: uid, defs: '<linearGradient id="'+uid+'" x1="0%" y1="0%" x2="100%" y2="100%"><stop offset="0%" stop-color="'+color+'"/><stop offset="100%" stop-color="'+color+'" stop-opacity="0.65"/></linearGradient>' };
    }

    function makeCatIcon(id, color, body) {
        var g = svgGradient(id, color);
        return '<svg width="16" height="16" viewBox="0 0 24 24" fill="none"><defs>'+g.defs+'</defs>'+body.replace(/\burl\(#\)\b/g, 'url(#'+g.id+')')+'</svg>';
    }

    var catIcons = {
        video:    makeCatIcon('cv','#FB7299','<polygon points="8 4 20 12 8 20" fill="url(#)" stroke="#FB7299" stroke-width="0.5" stroke-linejoin="round"/>'),
        music:    makeCatIcon('cm','#E91E63','<path d="M9 18V5l12-2v13" fill="none" stroke="url(#)" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/><circle cx="6" cy="18" r="3" fill="url(#)"/><circle cx="18" cy="16" r="3" fill="url(#)"/>'),
        learn:    makeCatIcon('cl','#4CAF50','<path d="M4 19.5A2.5 2.5 0 016.5 17H20" fill="none" stroke="url(#)" stroke-width="2" stroke-linecap="round"/><path d="M6.5 2H20v20H6.5A2.5 2.5 0 014 19.5v-15A2.5 2.5 0 016.5 2z" fill="none" stroke="url(#)" stroke-width="2" stroke-linecap="round"/><line x1="8" y1="7" x2="16" y2="7" stroke="url(#)" stroke-width="1.2" stroke-linecap="round"/><line x1="8" y1="11" x2="14" y2="11" stroke="url(#)" stroke-width="1.2" stroke-linecap="round"/>'),
        social:   makeCatIcon('cs','#FF6B35','<circle cx="8" cy="8" r="4" fill="url(#)"/><circle cx="16" cy="10" r="4" fill="url(#)" opacity="0.65"/><path d="M4 20c0-4 4-7 8-7s8 3 8 7" fill="none" stroke="url(#)" stroke-width="2" stroke-linecap="round"/>'),
        news:     makeCatIcon('cn','#2563EB','<rect x="2" y="3" width="20" height="18" rx="2" fill="none" stroke="url(#)" stroke-width="2"/><line x1="6" y1="8" x2="18" y2="8" stroke="url(#)" stroke-width="1.5" stroke-linecap="round"/><line x1="6" y1="12" x2="18" y2="12" stroke="url(#)" stroke-width="1.5" stroke-linecap="round"/><line x1="6" y1="16" x2="12" y2="16" stroke="url(#)" stroke-width="1.5" stroke-linecap="round"/>'),
        movie:    makeCatIcon('cmv','#F5A623','<rect x="2" y="2" width="20" height="20" rx="2" fill="none" stroke="url(#)" stroke-width="2"/><path d="M8 2v20M16 2v20M2 8h20M2 16h20" stroke="url(#)" stroke-width="0.8" opacity="0.5"/><circle cx="12" cy="12" r="2" fill="url(#)"/>'),
        tool:     makeCatIcon('ct','#6C5CE7','<circle cx="12" cy="12" r="3" fill="url(#)"/><path d="M12 2v4m0 12v4M2 12h4m12 0h4" stroke="url(#)" stroke-width="1.5" stroke-linecap="round"/><circle cx="12" cy="12" r="9" fill="none" stroke="url(#)" stroke-width="0.5" opacity="0.4"/>'),
        game:    makeCatIcon('cg','#FF6B6B','<path d="M12 2l3 6 6 1-4 4 1 6-6-3-6 3 1-6-4-4 6-1z" fill="url(#)" stroke="url(#)" stroke-width="0.5"/><circle cx="12" cy="12" r="2" fill="#fff" opacity="0.8"/>'),
    };

    var sites = {
        '视频网站': { icon: 'video', color: '#FB7299', iconSvg: catIcons.video, items: [
            { name: 'Bilibili', url: 'https://www.bilibili.com', color: '#FB7299', desc: '国内知名弹幕视频网站' },
            { name: 'YouTube', url: 'https://www.youtube.com', color: '#FF0000', desc: '全球最大视频分享平台' },
            { name: '优酷', url: 'https://www.youku.com', color: '#00A0E9', desc: '海量视频在线观看' },
            { name: '腾讯视频', url: 'https://v.qq.com', color: '#FF6B00', desc: '热门影视剧在线观看' },
            { name: '爱奇艺', url: 'https://www.iqiyi.com', color: '#00BE00', desc: '高清视频在线观看' },
            { name: '抖音', url: 'https://www.douyin.com', color: '#010101', desc: '短视频创作平台' },
            { name: '快手', url: 'https://www.kuaishou.com', color: '#FF4906', desc: '记录生活分享生活' },
            { name: 'AcFun', url: 'https://www.acfun.cn', color: '#FD4C5D', desc: '硬核二次元社区' },
            { name: '芒果TV', url: 'https://www.mgtv.com', color: '#FF6D00', desc: '湖南卫视在线播放' },
            { name: '西瓜视频', url: 'https://www.ixigua.com', color: '#F04142', desc: '新鲜好看刷不停' }
        ]},
        '音乐平台': { icon: 'music', color: '#E91E63', iconSvg: catIcons.music, items: [
            { name: '网易云音乐', url: 'https://music.163.com', color: '#C62F2F', desc: '发现好音乐' },
            { name: 'QQ音乐', url: 'https://y.qq.com', color: '#31C27C', desc: '千万正版音乐' },
            { name: '酷狗音乐', url: 'https://www.kugou.com', color: '#2A9EF5', desc: '就是歌多' },
            { name: '酷我音乐', url: 'https://www.kuwo.cn', color: '#FF8800', desc: '好音质用酷我' },
            { name: '咪咕音乐', url: 'https://music.migu.cn', color: '#E60012', desc: '正版音乐随身听' },
            { name: '荔枝FM', url: 'https://www.lizhi.fm', color: '#E1334A', desc: '人人都是主播' },
            { name: '喜马拉雅', url: 'https://www.ximalaya.com', color: '#FC5832', desc: '听书听广播' },
            { name: '蜻蜓FM', url: 'https://www.qingting.fm', color: '#00A0E9', desc: '网络收音机' }
        ]},
        '学习资源': { icon: 'learn', color: '#4CAF50', iconSvg: catIcons.learn, items: [
            { name: '中国大学MOOC', url: 'https://www.icourse163.org', color: '#4CAF50', desc: '优质在线课程平台' },
            { name: '学堂在线', url: 'https://www.xuetangx.com', color: '#7B1FA2', desc: '名校在线课程' },
            { name: '网易公开课', url: 'https://open.163.com', color: '#D32F2F', desc: '国际名校公开课' },
            { name: 'CSDN', url: 'https://www.csdn.net', color: '#FC5531', desc: '专业开发者社区' },
            { name: '掘金', url: 'https://juejin.cn', color: '#1E80FF', desc: '开发者技术社区' },
            { name: 'GitHub', url: 'https://github.com', color: '#24292e', desc: '代码托管平台' },
            { name: '知乎', url: 'https://www.zhihu.com', color: '#0066FF', desc: '有问题就会有答案' },
            { name: '百度百科', url: 'https://baike.baidu.com', color: '#2E6CB5', desc: '中文百科全书' },
            { name: '维基百科', url: 'https://zh.wikipedia.org', color: '#333333', desc: '自由的百科全书' },
            { name: '豆瓣', url: 'https://www.douban.com', color: '#00B51D', desc: '读书/电影/音乐' },
            { name: '知网', url: 'https://www.cnki.net', color: '#01579B', desc: '中文学术资源' },
            { name: '万方', url: 'https://www.wanfangdata.com.cn', color: '#0277BD', desc: '学术文献数据库' }
        ]},
        '社交媒体': { icon: 'social', color: '#FF6B35', iconSvg: catIcons.social, items: [
            { name: '微博', url: 'https://weibo.com', color: '#E6162D', desc: '随时随地发现新鲜事' },
            { name: '贴吧', url: 'https://tieba.baidu.com', color: '#3385FF', desc: '兴趣主题社区' },
            { name: '小红书', url: 'https://www.xiaohongshu.com', color: '#FE2C55', desc: '标记我的生活' },
            { name: '豆瓣小组', url: 'https://www.douban.com/group', color: '#00B51D', desc: '兴趣小组社区' },
            { name: '虎扑', url: 'https://www.hupu.com', color: '#C12026', desc: '篮球/足球/步行街' },
            { name: '知乎', url: 'https://www.zhihu.com', color: '#0066FF', desc: '问答社区' },
            { name: 'NGA', url: 'https://bbs.nga.cn', color: '#C19A49', desc: '艾泽拉斯国家地理' }
        ]},
        '新闻资讯': { icon: 'news', color: '#2563EB', iconSvg: catIcons.news, items: [
            { name: '百度新闻', url: 'https://news.baidu.com', color: '#2E6CB5', desc: '全球中文新闻聚合' },
            { name: '今日头条', url: 'https://www.toutiao.com', color: '#E13E3E', desc: '你关心的才是头条' },
            { name: '澎湃新闻', url: 'https://www.thepaper.cn', color: '#D7141E', desc: '专注时政与思想' },
            { name: '36氪', url: 'https://36kr.com', color: '#2563EB', desc: '科技商业资讯' },
            { name: '虎嗅', url: 'https://www.huxiu.com', color: '#D32F2F', desc: '商业科技新媒体' },
            { name: '少数派', url: 'https://sspai.com', color: '#D91A1A', desc: '数字生活指南' },
            { name: 'IT之家', url: 'https://www.ithome.com', color: '#CD201F', desc: '科技数码资讯' },
            { name: 'cnBeta', url: 'https://www.cnbeta.com', color: '#004098', desc: 'IT新闻资讯' }
        ]},
        '影视资源': { icon: 'movie', color: '#F5A623', iconSvg: catIcons.movie, items: [
            { name: '豆瓣电影', url: 'https://movie.douban.com', color: '#00B51D', desc: '电影评分与推荐' },
            { name: '时光网', url: 'https://www.mtime.com', color: '#E50012', desc: '电影资讯平台' },
            { name: '1905电影网', url: 'https://www.1905.com', color: '#C8102E', desc: '电影频道官网' },
            { name: '人人影视', url: 'https://www.yyets.com', color: '#FFCC00', desc: '字幕组资源站' },
            { name: '美剧天堂', url: 'https://www.meijutt.com', color: '#0099FF', desc: '美剧在线观看' }
        ]},
        '实用工具': { icon: 'tool', color: '#6C5CE7', iconSvg: catIcons.tool, items: [
            { name: '百度翻译', url: 'https://fanyi.baidu.com', color: '#3385FF', desc: '在线翻译工具' },
            { name: '有道翻译', url: 'https://fanyi.youdao.com', color: '#E8380D', desc: '专业翻译服务' },
            { name: 'ProcessOn', url: 'https://www.processon.com', color: '#2E8B57', desc: '在线作图工具' },
            { name: 'Canva可画', url: 'https://www.canva.cn', color: '#00C4CC', desc: '在线设计平台' },
            { name: '百度网盘', url: 'https://pan.baidu.com', color: '#3385FF', desc: '云存储服务' },
            { name: '阿里云盘', url: 'https://www.aliyundrive.com', color: '#FF6A00', desc: '不限速云盘' },
            { name: '腾讯文档', url: 'https://docs.qq.com', color: '#00A0E9', desc: '在线协作文档' },
            { name: '石墨文档', url: 'https://shimo.im', color: '#4A90D9', desc: '云端Office' }
        ]},
        '小游戏': { icon: 'game', color: '#FF6B6B', iconSvg: catIcons.game, items: [
            { name: '4399小游戏', url: 'https://www.4399.com', color: '#FF6600', desc: '中文小游戏平台' },
            { name: '7k7k小游戏', url: 'https://www.7k7k.com', color: '#FF9900', desc: '在线小游戏大全' },
            { name: 'CrazyGames', url: 'https://www.crazygames.com', color: '#6842FF', desc: '免费在线游戏' },
            { name: 'Poki', url: 'https://poki.com', color: '#FF6B6B', desc: '免费在线游戏平台' },
            { name: 'itch.io', url: 'https://itch.io', color: '#FA5C5C', desc: '独立游戏平台' },
            { name: 'Y8', url: 'https://www.y8.com', color: '#0066CC', desc: '经典在线游戏' },
            { name: 'Kongregate', url: 'https://www.kongregate.com', color: '#2E2E2E', desc: '网页游戏社区' },
            { name: 'Armor Games', url: 'https://armorgames.com', color: '#8B4513', desc: '策略与冒险游戏' }
        ]},
        '购物生活': { icon: 'shop', color: '#FF5000', iconSvg: catIcons.shop, items: [
            { name: '淘宝', url: 'https://www.taobao.com', color: '#FF5000', desc: '淘我喜欢' },
            { name: '京东', url: 'https://www.jd.com', color: '#C91623', desc: '多快好省' },
            { name: '拼多多', url: 'https://www.pinduoduo.com', color: '#E02E24', desc: '拼着买才便宜' },
            { name: '美团', url: 'https://www.meituan.com', color: '#FFC300', desc: '吃喝玩乐全都有' },
            { name: '饿了么', url: 'https://www.ele.me', color: '#0085FF', desc: '外卖订餐平台' }
        ]}
    };

    var frame = document.getElementById('browserFrame');
    var urlBar = document.getElementById('urlBar');
    var progressBar = document.getElementById('browserProgress');
    var emptyState = document.getElementById('browserEmpty');
    var frameError = document.getElementById('frameError');
    var btnBack = document.getElementById('btnBack');
    var btnForward = document.getElementById('btnForward');
    var urlLock = document.getElementById('urlLock');
    var currentUrl = '';
    var historyStack = [];
    var historyIndex = -1;
    var frameLoadTimeout = null;
    var currentSite = null;

    var darkToggle = document.getElementById('darkToggle');
    function getTheme() { return localStorage.getItem('browser-theme') || 'light'; }
    function setTheme(theme) {
        document.documentElement.setAttribute('data-theme', theme);
        localStorage.setItem('browser-theme', theme);
    }
    setTheme(getTheme());
    darkToggle.addEventListener('click', function() {
        setTheme(getTheme() === 'dark' ? 'light' : 'dark');
        showToast(getTheme() === 'dark' ? '已切换深色模式' : '已切换浅色模式');
    });

    var toastEl = document.getElementById('browserToast');
    var toastTimer = null;
    function showToast(msg) {
        if (toastTimer) clearTimeout(toastTimer);
        toastEl.textContent = msg;
        toastEl.classList.add('show');
        toastTimer = setTimeout(function() { toastEl.classList.remove('show'); }, 2000);
    }

    function getRecent() {
        try { return JSON.parse(localStorage.getItem('browser-recent') || '[]'); } catch(e) { return []; }
    }
    function saveRecent(arr) {
        localStorage.setItem('browser-recent', JSON.stringify(arr));
    }
    function addRecent(site) {
        var recent = getRecent();

        recent = recent.filter(function(r) { return r.url !== site.url; });
        recent.unshift({ name: site.name, url: site.url, color: site.color });
        if (recent.length > 10) recent = recent.slice(0, 10);
        saveRecent(recent);
        renderRecent();
    }

    function renderRecent() {
        var recent = getRecent();
        var list = document.getElementById('recentList');
        if (recent.length === 0) {
            list.innerHTML = '<div class="browser-recent-empty">暂无访问记录</div>';
            return;
        }
        var html = '';
        recent.forEach(function(site) {
            var faviconUrl = 'https://www.google.com/s2/favicons?domain=' + encodeURIComponent(site.url) + '&sz=32';
            html += '<span class="browser-recent-tag" data-url="'+site.url+'" data-name="'+site.name+'" title="'+site.name+'">';
            html += '<img class="recent-favicon" src="'+faviconUrl+'" loading="lazy" onerror="this.style.display=\'none\'" alt="">';
            html += site.name;
            html += '</span>';
        });
        list.innerHTML = html;

        list.querySelectorAll('.browser-recent-tag').forEach(function(tag) {
            tag.addEventListener('click', function() {
                navigateTo(this.getAttribute('data-url'), this.getAttribute('data-name'));
                closeSidebar();
            });
        });
    }

    document.getElementById('recentClear').addEventListener('click', function() {
        saveRecent([]);
        renderRecent();
        showToast('已清除访问记录');
    });

    function renderNav() {
        var nav = document.getElementById('browserNav');
        var html = '';
        var firstCat = true;
        Object.keys(sites).forEach(function(cat) {
            var catData = sites[cat];
            html += '<div class="browser-cat-group'+(firstCat?' open':'')+'" data-cat="'+cat+'">';
            html += '<button class="browser-cat-header">';
            html += '<span class="cat-icon">'+catData.iconSvg+'</span>';
            html += '<span>'+cat+'</span>';
            html += '<span class="cat-arrow">';
            html += '<svg width="12" height="12" viewBox="0 0 24 24" fill="none"><polyline points="9 18 15 12 9 6" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"/></svg>';
            html += '</span>';
            html += '</button>';
            html += '<div class="browser-cat-sites"><div class="browser-cat-sites-inner">';
            catData.items.forEach(function(site) {
                var domain = site.url.replace(/^https?:\/\
                var faviconUrl = 'https://www.google.com/s2/favicons?domain=' + encodeURIComponent(domain) + '&sz=32';
                html += '<button class="browser-site-item" data-url="'+site.url+'" data-name="'+site.name+'" data-cat="'+cat+'">';
                html += '<span class="site-favicon" style="background:'+site.color+'18;">';
                html += '<img src="'+faviconUrl+'" width="18" height="18" onerror="this.style.display=\'none\';this.nextElementSibling.style.display=\'flex\'" alt="" loading="lazy">';
                html += '<span class="site-favicon-letter" style="background:'+site.color+';">'+site.name.charAt(0)+'</span>';
                html += '</span>';
                html += '<span class="browser-site-info">';
                html += '<div class="browser-site-name">'+site.name+'</div>';
                html += '<div class="browser-site-desc">'+site.desc+'</div>';
                html += '</span>';
                html += '</button>';
            });
            html += '</div></div></div>';
            if (firstCat) firstCat = false;
        });
        nav.innerHTML = html;

        nav.querySelectorAll('.browser-cat-header').forEach(function(header) {
            header.addEventListener('click', function() {
                this.parentElement.classList.toggle('open');
            });
        });

        nav.querySelectorAll('.browser-site-item').forEach(function(item) {
            item.addEventListener('click', function() {
                var url = this.getAttribute('data-url');
                var name = this.getAttribute('data-name');
                navigateTo(url, name);

                nav.querySelectorAll('.browser-site-item').forEach(function(i) { i.classList.remove('active'); });
                this.classList.add('active');
                closeSidebar();
            });
        });
    }

    var searchInput = document.getElementById('siteSearch');
    var searchClear = document.getElementById('searchClear');
    var searchTimer = null;

    function filterSites(query) {
        var q = query.toLowerCase().trim();
        var nav = document.getElementById('browserNav');
        var groups = nav.querySelectorAll('.browser-cat-group');
        var anyVisible = false;

        groups.forEach(function(group) {
            var items = group.querySelectorAll('.browser-site-item');
            var groupVisible = false;
            items.forEach(function(item) {
                var name = (item.getAttribute('data-name') || '').toLowerCase();
                var cat = (item.getAttribute('data-cat') || '').toLowerCase();
                var matches = !q || name.indexOf(q) !== -1 || cat.indexOf(q) !== -1;
                item.style.display = matches ? '' : 'none';
                if (matches) groupVisible = true;
            });
            group.style.display = groupVisible || !q ? '' : 'none';
            if (q && groupVisible) {
                group.classList.add('open');
                anyVisible = true;
            }
        });

        var existingNoResults = nav.querySelector('.browser-no-results');
        if (q && !anyVisible) {
            if (!existingNoResults) {
                var div = document.createElement('div');
                div.className = 'browser-no-results';
                div.textContent = '未找到匹配的网站';
                nav.appendChild(div);
            }
        } else if (existingNoResults) {
            existingNoResults.remove();
        }

        searchClear.classList.toggle('visible', q.length > 0);
    }

    searchInput.addEventListener('input', function() {
        if (searchTimer) clearTimeout(searchTimer);
        searchTimer = setTimeout(function() {
            filterSites(searchInput.value);
        }, 150);
    });

    searchClear.addEventListener('click', function() {
        searchInput.value = '';
        filterSites('');
        searchInput.focus();
    });

    function navigateTo(url, siteName) {
        if (!url) return;
        if (!/^https?:\/\
            url = 'https://' + url;
        }
        currentUrl = url;
        urlBar.value = url;
        emptyState.style.display = 'none';
        frameError.style.display = 'none';
        showProgress();
        urlLock.style.display = (url.indexOf('https://') === 0) ? '' : 'none';

        if (historyIndex < historyStack.length - 1) {
            historyStack = historyStack.slice(0, historyIndex + 1);
        }
        historyStack.push(url);
        historyIndex = historyStack.length - 1;
        updateNavButtons();

        if (siteName) {
            currentSite = { name: siteName, url: url };
        } else {
            currentSite = findSiteByUrl(url);
        }
        if (currentSite) {
            addRecent(currentSite);
        }

        frame.src = url;
    }

    function findSiteByUrl(url) {
        var result = null;
        Object.keys(sites).forEach(function(cat) {
            sites[cat].items.forEach(function(site) {
                if (url.indexOf(site.url.replace('https://','').replace('http://','').replace('www.','')) !== -1) {
                    result = site;
                }
            });
        });
        return result;
    }

    function showProgress() {
        progressBar.classList.add('loading');
        progressBar.classList.remove('done', 'hide');
        if (frameLoadTimeout) clearTimeout(frameLoadTimeout);
        frameLoadTimeout = setTimeout(function() {
            hideProgress();
        }, 10000);
    }

    function hideProgress() {
        if (frameLoadTimeout) clearTimeout(frameLoadTimeout);
        progressBar.classList.remove('loading');
        progressBar.classList.add('done');
        setTimeout(function() { progressBar.classList.add('hide'); }, 500);
    }

    function updateNavButtons() {
        btnBack.disabled = historyIndex <= 0;
        btnForward.disabled = historyIndex >= historyStack.length - 1;
    }

    frame.addEventListener('load', function() {
        hideProgress();
        frameError.style.display = 'none';
        try {
            var doc = frame.contentDocument || frame.contentWindow.document;

        } catch (e) {

        }
    });

    frame.addEventListener('error', function() {
        hideProgress();
        frameError.style.display = 'block';
    });

    btnBack.addEventListener('click', function() {
        if (historyIndex > 0) {
            historyIndex--;
            var url = historyStack[historyIndex];
            currentUrl = url;
            urlBar.value = url;
            frame.src = url;
            showProgress();
            updateNavButtons();
            urlLock.style.display = (url.indexOf('https://') === 0) ? '' : 'none';
        }
    });

    btnForward.addEventListener('click', function() {
        if (historyIndex < historyStack.length - 1) {
            historyIndex++;
            var url = historyStack[historyIndex];
            currentUrl = url;
            urlBar.value = url;
            frame.src = url;
            showProgress();
            updateNavButtons();
            urlLock.style.display = (url.indexOf('https://') === 0) ? '' : 'none';
        }
    });

    document.getElementById('btnReload').addEventListener('click', function() {
        if (currentUrl) {
            showProgress();
            frame.src = currentUrl;
        }
    });

    document.getElementById('btnHome').addEventListener('click', function() {
        frame.src = '';
        currentUrl = '';
        urlBar.value = '';
        emptyState.style.display = 'flex';
        frameError.style.display = 'none';
        hideProgress();
        urlLock.style.display = 'none';
        historyStack = [];
        historyIndex = -1;
        currentSite = null;
        updateNavButtons();

        document.querySelectorAll('.browser-site-item.active').forEach(function(i) { i.classList.remove('active'); });
    });

    document.getElementById('btnNewWindow').addEventListener('click', function() {
        if (currentUrl) {
            window.open(currentUrl, '_blank', 'noopener,noreferrer');
        }
    });

    document.getElementById('btnOpenExternal').addEventListener('click', function() {
        if (currentUrl) {
            window.open(currentUrl, '_blank', 'noopener,noreferrer');
        }
    });

    urlBar.addEventListener('keydown', function(e) {
        if (e.key === 'Enter') {
            var url = this.value.trim();
            if (url) navigateTo(url, null);
        }
    });

    var sidebar = document.getElementById('browserSidebar');
    var overlay = document.getElementById('sidebarOverlay');

    function closeSidebar() {
        sidebar.classList.remove('mobile-open');
        overlay.classList.remove('show');
    }
    function openSidebar() {
        sidebar.classList.add('mobile-open');
        overlay.classList.add('show');
    }

    document.getElementById('sidebarToggle').addEventListener('click', function() {
        if (sidebar.classList.contains('mobile-open')) {
            closeSidebar();
        } else {
            openSidebar();
        }
    });

    overlay.addEventListener('click', closeSidebar);

    document.addEventListener('keydown', function(e) {

        if ((e.ctrlKey || e.metaKey) && e.key === 'l') {
            e.preventDefault();
            urlBar.select();
            return;
        }

        if ((e.ctrlKey || e.metaKey) && e.key === 'r') {
            e.preventDefault();
            document.getElementById('btnReload').click();
            return;
        }

        if ((e.ctrlKey || e.metaKey) && e.key === 'h') {
            e.preventDefault();
            document.getElementById('btnHome').click();
            return;
        }

        if ((e.ctrlKey || e.metaKey) && e.key === 'b') {
            e.preventDefault();
            if (sidebar.classList.contains('mobile-open')) {
                closeSidebar();
            } else {
                openSidebar();
            }
            return;
        }

        if ((e.ctrlKey || e.metaKey) && e.shiftKey && e.key === 'O') {
            e.preventDefault();
            document.getElementById('btnNewWindow').click();
            return;
        }

        if (e.altKey && e.key === 'ArrowLeft') {
            e.preventDefault();
            if (!btnBack.disabled) btnBack.click();
            return;
        }

        if (e.altKey && e.key === 'ArrowRight') {
            e.preventDefault();
            if (!btnForward.disabled) btnForward.click();
            return;
        }

        if (e.key === 'Escape') {
            closeSidebar();
        }
    });

    var popularSites = [
        { name: 'Bilibili', url: 'https://www.bilibili.com', color: '#FB7299' },
        { name: 'YouTube', url: 'https://www.youtube.com', color: '#FF0000' },
        { name: 'GitHub', url: 'https://github.com', color: '#24292e' },
        { name: '知乎', url: 'https://www.zhihu.com', color: '#0066FF' },
        { name: '微博', url: 'https://weibo.com', color: '#E6162D' },
        { name: '网易云音乐', url: 'https://music.163.com', color: '#C62F2F' },
        { name: 'CSDN', url: 'https://www.csdn.net', color: '#FC5531' },
        { name: '百度百科', url: 'https://baike.baidu.com', color: '#2E6CB5' }
    ];

    function renderPopularSites() {
        var container = document.getElementById('popularSites');
        if (!container) return;
        var html = '<div class="popular-title">热门网站</div><div class="popular-grid">';
        popularSites.forEach(function(site) {
            var domain = site.url.replace(/^https?:\/\
            var faviconUrl = 'https://www.google.com/s2/favicons?domain=' + encodeURIComponent(domain) + '&sz=32';
            html += '<div class="popular-card" data-url="'+site.url+'" data-name="'+site.name+'">';
            html += '<div class="popular-favicon-wrap" style="background:'+site.color+'18;">';
            html += '<img src="'+faviconUrl+'" width="24" height="24" onerror="this.style.display=\'none\';this.nextElementSibling.style.display=\'flex\'" alt="" loading="lazy">';
            html += '<span class="popular-favicon-letter" style="background:'+site.color+';">'+site.name.charAt(0)+'</span>';
            html += '</div>';
            html += '<span class="popular-name">'+site.name+'</span>';
            html += '</div>';
        });
        html += '</div>';
        container.innerHTML = html;

        container.querySelectorAll('.popular-card').forEach(function(card) {
            card.addEventListener('click', function() {
                var url = this.getAttribute('data-url');
                var name = this.getAttribute('data-name');
                navigateTo(url, name);
            });
        });
    }

    renderNav();
    renderRecent();
    renderPopularSites();
})();
</script>
</body>
</html>
