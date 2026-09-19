<?php

class FileStorage {
    private $dataDir;
    private $cache = [];
    private $cacheEnabled = true;
    private $locks = [];

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

    private function acquireLock($table, $mode = LOCK_SH) {
        $file = $this->getFilePath($table);
        $lockFile = $file . '.lock';
        if (!isset($this->locks[$lockFile])) {
            $this->locks[$lockFile] = fopen($lockFile, 'c+');
            if (!$this->locks[$lockFile]) {
                error_log('FileStorage: Failed to open lock file: ' . $lockFile);
                return false;
            }
        }
        $attempts = 0;
        while (!flock($this->locks[$lockFile], $mode | LOCK_NB)) {
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
        if (isset($this->locks[$lockFile])) {
            flock($this->locks[$lockFile], LOCK_UN);
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
            $this->cache[$table] = [];
            return [];
        }
        $result = is_array($data) ? $data : [];
        $this->cache[$table] = $result;
        return $result;
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
        $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
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
            return [];
        }
        return is_array($data) ? $data : [];
    }

    private function writeUnlocked($table, $data) {
        $file = $this->getFilePath($table);
        // 紧凑格式写入，显著减小磁盘占用（5GB 服务器）；读取不依赖排版。
        $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
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
        return true;
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
        return $this->findOne($table, ['id' => $id]);
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
        } else {
            unset($this->cache[$table]);
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
