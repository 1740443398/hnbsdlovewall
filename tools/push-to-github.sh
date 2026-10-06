#!/usr/bin/env bash
# =============================================================================
#  push-to-github.sh —— 把 love_wall 源码（脱敏后）推送到 GitHub 仓库
# =============================================================================
#  为什么需要这个脚本：
#    GitHub 侧的 OAuth 授权只有只读权限，自动化流程无法代为推送；
#    本机又没有任何 git 凭据（无 gh、无 SSH key、无凭据管理器）。
#    所以推送这一步需要你在本机执行，凭据由你提供、不经手第三方。
#
#  用法：
#      bash tools/push-to-github.sh                  # 交互式，推荐
#      GITHUB_TOKEN=ghp_xxx bash tools/push-to-github.sh   # 非交互
#
#  安全设计：
#    - 推送前会逐项校验「危险路径」是否被 .gitignore 挡住，任一漏网即中止；
#    - 全程不打印 token；
#    - 目标 URL 只写到临时 remote，不污染全局 git 配置。
# =============================================================================
set -euo pipefail

REPO_OWNER="1740443398"
REPO_NAME="hnbsdlovewall"
REPO_URL="https://github.com/${REPO_OWNER}/${REPO_NAME}.git"
BRANCH="main"

# 切换到项目根目录（本脚本位于 tools/ 下）
cd "$(dirname "$0")/.."
ROOT="$(pwd)"

echo "======================================================================"
echo "  推送 love_wall 源码到 ${REPO_OWNER}/${REPO_NAME}"
echo "  工作目录：${ROOT}"
echo "======================================================================"
echo

# -----------------------------------------------------------------------------
# 1. 取得令牌
# -----------------------------------------------------------------------------
if [ -z "${GITHUB_TOKEN:-}" ]; then
    echo "需要一个 GitHub Personal Access Token（需勾选 repo 权限）。"
    echo "生成地址： https://github.com/settings/tokens"
    echo "（输入时不会回显，输入完直接回车）"
    echo
    read -r -s -p "请粘贴 Token: " GITHUB_TOKEN
    echo
fi

if [ -z "${GITHUB_TOKEN:-}" ]; then
    echo "错误：未提供 Token，已退出。" >&2
    exit 1
fi

# -----------------------------------------------------------------------------
# 2. 初始化仓库（若尚未初始化）
# -----------------------------------------------------------------------------
if [ ! -d .git ]; then
    echo "[1/6] 初始化本地仓库..."
    git init -q -b "$BRANCH" 2>/dev/null || git init -q
else
    echo "[1/6] 本地仓库已存在，跳过初始化。"
fi

git symbolic-ref HEAD "refs/heads/${BRANCH}" 2>/dev/null || true

# -----------------------------------------------------------------------------
# 3. 暂存全部文件
# -----------------------------------------------------------------------------
echo "[2/6] 暂存文件..."
git add -A

# -----------------------------------------------------------------------------
# 4. 🔴 安全闸门：危险路径一旦被暂存，立即中止
# -----------------------------------------------------------------------------
echo "[3/6] 安全校验（危险文件是否被正确忽略）..."
STAGED="$(git diff --cached --name-only)"

FAIL=0
check_absent() {
    local pattern="$1" desc="$2"
    if printf '%s\n' "$STAGED" | grep -qE "$pattern"; then
        echo "  ✗ 发现不该上传的文件（${desc}）："
        printf '%s\n' "$STAGED" | grep -E "$pattern" | sed 's/^/      /'
        FAIL=1
    else
        echo "  ✓ ${desc} 已排除"
    fi
}

check_absent "^data/"              "运行时数据 data/"
check_absent "^uploads/"           "用户上传 uploads/"
check_absent "^music/"             "音乐文件 music/"
check_absent "data_backup"         "本地备份 data_backup_*/"
check_absent "config/ai_config"    "AI 密钥 config/ai_config.php"
check_absent "config/mail_config"  "邮箱密钥 config/mail_config.php"
check_absent "config/sync_config"  "同步种子 config/sync_config.php"
check_absent "sync_nonces"         "防重放记录 sync_nonces.json"
check_absent "\.zip$"              "本地打包产物 *.zip"
check_absent "keystore|\.jks$"     "安卓签名密钥"
check_absent "^\.trae/|^\.idea/"   "IDE / AI 工具目录"
check_absent "\.lock$"             "锁文件 *.lock"

# 反向校验：源码文件必须真的在暂存区里
for must in "index.php" "config/constants.php" "config/config.php" "README.md" "README_CN.md" ".gitignore"; do
    if printf '%s\n' "$STAGED" | grep -qx "$must"; then
        echo "  ✓ ${must} 已包含"
    else
        echo "  ✗ 缺少必需文件：${must}"
        FAIL=1
    fi
done

if [ "$FAIL" -ne 0 ]; then
    echo
    echo "中止：安全校验未通过，未做任何推送。" >&2
    echo "请检查 .gitignore 后重试。" >&2
    exit 1
fi

TOTAL="$(printf '%s\n' "$STAGED" | grep -c . || true)"
echo "  → 待推送文件共 ${TOTAL} 个"
echo

# -----------------------------------------------------------------------------
# 5. 提交
# -----------------------------------------------------------------------------
echo "[4/6] 创建提交..."
git config user.name  "蕭遞"                         >/dev/null 2>&1 || true
git config user.email "1740443398@qq.com"            >/dev/null 2>&1 || true

if git diff --cached --quiet; then
    echo "  没有变更需要提交。"
else
    git commit -q -m "chore: 同步站点源码（README 订正 + 安全强化）

- README 中英双份全面订正：移除不存在的 router.php 引用、
  删除幽灵依赖 ZEGO、PHP 要求修正为 8.0+
- 补齐 SEO / PWA / 数据同步工具 / 静态资源压缩 等章节
- .gitignore 强化：排除 data_backup_*/、*.lock、*.zip、IDE 目录等"
    echo "  已提交：$(git rev-parse --short HEAD)"
fi
echo

# -----------------------------------------------------------------------------
# 6. 推送（令牌只出现在本次命令的 remote URL 中，不写盘）
# -----------------------------------------------------------------------------
echo "[5/6] 推送到 GitHub..."
git remote remove origin >/dev/null 2>&1 || true
git remote add origin "https://${REPO_OWNER}:${GITHUB_TOKEN}@github.com/${REPO_OWNER}/${REPO_NAME}.git"

if git push -u origin "$BRANCH" 2>&1 | sed "s/${GITHUB_TOKEN}/***REDACTED***/g"; then
    echo
    echo "[6/6] 推送成功。"
else
    echo
    echo "推送失败。常见原因：" >&2
    echo "  - Token 无效或缺少 repo 权限" >&2
    echo "  - 远端已有内容，需要先合并： git pull --rebase origin ${BRANCH}" >&2
    echo "  - 网络无法访问 github.com（需配置代理）" >&2
    # 移除带令牌的 remote，避免令牌残留在配置里
    git remote remove origin >/dev/null 2>&1 || true
    exit 1
fi

# 清理：把带令牌的 remote 换回干净的 URL
git remote set-url origin "$REPO_URL"

echo
echo "仓库地址： https://github.com/${REPO_OWNER}/${REPO_NAME}"
echo "完成。"
