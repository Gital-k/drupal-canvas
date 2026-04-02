<?php

declare(strict_types=1);

namespace Drupal\canvas\Block;

use Drupal\canvas\ComponentSource\ComponentSourceManager;
use Drupal\canvas\Plugin\Canvas\ComponentSource\BlockComponent;
use Drupal\Core\Cache\CacheBackendInterface;

/**
 * Decorates the cache.discovery backend to regenerate block Canvas components.
 *
 * When block plugin definitions are rebuilt and cached (triggered by a
 * set() with the 'block_plugins' cache ID), Canvas regenerates its Component
 * config entities for the "block" component source. Without this, newly added
 * block plugins (e.g. Views blocks) do not appear in Canvas until a full cache
 * clear and subsequent block definition rebuild.
 *
 * @todo Refactor this after https://www.drupal.org/project/drupal/issues/3001284 lands.
 *
 * @see \Drupal\canvas\Plugin\Canvas\ComponentSource\BlockComponent
 * @see \Drupal\canvas\Plugin\Canvas\ComponentSource\BlockComponentDiscovery
 * @see \Drupal\canvas\ComponentSource\ComponentSourceManager::generateComponents()
 * @see https://www.drupal.org/project/canvas/issues/3578142
 * @internal
 */
final readonly class BlockPluginCacheBackend implements CacheBackendInterface {

  public function __construct(
    private CacheBackendInterface $decorated,
    private ComponentSourceManager $componentSourceManager,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function get($cid, $allow_invalid = FALSE) {
    return $this->decorated->get($cid, $allow_invalid);
  }

  /**
   * {@inheritdoc}
   */
  public function getMultiple(&$cids, $allow_invalid = FALSE) {
    return $this->decorated->getMultiple($cids, $allow_invalid);
  }

  /**
   * {@inheritdoc}
   */
  public function set($cid, $data, $expire = CacheBackendInterface::CACHE_PERMANENT, array $tags = []) {
    $this->decorated->set($cid, $data, $expire, $tags);
    if ($cid === 'block_plugins') {
      $this->componentSourceManager->generateComponents(BlockComponent::SOURCE_PLUGIN_ID);
    }
  }

  /**
   * {@inheritdoc}
   */
  public function setMultiple(array $items) {
    $this->decorated->setMultiple($items);
  }

  /**
   * {@inheritdoc}
   */
  public function delete($cid) {
    $this->decorated->delete($cid);
  }

  /**
   * {@inheritdoc}
   */
  public function deleteMultiple(array $cids) {
    $this->decorated->deleteMultiple($cids);
  }

  /**
   * {@inheritdoc}
   */
  public function deleteAll() {
    $this->decorated->deleteAll();
  }

  /**
   * {@inheritdoc}
   */
  public function invalidate($cid) {
    $this->decorated->invalidate($cid);
  }

  /**
   * {@inheritdoc}
   */
  public function invalidateMultiple(array $cids) {
    $this->decorated->invalidateMultiple($cids);
  }

  /**
   * {@inheritdoc}
   */
  public function invalidateAll() {
    $this->decorated->invalidateAll();
  }

  /**
   * {@inheritdoc}
   */
  public function garbageCollection() {
    $this->decorated->garbageCollection();
  }

  /**
   * {@inheritdoc}
   */
  public function removeBin() {
    $this->decorated->removeBin();
  }

}
