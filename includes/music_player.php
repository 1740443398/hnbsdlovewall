<?php
$musicAutoPlay = getSetting('music_autoplay', '1') === '1';
$musicVolume = floatval(getSetting('music_volume', '0.3'));
$currentMusic = getSetting('current_music', '');

$musicDir = __DIR__ . '/../music/';
$musicFiles = [];
if (is_dir($musicDir)) {
    $files = scandir($musicDir);
    foreach ($files as $file) {
        $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
        if (in_array($ext, ['mp3', 'wav', 'ogg', 'm4a', 'aac', 'flac'])) {
            $musicFiles[] = $file;
        }
    }
}

if (empty($musicFiles)) return;
?>
<div class="music-player-bar" id="musicPlayerBar" style="display:none">
    <button class="mp-btn mp-toggle" id="mpToggle" title="播放/暂停">
        <svg width="18" height="18" viewBox="0 0 24 24" fill="none"><rect x="5" y="4" width="5" height="16" rx="1" fill="currentColor"/><rect x="14" y="4" width="5" height="16" rx="1" fill="currentColor"/></svg>
    </button>
    <div class="mp-info">
        <span class="mp-title" id="mpTitle">加载中...</span>
    </div>
    <button class="mp-btn mp-next" id="mpNext" title="下一首">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none"><polygon points="5 3 19 12 5 21" fill="currentColor"/><line x1="19" y1="3" x2="19" y2="21" stroke="currentColor" stroke-width="2"/></svg>
    </button>
    <button class="mp-btn mp-close" id="mpClose" title="关闭音乐">
        <svg width="14" height="14" viewBox="0 0 24 24" fill="none"><line x1="18" y1="6" x2="6" y2="18" stroke="currentColor" stroke-width="2" stroke-linecap="round"/><line x1="6" y1="6" x2="18" y2="18" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg>
    </button>
</div>
<style>
.music-player-bar {
    position: fixed;
    bottom: 20px;
    left: 50%;
    transform: translateX(-50%);
    z-index: 9999;
    display: flex;
    align-items: center;
    gap: 10px;
    background: var(--card-bg, rgba(255,255,255,0.95));
    backdrop-filter: blur(12px);
    -webkit-backdrop-filter: blur(12px);
    border: 1px solid var(--border, rgba(0,0,0,0.1));
    border-radius: 28px;
    padding: 8px 16px;
    box-shadow: 0 4px 24px rgba(0,0,0,0.15);
    transition: all 0.3s;
    max-width: 90vw;
}
.music-player-bar:hover {
    box-shadow: 0 6px 32px rgba(0,0,0,0.2);
}
.mp-btn {
    width: 34px; height: 34px;
    border-radius: 50%;
    border: none;
    cursor: pointer;
    display: flex;
    align-items: center;
    justify-content: center;
    background: var(--primary, #667eea);
    color: #fff;
    transition: all 0.2s;
    flex-shrink: 0;
    padding: 0;
}
.mp-btn:hover {
    transform: scale(1.1);
    box-shadow: 0 2px 12px rgba(27,58,92,0.4);
}
.mp-btn.mp-close {
    background: transparent;
    color: var(--text-muted, #999);
    width: 28px; height: 28px;
}
.mp-btn.mp-close:hover {
    background: rgba(0,0,0,0.08);
    color: var(--text, #333);
    transform: scale(1.1);
    box-shadow: none;
}
.mp-info {
    display: flex;
    flex-direction: column;
    min-width: 0;
    max-width: 200px;
}
.mp-title {
    font-size: 0.8rem;
    font-weight: 500;
    color: var(--text, #333);
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}
.mp-toggle {
    background: linear-gradient(135deg, #1B3A5C, #2A5078);
    box-shadow: 0 2px 12px rgba(27,58,92,0.3);
}
.mp-toggle.paused {
    background: linear-gradient(135deg, #43e97b, #38f9d7);
    box-shadow: 0 2px 12px rgba(67,233,123,0.4);
}
.mp-toggle.paused svg {
    display: none;
}
.mp-toggle.paused::after {
    content: '▶';
    display: block;
    font-size: 0.75rem;
    color: #fff;
}
[data-theme="dark"] .music-player-bar {
    background: rgba(30,30,40,0.95);
    border-color: rgba(255,255,255,0.1);
}
[data-theme="dark"] .mp-btn.mp-close:hover {
    background: rgba(255,255,255,0.1);
}
@media (max-width: 768px) {
    .music-player-bar {
        bottom: calc(96px + env(safe-area-inset-bottom, 0px));
        left: 50%;
        right: auto;
        transform: translateX(-50%);
        padding: 6px 12px;
        gap: 6px;
        max-width: 82vw;
    }
    .mp-btn { width: 30px; height: 30px; }
    .mp-title { font-size: 0.72rem; max-width: 100px; }
}
@media (max-width: 480px) {
    .music-player-bar {
        bottom: calc(100px + env(safe-area-inset-bottom, 0px));
    }
}
</style>
<script>
(function() {
    var musicFiles = <?= json_encode($musicFiles) ?>;
    var currentMusic = <?= json_encode($currentMusic) ?>;
    var autoPlay = <?= $musicAutoPlay ? 'true' : 'false' ?>;
    var volume = <?= $musicVolume ?>;
    if (!musicFiles.length) return;

    var STATE_KEY = 'lovewall_bgm';
    var state = null;
    try { state = JSON.parse(localStorage.getItem(STATE_KEY) || 'null'); } catch (e) { state = null; }

    // 优先使用上次播放的歌曲（跨页面/重新打开都从这里继续）
    var selectedMusic = '';
    if (state && state.song && musicFiles.indexOf(state.song) !== -1) {
        selectedMusic = state.song;
    } else if (currentMusic && musicFiles.indexOf(currentMusic) !== -1) {
        selectedMusic = currentMusic;
    } else {
        selectedMusic = musicFiles[Math.floor(Math.random() * musicFiles.length)];
    }

    var audio = new Audio('/music/' + encodeURIComponent(selectedMusic));
    audio.volume = (state && typeof state.volume === 'number') ? state.volume : volume;
    audio.loop = false;

    var bar = document.getElementById('musicPlayerBar');
    var toggleBtn = document.getElementById('mpToggle');
    var titleEl = document.getElementById('mpTitle');
    if (bar) bar.style.display = 'flex';

    function currentTitle() {
        var name = selectedMusic.replace(/\.(mp3|wav|ogg|m4a|aac|flac)$/i, '');
        return name.replace(/[_-]/g, ' ').replace(/\s+/g, ' ').trim() || selectedMusic;
    }

    // 播放状态持久化：歌曲 / 进度 / 暂停与否 / 音量 都存到本机
    function saveState() {
        try {
            localStorage.setItem(STATE_KEY, JSON.stringify({
                song: selectedMusic,
                time: Math.max(0, Math.floor(audio.currentTime || 0)),
                paused: audio.paused,
                volume: audio.volume
            }));
        } catch (e) {}
    }

    function updateUI() {
        if (titleEl) titleEl.textContent = currentTitle();
        if (toggleBtn) {
            if (audio.paused) { toggleBtn.classList.add('paused'); }
            else { toggleBtn.classList.remove('paused'); }
        }
    }

    function playNext() {
        var idx = musicFiles.indexOf(selectedMusic);
        if (idx < 0) idx = 0;
        idx = (idx + 1) % musicFiles.length;
        selectedMusic = musicFiles[idx];
        audio.src = '/music/' + encodeURIComponent(selectedMusic);
        audio.play().catch(function() {});
        saveState();
    }

    function throttleSave() {
        var now = Date.now();
        if (now - (throttleSave._last || 0) > 2000) {
            throttleSave._last = now;
            saveState();
        }
    }

    audio.addEventListener('play', function () { updateUI(); saveState(); });
    audio.addEventListener('pause', function () { updateUI(); saveState(); });
    audio.addEventListener('ended', playNext);
    audio.addEventListener('timeupdate', throttleSave);
    audio.addEventListener('error', function () { setTimeout(playNext, 2000); });

    if (toggleBtn) toggleBtn.addEventListener('click', function () {
        if (audio.paused) { audio.play().catch(function(){}); }
        else { audio.pause(); }
    });
    var nextBtn = document.getElementById('mpNext');
    if (nextBtn) nextBtn.addEventListener('click', playNext);
    var closeBtn = document.getElementById('mpClose');
    if (closeBtn) closeBtn.addEventListener('click', function () {
        audio.pause();
        saveState();   // 记住当前歌曲与进度，下次进来从暂停处继续
        bar.style.display = 'none';
    });

    window.bgMusic = {
        audio: audio,
        play: function () { audio.play().catch(function(){}); },
        pause: function () { audio.pause(); },
        toggle: function () {
            if (audio.paused) { audio.play().catch(function(){}); }
            else { audio.pause(); }
        },
        next: playNext,
        setVolume: function (v) { audio.volume = Math.max(0, Math.min(1, v)); saveState(); },
        isPlaying: function () { return !audio.paused; },
        currentName: function () { return selectedMusic; }
    };

    // 跨页面恢复：等音频元数据可定位后再跳转到上次进度
    var seekRestored = false;
    audio.addEventListener('loadedmetadata', function () {
        if (!seekRestored && state && state.time > 1) {
            try {
                var t = Math.min(state.time, Math.max(0, (audio.duration || state.time) - 0.5));
                if (t > 0) audio.currentTime = t;
            } catch (e) {}
        }
        seekRestored = true;
    });

    updateUI();

    var hasHistory = !!state;
    if (hasHistory && state.paused === false) {
        // 上次在播放 → 自动从退出的位置继续
        setTimeout(function () {
            audio.play().then(saveState).catch(function () {
                var resume = function () {
                    audio.play().then(saveState).catch(function(){});
                    document.removeEventListener('click', resume);
                    document.removeEventListener('touchstart', resume);
                };
                document.addEventListener('click', resume, { once: true });
                document.addEventListener('touchstart', resume, { once: true });
            });
        }, 300);
    } else if (!hasHistory && autoPlay) {
        // 首次访问：按后台"自动播放"设置
        setTimeout(function () {
            audio.play().then(saveState).catch(function () {
                var playOnInteract = function () {
                    audio.play().then(saveState).catch(function(){});
                    document.removeEventListener('click', playOnInteract);
                    document.removeEventListener('touchstart', playOnInteract);
                };
                document.addEventListener('click', playOnInteract, { once: true });
                document.addEventListener('touchstart', playOnInteract, { once: true });
            });
        }, 3000);
    }
    // 已有记录且上次为暂停 → 保持暂停，绝不自动播放
})();
</script>
