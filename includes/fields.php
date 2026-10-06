<?php
/**
 * 列表类接口的「字段投影」（E14）
 * ==================================================================
 * 背景：信息流/评论/通知这类接口一次返回 10~20 条，每条 10~20 个字段，
 * 但调用方常常只用到其中几个（例如只画标题+点赞数，或后台轮询只要未读数）。
 * 多出来的字段既是流量，也是无谓的序列化开销。
 *
 * 设计原则（**改动最小、绝不破坏既有调用方**）：
 *   1. **纯可选**：不传参数时行为与改造前 100% 一致（函数直接返回原数组，连遍历都不做）；
 *      空数组参数同样视作「不传」（见下方两个投影函数里的退化请求处理）。
 *      所以前端可以逐页面慢慢迁移，不存在「一次全改」的上线风险。
 *   2. **白名单**：每个接口自带允许投影的字段清单；参数里出现清单外的名字**静默忽略**，
 *      既不报错也不泄露「有哪些字段存在」。同理，未出现在参数里的字段会被裁掉 ——
 *      也就是说 `fields` 是「只要这些」，不是「加上这些」。
 *   3. **必保字段**：每个接口可以声明「无论怎么投影都必须留」的字段（如 `id`），
 *      防止调用方把前端 key 用的字段裁掉导致列表渲染崩掉。
 *   4. **有界**：字段名做严格格式校验、数量封顶 32，避免拿它当放大器滥用。
 *
 * 两个维度，各自独立：
 *   - `?fields=a,b,c`  投影**列表行**（`posts` / `comments` / `users` 里的每一项）
 *   - `?top=x,y`       投影**顶层键**（如只要 `unread_count`，把整份列表都省掉）
 *
 * 用法（在接口里三行以内接完）：
 * ```php
 * require_once __DIR__ . '/../../includes/fields.php';
 * // …构造好 $result 之后、jsonSuccess 之前…
 * $result = lwProjectRows($result, lwFieldsRequest(), ['id','content','created_at'], ['id']);
 * jsonSuccess(lwProjectTop(['comments' => $result], lwTopRequest(), ['comments']));
 * ```
 *
 * 维护约定：**新增接口时把字段白名单写全**（漏写会让该字段无法被投影，但不影响正确性）；
 * `_probe/fields_probe.php` 会静态检查所有已接线的接口确实调用了这两个函数。
 */

/** 单个字段名的最长长度（够表达 author_title_gradient_start 这类名字，又不至于离谱） */
const LW_FIELDS_MAX_NAME = 40;
/** 一次最多允许投影的字段数 —— 超过就截断，避免 `fields=` 被拿来放大请求 */
const LW_FIELDS_MAX_COUNT = 32;

/**
 * 解析字段参数。
 *
 * @param string $key 参数名（默认 `fields`）
 * @return string[]|null null 表示「调用方没要求投影」，此时所有投影函数都是空操作
 */
function lwFieldsParse(string $key = 'fields'): ?array
{
    $raw = $_GET[$key] ?? $_REQUEST[$key] ?? '';
    if (!is_string($raw)) {
        return null;
    }
    $raw = trim($raw);
    if ($raw === '') {
        return null;
    }

    $seen = [];
    foreach (explode(',', $raw) as $name) {
        $name = trim($name);
        // 严格格式：字母/下划线开头，只含字母数字下划线。不符合的直接丢弃而不是报错，
        // 免得脏参数把正常调用一起带崩。
        if ($name === '' || strlen($name) > LW_FIELDS_MAX_NAME
            || !preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $name)) {
            continue;
        }
        $seen[$name] = true;
        if (count($seen) >= LW_FIELDS_MAX_COUNT) {
            break;
        }
    }

    return $seen === [] ? null : array_keys($seen);
}

/** `?fields=` 的语义糖 */
function lwFieldsRequest(): ?array
{
    return lwFieldsParse('fields');
}

/** `?top=` 的语义糖 */
function lwTopRequest(): ?array
{
    return lwFieldsParse('top');
}

/**
 * 投影列表行。
 *
 * @param mixed      $rows    列表（元素应是关联数组；非数组元素原样保留）
 * @param array|null $fields  {@see lwFieldsRequest()} 的结果；null 或空数组 = 不做投影
 * @param string[]   $allowed 本接口允许被投影的字段白名单
 * @param string[]   $required 无论怎么投影都必须保留的字段
 * @return mixed 投影后的列表
 */
function lwProjectRows($rows, ?array $fields, array $allowed, array $required = ['id'])
{
    // 空数组 = 「解析后一无所获」的退化请求。lwFieldsParse() 在这种情况本来就返回 null，
    // 但手写调用可能直接传 []；此时若按「只留必保字段」处理，会把每一行都裁成只剩 id，
    // 属于静默毁数据。故空数组与 null 同义，一律视为「不投影」。
    if ($fields === null || $fields === [] || !is_array($rows)) {
        return $rows;
    }

    $keep = array_values(array_intersect($fields, $allowed));
    foreach ($required as $r) {
        if (!in_array($r, $keep, true)) {
            $keep[] = $r;
        }
    }

    // 请求的字段已经覆盖（或接近覆盖）白名单时不做任何事：
    // 既能少一次全量重建，也让「fields 全写一遍」这种用法退化成原行为。
    if (count($keep) >= count($allowed)) {
        return $rows;
    }

    $keepSet = array_fill_keys($keep, true);
    $out = [];
    foreach ($rows as $row) {
        $out[] = is_array($row) ? array_intersect_key($row, $keepSet) : $row;
    }
    return $out;
}

/**
 * 投影顶层键。
 *
 * @param mixed      $data    jsonSuccess 的 data（关联数组）
 * @param array|null $fields  {@see lwTopRequest()} 的结果；null 或空数组 = 不做投影
 * @param string[]   $required 必须保留的顶层键（如 `comments`：列表本体不能被裁掉）
 * @return mixed
 */
function lwProjectTop($data, ?array $fields, array $required = [])
{
    // 与 lwProjectRows() 同理：空数组等同 null，避免把响应裁成只剩必保键。
    if ($fields === null || $fields === [] || !is_array($data)) {
        return $data;
    }

    $keep = [];
    foreach ($fields as $f) {
        if (array_key_exists($f, $data)) {
            $keep[] = $f;
        }
    }
    foreach ($required as $r) {
        if (array_key_exists($r, $data) && !in_array($r, $keep, true)) {
            $keep[] = $r;
        }
    }
    if ($keep === []) {
        // 参数里没有任何有效顶层键 —— 与其返回空对象让人以为接口坏了，不如原样返回。
        return $data;
    }

    return array_intersect_key($data, array_fill_keys($keep, true));
}
