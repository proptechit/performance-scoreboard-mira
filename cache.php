<?php

/**
 * cache.php
 * ─────────────────────────────────────────────────────────────────────────────
 * Lightweight file-based cache.
 * Stores serialized PHP values as flat files in CACHE_DIR.
 * Keys are sha1-hashed so any string can be a key.
 *
 * Usage:
 *   $cache = new ScoreboardCache();
 *   $data  = $cache->get($key);
 *   if ($data === null) {
 *       $data = expensiveQuery();
 *       $cache->set($key, $data);         // uses default CACHE_TTL
 *       $cache->set($key, $data, 60);     // custom TTL in seconds
 *   }
 *   $cache->delete($key);
 *   $cache->flush();                      // wipe all cache files
 * ─────────────────────────────────────────────────────────────────────────────
 */

class ScoreboardCache
{
    private $dir;
    private $ttl;
    private $enabled;

    public function __construct($dir = null, $ttl = null, $enabled = null)
    {
        $this->dir     = rtrim($dir     !== null ? $dir     : CACHE_DIR,  '/') . '/';
        $this->ttl     = $ttl     !== null ? (int)$ttl     : (int)CACHE_TTL;
        $this->enabled = $enabled !== null ? (bool)$enabled : (bool)CACHE_ENABLED;

        // Create cache directory if it doesn't exist
        if ($this->enabled && !is_dir($this->dir)) {
            mkdir($this->dir, 0755, true);
        }
    }

    // ─── PUBLIC API ───────────────────────────────────────────────────────

    /**
     * Retrieve a cached value.
     * Returns the value if found and not expired, null otherwise.
     *
     * @param  string $key
     * @return mixed|null
     */
    public function get($key)
    {
        if (!$this->enabled) {
            return null;
        }

        $file = $this->filePath($key);

        if (!file_exists($file)) {
            return null;
        }

        $raw = file_get_contents($file);
        if ($raw === false) {
            return null;
        }

        $data = unserialize($raw);
        if ($data === false) {
            return null;
        }

        // Check expiry
        if (isset($data['expires']) && time() > $data['expires']) {
            unlink($file);
            return null;
        }

        return isset($data['value']) ? $data['value'] : null;
    }

    /**
     * Store a value in the cache.
     *
     * @param  string   $key
     * @param  mixed    $value
     * @param  int|null $ttl   Seconds until expiry; null = use default
     * @return bool
     */
    public function set($key, $value, $ttl = null)
    {
        if (!$this->enabled) {
            return false;
        }

        $ttl  = $ttl !== null ? (int)$ttl : $this->ttl;
        $file = $this->filePath($key);

        $data = serialize(array(
            'expires' => time() + $ttl,
            'value'   => $value,
        ));

        return file_put_contents($file, $data, LOCK_EX) !== false;
    }

    /**
     * Delete a single cached entry.
     *
     * @param  string $key
     * @return bool
     */
    public function delete($key)
    {
        $file = $this->filePath($key);
        if (file_exists($file)) {
            return unlink($file);
        }
        return true;
    }

    /**
     * Delete all cache files in the cache directory.
     *
     * @return int  Number of files deleted
     */
    public function flush()
    {
        $count = 0;
        if (!is_dir($this->dir)) {
            return $count;
        }
        foreach (glob($this->dir . '*.cache') as $file) {
            if (unlink($file)) {
                $count++;
            }
        }
        return $count;
    }

    /**
     * Build a consistent cache key from an array of parameters.
     * Use this so callers don't have to build key strings manually.
     *
     * @param  string $prefix  e.g. 'ceo', 'agent_42', 'manager_7'
     * @param  array  $params  Filter parameters array
     * @return string
     */
    public function buildKey($prefix, $params)
    {
        ksort($params);
        return $prefix . '_' . sha1(serialize($params));
    }

    // ─── PRIVATE HELPERS ──────────────────────────────────────────────────

    /**
     * Resolve the file path for a given key.
     */
    private function filePath($key)
    {
        return $this->dir . sha1($key) . '.cache';
    }
}
