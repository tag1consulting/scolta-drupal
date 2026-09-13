<?php

declare(strict_types=1);

namespace Drupal\scolta\Plugin\search_api\backend;

use Drupal\search_api\Backend\BackendPluginBase;
use Drupal\search_api\IndexInterface;
use Drupal\search_api\Query\QueryInterface;

/**
 * Inert stand-in for the removed Pagefind backend.
 *
 * Search API config on upgrading sites still names backend "scolta_pagefind"
 * until scolta_update_10008() deletes the server. Without a plugin behind that
 * id, Server::getBackend() throws — and search_api_solr's SolrDocumentDeriver
 * calls it for every server during typed-data discovery, which breaks the
 * update itself and any cold cache rebuild. This class keeps the server
 * loadable so the update can remove it. Remove it when 10008 is old enough.
 *
 * @SearchApiBackend(
 *   id = "scolta_pagefind",
 *   label = @Translation("Scolta (Pagefind, removed)"),
 *   description = @Translation("Legacy placeholder. Run database updates to remove the server that uses it.")
 * )
 */
class ScoltaBackend extends BackendPluginBase {

  /**
   * {@inheritdoc}
   */
  public function indexItems(IndexInterface $index, array $items) {
    return [];
  }

  /**
   * {@inheritdoc}
   */
  public function deleteItems(IndexInterface $index, array $item_ids) {}

  /**
   * {@inheritdoc}
   */
  public function deleteAllIndexItems(IndexInterface $index, $datasource_id = NULL) {}

  /**
   * {@inheritdoc}
   */
  public function search(QueryInterface $query) {}

}
