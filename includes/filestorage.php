<?php

class FileStorage {
    private $dataDir;
    private $cache = [];
    private $cacheEnabled = true;
    private $locks = [];
    /** 主键索引：table => [id => 该行在表中的下标]。findById 走它，避免每次全表扫描。 */
    private $index = [];

    public function __construct() {
        $this->dataDir = __DIR__ . '/../data';
        if (!is_dir($this->dataDir)) {
            if (!mkdir($this->dataDir, 0750, true)) {
                error_log('FileStorage: Failed to create data directory: ' . $this->dataDir);
            }
        }
    }

    private function getFilePath($table) {
        return $this->dataDir . '/' . $table . '.json';
    }

    /**
     * 取文件锁（读 LOCK_SH / 写 LOCK_EX）。
     *
     * 健壮性要求：本方法**绝不允许**抛异常或致命错误。
     * 共享主机上 fopen 锁文件可能瞬时失败（并发打开同一文件、目录权限被回收、
     * 主机安全组件拦截），过去直接往下走会导致 flock(false) → TypeError → 整个接口 500。
     * 现在：重试几次；仍失败就放弃加锁继续跑（锁只是「尽力而为」的并发保护，
     * 数据写入本身是「临时文件 + rename」的原子替换，不用锁也不会写出半截文件）。
     */
    private function acquireLock($table, $mode = LOCK_SH) {
        $file = $this->getFilePath($table);
        $lockFile = $file . '.lock';

        if (!isset($this->locks[$lockFile])) {
            $fp = false;
            for ($try = 0; $try < 5; $try++) {
                $fp = @fopen($lockFile, 'c+');
                if ($fp) {
                    break;
                }
                usleep(60000 * ($try + 1)); // 60ms / 120ms / 180ms / 240ms
            }
            if (!$fp) {
                error_log('FileStorage: 锁文件打开失败，本次跳过加锁继续执行：' . $lockFile);
                $this->locks[$lockFile] = null; // 记住「不可用」，避免每次调用都重试
                return false;
            }
            $this->locks[$lockFile] = $fp;
        }

        $handle = $this->locks[$lockFile];
        if (!is_resource($handle)) {
            return false; // 之前判定不可用
        }

        $attempts = 0;
        while (!@flock($handle, $mode | LOCK_NB)) {
            usleep(rand(1000, 10000));
            $attempts++;
            if ($attempts > 500) {
                error_log('FileStorage: Lock timeout for table ' . $table);
                return false;
            }
        }
        return true;
    }

    private function releaseLock($table) {
        $file = $this->getFilePath($table);
        $lockFile = $file . '.lock';
        if (!isset($this->locks[$lockFile])) {
            return;
        }
        $handle = $this->locks[$lockFile];
        // 必须判类型：fopen 失败时这里存的是 null/占位值，
        // 直接 flock 会抛 TypeError 把整个请求打成 500。
        if (is_resource($handle)) {
            @flock($handle, LOCK_UN);
        }
    }

    /**
     * 把解析失败的 JSON 文件改名留档，而不是当作「空表」继续用。
     *
     * 为什么必须这么做：读取失败若静默返回 []，紧跟其后的 insert()/update() 会以
     * 「空表」为基础写回，把整表原有数据永久覆盖掉（该主机已知出现过文件系统层损坏，
     * 半截文件并不罕见）。改名后原始字节仍留在磁盘上（*.json.corrupt.<时间>），
     * 站长可以手工抢救；文件名不再以 .json 结尾，也不会被备份/导出逻辑误收。
     */
    private function quarantineCorrupt($table, $file) {
        if (!$file || !is_file($file)) {
            return;
        }
        $target = $file . '.corrupt.' . date('YmdHis');
        if (@rename($file, $target)) {
            @chmod($target, 0600);
            error_log('FileStorage: 数据文件解析失败，已留档为 ' . basename($target) . '（原表 ' . $table . '）');
        } else {
            error_log('FileStorage: 数据文件解析失败且留档失败，已跳过该表读取：' . $table);
        }
    }

    public function read($table) {
        if ($this->cacheEnabled && isset($this->cache[$table])) {
            return $this->cache[$table];
        }

        $this->acquireLock($table, LOCK_SH);
        $file = $this->getFilePath($table);
        clearstatcache(false, $file);
        if (!file_exists($file)) {
            $this->releaseLock($table);
            $this->cache[$table] = [];
            return [];
        }
        $content = file_get_contents($file);
        $this->releaseLock($table);

        if (!$content) {
            $this->cache[$table] = [];
            return [];
        }
        $data = json_decode($content, true);
        if ($data === null && json_last_error() !== JSON_ERROR_NONE) {
            error_log('FileStorage read: json_decode failed for ' . $table . ' error: ' . json_last_error_msg());
            $this->quarantineCorrupt($table, $file);
            $this->cache[$table] = [];
            return [];
        }
        if (!is_array($data)) {
            // 顶层既不是列表也不是对象（例如 null / 字符串），同样按损坏处理
            error_log('FileStorage read: 文件顶层结构异常，按损坏处理：' . $table);
            $this->quarantineCorrupt($table, $file);
            $this->cache[$table] = [];
            return [];
        }
        $this->cache[$table] = $data;
        return $data;
    }

    public function write($table, $data) {
        $this->acquireLock($table, LOCK_EX);
        $file = $this->getFilePath($table);
        $dir = dirname($file);
        if (!is_dir($dir)) {
            if (!mkdir($dir, 0755, true)) {
                error_log('FileStorage write: cannot create directory ' . $dir);
                $this->releaseLock($table);
                return false;
            }
        }
        // 紧凑格式写入，显著减小磁盘占用（5GB 服务器）；读取不依赖排版。
        // 注意：这里**不能**带 JSON_PRETTY_PRINT —— 缩进纯属浪费
        // （实测 waf_logs 300 条：128,797 字节 → 95,796 字节，省 26%），
        // 既占配额又拖慢每次 json_decode。数据文件由后台管理，不需要人工阅读排版。
        $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            error_log('FileStorage write: json_encode failed for table ' . $table . ' error: ' . json_last_error_msg());
            $this->releaseLock($table);
            return false;
        }
        $tmpFile = $file . '.tmp.' . bin2hex(random_bytes(8));
        $result = file_put_contents($tmpFile, $json);
        if ($result === false) {
            error_log('FileStorage write: file_put_contents failed for ' . $tmpFile . ' (table: ' . $table . ')');
            @unlink($tmpFile);
            $this->releaseLock($table);
            return false;
        }
        if (DIRECTORY_SEPARATOR === '\\') {
            @unlink($file);
        }
        if (!rename($tmpFile, $file)) {
            if (!@copy($tmpFile, $file)) {
                error_log('FileStorage write: rename and copy both failed from ' . $tmpFile . ' to ' . $file);
                @unlink($tmpFile);
                $this->releaseLock($table);
                return false;
            }
            @unlink($tmpFile);
        }
        $this->cache[$table] = $data;
        $this->dropIndex($table);
        $this->releaseLock($table);
        clearstatcache(false, $file);
        return true;
    }

    public function insert($table, $record) {
        $this->acquireLock($table, LOCK_EX);
        $data = $this->readUnlocked($table);
        $record['id'] = $this->generateId($data);
        $record['created_at'] = date('Y-m-d H:i:s');
        $record['updated_at'] = date('Y-m-d H:i:s');
        $data[] = $record;
        $result = $this->writeUnlocked($table, $data);
        if (!$result) {
            error_log('FileStorage insert: write failed for table ' . $table . ' with ' . count($data) . ' records');
        }
        $this->releaseLock($table);
        return $result ? $record : false;
    }

    /**
     * 插入一条记录，并按条数上限裁剪（保留最近 $maxRecords 条）。
     * 用于日志类表：避免表无限膨胀后每次追加都要整文件重写，拖慢共享主机。
     */
    public function insertCapped($table, $record, $maxRecords = 500) {
        $this->acquireLock($table, LOCK_EX);
        $data = $this->readUnlocked($table);
        $record['id'] = $this->generateId($data);
        $record['created_at'] = date('Y-m-d H:i:s');
        $record['updated_at'] = date('Y-m-d H:i:s');
        $data[] = $record;
        if ($maxRecords > 0 && count($data) > $maxRecords) {
            $data = array_slice($data, -$maxRecords);
        }
        $result = $this->writeUnlocked($table, $data);
        if (!$result) {
            error_log('FileStorage insertCapped: write failed for table ' . $table);
        }
        $this->releaseLock($table);
        return $result ? $record : false;
    }

    public function update($table, $id, $record) {
        $this->acquireLock($table, LOCK_EX);
        $data = $this->readUnlocked($table);
        $found = false;
        foreach ($data as &$item) {
            if (isset($item['id']) && (int)$item['id'] === (int)$id) {
                $record['updated_at'] = date('Y-m-d H:i:s');
                $item = array_merge($item, $record);
                $found = true;
                break;
            }
        }
        if (!$found) {
            $this->releaseLock($table);
            return false;
        }
        $result = $this->writeUnlocked($table, $data);
        $this->releaseLock($table);
        return $result;
    }

    public function delete($table, $id) {
        $this->acquireLock($table, LOCK_EX);
        $data = $this->readUnlocked($table);
        $filtered = array_filter($data, function($item) use ($id) {
            return (int)$item['id'] !== (int)$id;
        });
        $result = $this->writeUnlocked($table, array_values($filtered));
        $this->releaseLock($table);
        return $result;
    }

    private function readUnlocked($table) {
        $file = $this->getFilePath($table);
        clearstatcache(false, $file);
        if (!file_exists($file)) {
            return [];
        }
        $content = file_get_contents($file);
        if (!$content) {
            return [];
        }
        $data = json_decode($content, true);
        if ($data === null && json_last_error() !== JSON_ERROR_NONE) {
            error_log('FileStorage readUnlocked: json_decode failed for ' . $table . ' error: ' . json_last_error_msg());
            $this->quarantineCorrupt($table, $file);
            return [];
        }
        if (!is_array($data)) {
            error_log('FileStorage readUnlocked: 文件顶层结构异常，按损坏处理：' . $table);
            $this->quarantineCorrupt($table, $file);
            return [];
        }
        return $data;
    }

    private function writeUnlocked($table, $data) {
        $file = $this->getFilePath($table);
        // 同上：紧凑格式，不带 JSON_PRETTY_PRINT
        $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            return false;
        }
        $tmpFile = $file . '.tmp.' . bin2hex(random_bytes(8));
        $result = file_put_contents($tmpFile, $json);
        if ($result === false) {
            @unlink($tmpFile);
            return false;
        }
        if (DIRECTORY_SEPARATOR === '\\') {
            @unlink($file);
        }
        if (!rename($tmpFile, $file)) {
            if (!@copy($tmpFile, $file)) {
                @unlink($tmpFile);
                return false;
            }
            @unlink($tmpFile);
        }
        $this->cache[$table] = $data;
        $this->dropIndex($table);
        return true;
    }

    // -----------------------------------------------------------------------
    // 主键索引
    // -----------------------------------------------------------------------
    // 动机：findById() 原先走 find() 做全表线性扫描。列表页「每篇帖子查一次作者、
    // 每条评论查一次用户」会把 O(n) 拖成 O(n²)，表一大就肉眼可见地卡。
    // 这里给每张表建一份 id => 下标的索引，随写失效、按需重建。
    // 只在开启缓存时生效：缓存关闭意味着每次都从磁盘重读，索引会对不上。

    private function dropIndex($table) {
        unset($this->index[$table]);
    }

    private function buildIndex($table) {
        $idx = [];
        foreach ($this->read($table) as $i => $row) {
            if (is_array($row) && isset($row['id'])) {
                $k = (int)$row['id'];
                // 保留首次出现的位置，与原来 findOne() 返回第一条的语义一致
                if (!isset($idx[$k])) {
                    $idx[$k] = $i;
                }
            }
        }
        $this->index[$table] = $idx;
    }

    public function find($table, $conditions = []) {
        $data = $this->read($table);
        $filtered = $data;
        foreach ($conditions as $key => $value) {
            $filtered = array_filter($filtered, function($item) use ($key, $value) {
                if (!isset($item[$key])) return false;
                $itemVal = $item[$key];
                $condVal = $value;
                if (is_numeric($itemVal) && is_numeric($condVal)) {
                    return (int)$itemVal === (int)$condVal;
                }
                return $itemVal === $condVal;
            });
        }
        return array_values($filtered);
    }

    public function findOne($table, $conditions = []) {
        $results = $this->find($table, $conditions);
        return $results ? $results[0] : null;
    }

    public function getAll($table) {
        return $this->read($table);
    }

    public function findById($table, $id) {
        if (!$this->cacheEnabled) {
            return $this->findOne($table, ['id' => $id]);
        }
        if (!isset($this->index[$table])) {
            $this->buildIndex($table);
        }
        $k = (int)$id;
        if (!array_key_exists($k, $this->index[$table])) {
            return null;
        }
        $data = $this->read($table);
        $pos = $this->index[$table][$k];
        return $data[$pos] ?? null;
    }

    public function count($table, $conditions = []) {
        $results = $this->find($table, $conditions);
        return count($results);
    }

    public function orderBy($table, $field, $order = 'DESC') {
        $data = $this->read($table);
        usort($data, function($a, $b) use ($field, $order) {
            $va = $a[$field] ?? '';
            $vb = $b[$field] ?? '';
            if (is_numeric($va) && is_numeric($vb)) {
                $cmp = $va <=> $vb;
            } else {
                $cmp = strcmp((string)$va, (string)$vb);
            }
            return $order === 'DESC' ? -$cmp : $cmp;
        });
        return $data;
    }

    public function limit($data, $offset, $limit) {
        return array_slice($data, $offset, $limit);
    }

    public function search($table, $fields, $keyword) {
        $data = $this->read($table);
        $keyword = strtolower($keyword);
        $filtered = array_filter($data, function($item) use ($fields, $keyword) {
            foreach ($fields as $field) {
                if (isset($item[$field]) && stripos($item[$field], $keyword) !== false) {
                    return true;
                }
            }
            return false;
        });
        return array_values($filtered);
    }

    private function generateId($data) {
        $maxId = 0;
        foreach ($data as $item) {
            if (isset($item['id']) && $item['id'] > $maxId) {
                $maxId = $item['id'];
            }
        }
        return $maxId + 1;
    }

    public function clearCache($table = null) {
        if ($table === null) {
            $this->cache = [];
            $this->index = [];
        } else {
            unset($this->cache[$table]);
            unset($this->index[$table]);
        }
    }

    public function transaction(callable $callback) {
        try {
            return $callback($this);
        } catch (Exception $e) {
            return false;
        }
    }
}

function getFS() {
    static $fs = null;
    if ($fs === null) {
        $fs = new FileStorage();
    }
    return $fs;
}