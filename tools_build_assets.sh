#!/usr/bin/env bash
# ============================================================================
# 静态资源压缩脚本（无构建链项目的轻量替代）
#
# 用法：
#   bash tools_build_assets.sh                 # 只压缩「源文件比 .min 新」的
#   bash tools_build_assets.sh --force         # 全部重压（日常压缩，安全强混淆）
#   bash tools_build_assets.sh --obfuscate      # 仅对 4 个入口文件施加「域名锁+防篡改」包装
#   bash tools_build_assets.sh --force --obfuscate   # 重压 + 包装（上线前手工加固用）
#
# 设计要点：
#   * 源文件（X.js / X.css）永远是唯一真源，保持可读可改；.min 是派生产物。
#   * 只覆盖「源更新」的文件，避免每次全量重压导致 mtime 抖动、白白打断浏览器缓存。
#   * 与 config/config.php 的 asset_url() 配合：.min 比源旧时会被自动忽略并回退源文件，
#     所以「忘了跑本脚本」最多损失体积，绝不会把旧逻辑发上线。
#   * 安全兜底：每个 JS 产物都会过一道 `node --check` 语法闸门，语法不过即告警
#     （此时浏览器会因 .min 失效而回退源文件，不会把坏代码发上线）。
#
# 混淆加固（批次 I）：
#   * 默认强混淆（见 TERSER_OPTS_SAFE）：passes=3 + hoist_vars + toplevel(compress/mangle)
#     + keep_fargs=false + unsafe=false + ecma=2020。
#   * 关键约束：mangle 的 properties 始终为 false —— 绝不 mangle 属性名，
#     以保护与 PHP 后端约定的 JSON 字段、DOM 的 class/id，以及 window.X 这种跨文件全局。
#     本仓库所有入口 JS 均为顶层 IIFE 包裹（程序顶层零声明），故 toplevel 对它们实际
#     无副作用，仅对未来可能出现的裸顶层全局生效，可放心开启。
#   * --obfuscate 额外开启 unsafe 系（unsafe/unsafe_comps/unsafe_math）+ toplevel mangle，
#     并对 main.js / enhancements.js / ai_widget.js / polish.js 注入「域名锁 + 防篡改」包装。
#     该模式默认关闭，不影响日常 --force 压缩。
#
# 依赖（隔离安装在一个独立的 node 工具目录里，不进项目、不进仓库）：
#   terser        —— JS 压缩
#   clean-css-cli —— CSS 压缩
# ============================================================================
set -u

ROOT="$(cd "$(dirname "$0")" && pwd)"
cd "$ROOT" || exit 1

# ── 工具链定位（环境无关，线上版与比赛版共用同一份脚本）─────────────────────
#   NODE   —— node 可执行文件：优先环境变量 $NODE，否则用 PATH 里的 node。
#             （不硬编码任何绝对路径：换机器 / 升级 node 后脚本依然可用。）
#   NPMDIR —— 装了 terser 与 clean-css-cli 的目录。依次尝试：
#             ① 环境变量 $NPMDIR
#             ② 本目录下的 .buildtools（离线随包携带的工具链）
#             ③ 全局 npm root 的父目录
NODE="${NODE:-$(command -v node 2>/dev/null || true)}"

if [ -z "$NODE" ]; then
    echo "找不到可用的 node。请安装 Node.js，或设置 NODE=/path/to/node 后重跑。"
    exit 1
fi

lw_pick_npmdir() {
    local cand
    for cand in "${NPMDIR:-}" "$ROOT/.buildtools"; do
        [ -n "$cand" ] && [ -f "$cand/node_modules/terser/bin/terser" ] && { echo "$cand"; return; }
    done
    cand="$(npm root -g 2>/dev/null || true)"
    [ -n "$cand" ] && cand="$(dirname "$cand")"
    for cand in "$cand" "$ROOT/.buildtools"; do
        [ -n "$cand" ] && [ -f "$cand/node_modules/terser/bin/terser" ] && { echo "$cand"; return; }
    done
    echo "${NPMDIR:-$ROOT/.buildtools}"
}
NPMDIR="$(lw_pick_npmdir)"
TERSER="$NPMDIR/node_modules/terser/bin/terser"
CLEANCSS="$NPMDIR/node_modules/clean-css-cli/bin/cleancss"

FORCE=0
OBF=0
for arg in "$@"; do
    case "$arg" in
        --force)     FORCE=1 ;;
        --obfuscate) OBF=1 ;;
    esac
done

if [ ! -f "$TERSER" ]; then
    echo "缺少 terser，请先安装："
    echo "  cd $NPMDIR && \"$NODE\" <npm-cli> install terser clean-css-cli"
    exit 1
fi

# ---------------------------------------------------------------------------
# terser 参数
#   SAFE：日常 --force 使用，强混淆但保持安全（unsafe=false）。
#   OBF ：--obfuscate 使用，开启 unsafe 系以进一步缩短/混淆（可选、默认不启用）。
#   两者均显式 properties=false，绝不 mangle 属性名。
# ---------------------------------------------------------------------------
TERSER_OPTS_SAFE="-c passes=3,hoist_vars=true,toplevel=true,keep_fargs=false,unsafe=false -m toplevel=true,properties=false --comments false --format ecma=2020"
TERSER_OPTS_OBF="-c passes=3,hoist_vars=true,toplevel=true,keep_fargs=false,unsafe=true,unsafe_comps=true,unsafe_math=true -m toplevel=true,properties=false --comments false --format ecma=2020"

# --obfuscate 时才套用「域名锁 + 防篡改」包装的入口文件清单（仅 assets/js 下）
OBF_ENTRIES="main.js enhancements.js ai_widget.js polish.js"

# ---------------------------------------------------------------------------
# 域名锁前置代码（仅当 LW_ALLOWED_HOSTS 非空时才有内容，默认留空 = 不启用）
# 校验 location.hostname，不匹配则清空页面并跳转 about:blank，杜绝非授权域名镜像。
# ---------------------------------------------------------------------------
gen_domain_lock() {
    local hosts="${LW_ALLOWED_HOSTS:-}"
    [ -z "$hosts" ] && return 0
    local js="["
    local first=1
    local IFS=','
    # shellcheck disable=SC2086
    for h in $hosts; do
        h="$(printf '%s' "$h" | tr -d '[:space:]')"
        [ -z "$h" ] && continue
        [ "$first" -eq 1 ] && first=0 || js="$js,"
        local e="${h//\'/\\\'}"
        js="$js'$e'"
    done
    js="$js]"
    cat <<EOF
(function(){var __lw_allowed=$js;if(__lw_allowed.length){var __h=(location.hostname||'').toLowerCase();var __ok=false;for(var __i=0;__i<__lw_allowed.length;__i++){var __a=String(__lw_allowed[__i]).toLowerCase();if(__h===__a||(function(s,a){return s.length>a.length&&s.substr(s.length-a.length-1)==='.'+a;})(__h,__a)){__ok=true;break;}}if(!__ok){try{document.documentElement.innerHTML='';}catch(e){}try{window.stop();}catch(e){}try{window.location.href='about:blank';}catch(e){}throw new Error('[lovewall] host not allowed: '+__h);}}})();
EOF
}

# ---------------------------------------------------------------------------
# 防篡改后置代码（轻量自检）：置位哨兵，每 3s 检查，一旦失效即重载/清空。
# 不写裸 debugger（避免卡住正常用户）。
# ---------------------------------------------------------------------------
gen_antitamper() {
    cat <<'EOF'
(function(){try{window.__lw_ok=true;}catch(e){}setInterval(function(){try{if(window.__lw_ok!==true){if(location&&location.reload){location.reload();}else{document.documentElement.innerHTML='';}}}catch(e){try{document.documentElement.innerHTML='';}catch(e2){}}},3000);})();
EOF
}

# ---------------------------------------------------------------------------
# 弱反调试（仅 LW_ANTIDEBUG=1 时注入，默认不注入）：形式如 (()=>{setInterval(()=>{if(!(window.__lw_ok)){}},2000)})()
# ---------------------------------------------------------------------------
gen_antidebug() {
    [ "${LW_ANTIDEBUG:-}" != "1" ] && return 0
    cat <<'EOF'
(()=>{setInterval(()=>{if(!(window.__lw_ok)){}},2000)})();
EOF
}

# 判断源文件是否为需要包装的入口文件
is_obf_entry() {
    local base="$1"
    case " $OBF_ENTRIES " in
        *" $base "*) return 0 ;;
        *) return 1 ;;
    esac
}

# 自动发现所有待压缩资源 —— 不再需要手动往清单里加文件。
# （历史教训：清单原来是硬编码的，新增 polish.css 后忘了登记，
#   结果 .min 一直不生成、线上默默回退到未压缩版，还查不出来。）
# 排除 .min 本身与第三方 vendor（vendor 里的 xlsx 已是压缩产物，再压一次纯浪费）。
JS_LIST="$(find assets/js admin/assets/js -maxdepth 1 -name '*.js' ! -name '*.min.js' 2>/dev/null | grep -v '/vendor/' | sort)"
CSS_LIST="$(find assets/css -maxdepth 1 -name '*.css' ! -name '*.min.css' 2>/dev/null | sort)"

need_build() {
    # 需要重建：源比 .min 新，或 .min 不存在
    local src="$1" min="$2"
    [ ! -f "$src" ] && return 1
    if [ "$FORCE" = "1" ]; then return 0; fi
    [ ! -f "$min" ] && return 0
    [ "$src" -nt "$min" ] && return 0
    return 1
}

echo "=== JS === (混淆模式: $([ "$OBF" = 1 ] && echo obfuscate || echo safe))"
echo "$JS_LIST" | while read -r src; do
    [ -z "$src" ] && continue
    min="${src%.js}.min.js"
    if need_build "$src" "$min"; then
        opts="$TERSER_OPTS_SAFE"
        [ "$OBF" = "1" ] && opts="$TERSER_OPTS_OBF"
        out=$("$NODE" "$TERSER" "$src" $opts -o "$min" 2>&1)
        if [ $? -eq 0 ]; then
            # --obfuscate 且为入口文件：套域名锁 + 防篡改包装（默认关闭，可配置）
            if [ "$OBF" = "1" ] && is_obf_entry "$(basename "$src")"; then
                wrap="$ROOT/.lw_obf_$$.js"
                { gen_domain_lock; cat "$min"; gen_antitamper; gen_antidebug; } > "$wrap" 2>/dev/null
                mv -f "$wrap" "$min"
                echo "  [obfuscate] 已为 $(basename "$src") 施加域名锁/防篡改包装"
            fi
            # 语法闸门：node --check 不过则告警（浏览器回退源文件，不会发坏代码）
            if "$NODE" --check "$min" >/dev/null 2>&1; then
                o=$(stat -c%s "$src"); m=$(stat -c%s "$min")
                echo "  压缩 $src  $o -> $m  (-$(( (o-m)*100/o ))%)"
            else
                echo "  [语法校验失败] $src -> $min（保留产物，建议人工检查；运行时将回退源文件）"
            fi
        else
            echo "  [失败] $src"
            echo "$out" | head -5
        fi
    else
        echo "  跳过 $src (已是最新)"
    fi
done

echo "=== CSS ==="
echo "$CSS_LIST" | while read -r src; do
    [ -z "$src" ] && continue
    min="${src%.css}.min.css"
    if need_build "$src" "$min"; then
        out=$("$NODE" "$CLEANCSS" -O2 "$src" -o "$min" 2>&1)
        if [ $? -eq 0 ]; then
            o=$(stat -c%s "$src"); m=$(stat -c%s "$min")
            echo "  压缩 $src  $o -> $m  (-$(( (o-m)*100/o ))%)"
        else
            echo "  [失败] $src"
            echo "$out" | head -5
        fi
    else
        echo "  跳过 $src (已是最新)"
    fi
done

echo
echo "完成。页面通过 asset_url() 自动引用 .min；.min 比源旧时自动回退源文件。"
echo "混淆流水线说明见 tools_obfuscate_readme.md。"
[ "$OBF" = "1" ] && {
    echo "注意：本次启用 --obfuscate。域名锁仅当环境变量 LW_ALLOWED_HOSTS 非空时生效；"
    echo "      防篡改包装已注入 4 个入口文件的 .min。默认关闭，日常请勿启用以免影响构建协作。"
}
exit 0
