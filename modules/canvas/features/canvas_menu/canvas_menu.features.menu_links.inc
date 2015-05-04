<?php
/**
 * @file
 * canvas_menu.features.menu_links.inc
 */

/**
 * Implements hook_menu_default_menu_links().
 */
function canvas_menu_menu_default_menu_links() {
  $menu_links = array();

  // Exported menu link: main-menu_contact:contact
  $menu_links['main-menu_contact:contact'] = array(
    'menu_name' => 'main-menu',
    'link_path' => 'contact',
    'router_path' => 'contact',
    'link_title' => 'Contact',
    'options' => array(
      'identifier' => 'main-menu_contact:contact',
    ),
    'module' => 'menu',
    'hidden' => 0,
    'external' => 0,
    'has_children' => 0,
    'expanded' => 0,
    'weight' => 0,
    'customized' => 0,
  );
  // Exported menu link: footer-menu_sitemap-and-feeds:sitemap
  $menu_links['footer-menu_sitemap-and-feeds:sitemap'] = array(
    'menu_name' => 'footer-menu',
    'link_path' => 'sitemap',
    'router_path' => 'sitemap',
    'link_title' => 'Sitemap and Feeds',
    'options' => array(
      'identifier' => 'footer-menu_sitemap-and-feeds:sitemap',
    ),
    'module' => 'menu',
    'hidden' => 0,
    'external' => 0,
    'has_children' => 0,
    'expanded' => 0,
    'weight' => -39,
    'customized' => 0,
  );
  // Exported menu link: user-menu_site-map-and-feeds:sitemap
  $menu_links['user-menu_site-map-and-feeds:sitemap'] = array(
    'menu_name' => 'user-menu',
    'link_path' => 'sitemap',
    'router_path' => 'sitemap',
    'link_title' => 'Sitemap and Feeds',
    'options' => array(
      'attributes' => array(),
      'identifier' => 'user-menu_site-map-and-feeds:sitemap',
    ),
    'module' => 'menu',
    'hidden' => 0,
    'external' => 0,
    'has_children' => 0,
    'expanded' => 0,
    'weight' => -50,
    'customized' => 0,
  );
  // Translatables
  // Included for use with string extractors like potx.
  t('Contact');
  t('Sitemap and Feeds');
  t('Sitemap and Feeds');


  return $menu_links;
}
