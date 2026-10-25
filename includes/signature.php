<?php
/**
 * 接口签名校验（sign + timestamp + nonce）
 *
 * 【为什么需要】
 * 接口裸奔时，别人抓一次包就能写脚本无限刷，把配额吃光，
 * 导致正常用户看到「请求过于频繁」。签名要求每个请求带密钥算出的 sign，
 * 密钥只在 App 里，抓包的人拿不到（除非逆向 App）。
 *
 * 协议：
 *   X-LW-Timestamp  Unix 秒
 *   X-LW-Nonce      8~32 位随机串
 *   X-LW-Sign       HMAC-SHA256(secret, canonical) 的 hex
 *
 * canonical = METHOD + 换行 + PATH + 换行 + SORTED_QUERY + 换行 + TS + 换行 + NONCE
 *
 * 时间窗口默认正负 300 秒。nonce 在同窗口内只能用一次。
 *
 * 兼容策略：off（默认）/ optional（有就校验）/ required（必须带）
 * 默认 off 是因为 App 旧版还在用，网页版也无法安全存密钥。
 */

if (!defined("LW_SIGNATURE_LOADED")) {
    define("LW_SIGNATURE_LOADED", 1);

    /** 签名密钥。放 config/ 下，不进开源版。 */
    function lwSignSecret(): string
    {
        static $s = null;
        if ($s !== null) {
            return $s;
        }
        $f = __DIR__ . "/../config/sign_secret.php";
        if (is_file($f)) {
            $v = include $f;
            if (is_string($v) && strlen($v) >= 16) {
                $s = $v;
                return $s;
            }
        }
        $s = getenv("LW_SIGN_SECRET") ?: "";
        return $s;
    }

    /** 当前模式：off | optional | required */
    function lwSignMode(): string
    {
        $m = getenv("LW_SIGN_MODE");
        if ($m === "required" || $m === "optional" || $m === "off") {
            return $m;
        }
        $f = __DIR__ . "/../config/sign_mode.php";
        if (is_file($f)) {
            $v = include $f;
            if (is_string($v) && in_array($v, ["off", "optional", "required"], true)) {
                return $v;
            }
        }
        return "off";
    }

    /** 构造待签名串。 */
    function lwSignCanonical(string $method, string $path, array $query, string $ts, string $nonce): string
    {
        unset($query["sign"], $query["_sign"], $query["timestamp"], $query["_ts"], $query["nonce"], $query["_nonce"]);
        ksort($query);
        $pairs = [];
        foreach ($query as $k => $v) {
            if (is_array($v)) {
                continue;
            }
            $pairs[] = $k . "=" . $v;
        }
        return strtoupper($method) . chr(10) . $path . chr(10) . implode("&", $pairs) . chr(10) . $ts . chr(10) . $nonce;
    }

    /** 计算签名。 */
    function lwSignCompute(string $method, string $path, array $query, string $ts, string $nonce): string
    {
        $secret = lwSignSecret();
        if ($secret === "") {
            return "";
        }
        return hash_hmac("sha256", lwSignCanonical($method, $path, $query, $ts, $nonce), $secret);
    }

    /** 校验请求签名。返回 [ok, reason]。 */
    function lwSignVerify(): array
    {
        $mode = lwSignMode();
        if ($mode === "off") {
            return ["ok" => true, "reason" => "disabled"];
        }
        $secret = lwSignSecret();
        if ($secret === "") {
            return ["ok" => true, "reason" => "no_secret"];
        }

        $h = function ($k) {
            return $_SERVER["HTTP_" . strtoupper(str_replace("-", "_", $k))]
                ?? $_GET[$k] ?? $_POST[$k] ?? "";
        };
        $ts = (string)$h("X-LW-Timestamp");
        $nonce = (string)$h("X-LW-Nonce");
        $sign = (string)$h("X-LW-Sign");

        $has = ($ts !== "" && $nonce !== "" && $sign !== "");
        if (!$has) {
            return $mode === "required"
                ? ["ok" => false, "reason" => "missing"]
                : ["ok" => true, "reason" => "absent_allowed"];
        }

        $drift = abs(time() - (int)$ts);
        if ($drift > 300) {
            return ["ok" => false, "reason" => "expired"];
        }

        if (strlen($nonce) < 8 || strlen($nonce) > 64) {
            return ["ok" => false, "reason" => "bad_nonce"];
        }

        if (lwSignNonceUsed($nonce)) {
            return ["ok" => false, "reason" => "replay"];
        }

        $path = (string)(parse_url($_SERVER["REQUEST_URI"] ?? "/", PHP_URL_PATH) ?: "/");
        $method = (string)($_SERVER["REQUEST_METHOD"] ?? "GET");
        $query = array_merge($_GET, $_POST);
        $expect = lwSignCompute($method, $path, $query, $ts, $nonce);
        if (!hash_equals($expect, $sign)) {
            return ["ok" => false, "reason" => "bad_sign"];
        }

        lwSignMarkNonce($nonce, (int)$ts);
        return ["ok" => true, "reason" => "verified"];
    }

    function lwSignNonceUsed(string $nonce): bool
    {
        $f = lwSignNonceFile();
        if (!is_file($f)) {
            return false;
        }
        $d = json_decode((string)@file_get_contents($f), true);
        return is_array($d) && isset($d[$nonce]);
    }

    function lwSignMarkNonce(string $nonce, int $ts): void
    {
        $f = lwSignNonceFile();
        $d = [];
        if (is_file($f)) {
            $decoded = json_decode((string)@file_get_contents($f), true);
            if (is_array($decoded)) {
                $d = $decoded;
            }
        }
        $cut = time() - 600;
        foreach ($d as $k => $v) {
            if ((int)$v < $cut) {
                unset($d[$k]);
            }
        }
        if (count($d) > 5000) {
            $d = array_slice($d, -2500, null, true);
        }
        $d[$nonce] = $ts;
        $dir = dirname($f);
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
        @file_put_contents($f, json_encode($d), LOCK_EX);
    }

    function lwSignNonceFile(): string
    {
        return __DIR__ . "/../data/_sign_nonces.json";
    }
}
