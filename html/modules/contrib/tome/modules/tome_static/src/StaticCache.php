<?php

namespace Drupal\tome_static;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Component\Serialization\ObjectAwareSerializationInterface;
use Drupal\Core\Cache\Cache;
use Drupal\Core\Cache\CacheableResponseInterface;
use Drupal\Core\Cache\CacheTagsChecksumInterface;
use Drupal\Core\Cache\DatabaseBackend;
use Drupal\Core\Database\Connection;
use Drupal\Core\Site\Settings;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Determines if pages are statically cached.
 *
 * @internal
 */
class StaticCache extends DatabaseBackend implements StaticCacheInterface {

  /**
   * Constructs a StaticCache object.
   *
   * @param \Drupal\Core\Database\Connection $connection
   *   The database connection.
   * @param \Drupal\Core\Cache\CacheTagsChecksumInterface $checksum_provider
   *   The cache tags checksum provider.
   * @param \Drupal\Component\Serialization\ObjectAwareSerializationInterface|int|string|null $serializer
   *   (optional) The serializer to use.
   * @param \Drupal\Component\Datetime\TimeInterface|int|string|null $time
   *   The time service.
   */
  public function __construct(Connection $connection, CacheTagsChecksumInterface $checksum_provider, ObjectAwareSerializationInterface $serializer, TimeInterface $time) {
    DeprecationHelper::backwardsCompatibleCall(\Drupal::VERSION, '11.0.0',
      fn() => parent::__construct($connection, $checksum_provider, 'tome_static', $serializer, $time, self::MAXIMUM_NONE),
      fn() => parent::__construct($connection, $checksum_provider, 'tome_static', self::MAXIMUM_NONE)
    );
  }

  /**
   * {@inheritdoc}
   */
  public function filterUncachedPaths($base_url, array $original_paths) {
    $this->ensureBinExists();

    $cid_map = [];
    foreach ($original_paths as $original_path) {
      $cid_map[$this->getCacheId($base_url, $original_path)] = $original_path;
    }

    $never_cache = Settings::get('tome_static_cache_exclude', []);

    $cids = array_keys($cid_map);
    foreach ($this->getMultiple($cids) as $cid => $cache) {
      $skip = FALSE;
      foreach ($never_cache as $pattern) {
        if ($cid_map[$cid] === $pattern || @preg_match($pattern, $cid_map[$cid])) {
          $skip = TRUE;
          break;
        }
      }
      if (!$skip && file_exists($cache->data)) {
        unset($cid_map[$cid]);
      }
    }

    return array_values($cid_map);
  }

  /**
   * {@inheritdoc}
   */
  public function isCacheEmpty() {
    $this->ensureBinExists();

    $count = $this->connection->select($this->bin)
      ->fields($this->bin, ['cid'])
      ->countQuery()
      ->execute()
      ->fetchField();
    return empty($count);
  }

  /**
   * {@inheritdoc}
   */
  public function getExpiredFiles() {
    $this->ensureBinExists();

    $cids = $this->connection->select($this->bin)
      ->fields($this->bin, ['cid'])
      ->execute()
      ->fetchCol();
    $files = [];
    foreach ($this->getMultiple($cids, TRUE) as $cache) {
      if (!$cache->valid && file_exists($cache->data)) {
        $files[$cache->data] = $cache->data;
      }
    }
    parent::garbageCollection();
    return array_values($files);
  }

  /**
   * {@inheritdoc}
   */
  public function setCache(Request $request, Response $response, $original_path, $destination) {
    if (!$response instanceof CacheableResponseInterface) {
      return FALSE;
    }

    $request_time = $request->server->get('REQUEST_TIME');
    if ($expires = $response->getExpires()) {
      $date = $expires->getTimestamp();
      $expire = ($date > $request_time) ? $date : Cache::PERMANENT;
    }
    else {
      $expire = Cache::PERMANENT;
    }

    if ($expire === Cache::PERMANENT || $expire > $request_time) {
      $tags = $response->getCacheableMetadata()->getCacheTags();
      $cid = $this->getCacheId($request->getSchemeAndHttpHost(), $original_path);
      $this->set($cid, $destination, $expire, $tags);
      if ($request->getUri() !== $original_path) {
        $cid = $this->getCacheId($request->getSchemeAndHttpHost(), $request->getPathInfo());
        $this->set($cid, $destination, $expire, $tags);
      }
    }

    return TRUE;
  }

  /**
   * {@inheritdoc}
   */
  public function garbageCollection() {
    // No-op, we rely on expired rows in ::getExpiredFiles.
  }

  /**
   * Gets the Tome cache ID for this request.
   *
   * @param string $base_url
   *   The base scheme/host for this request.
   * @param string $original_path
   *   The original, placeholdered path.
   *
   * @return string
   *   The cache ID for this request.
   */
  protected function getCacheId($base_url, $original_path) {
    $cid_parts = [
      $base_url,
      rtrim($original_path, '/'),
    ];
    return strtolower(implode(':', $cid_parts));
  }

}
