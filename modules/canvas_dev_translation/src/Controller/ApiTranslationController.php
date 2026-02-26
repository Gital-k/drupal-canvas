<?php

declare(strict_types=1);

namespace Drupal\canvas_dev_translation\Controller;

use Drupal\canvas\AutoSave\AutoSaveManager;
use Drupal\Core\Cache\CacheTagsInvalidatorInterface;
use Drupal\Core\Config\ConfigFactoryOverrideInterface;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\TranslatableInterface;
use Drupal\Core\KeyValueStore\KeyValueFactoryInterface;
use Drupal\Core\Language\LanguageManagerInterface;
use Drupal\language\Config\LanguageConfigFactoryOverride;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Handles translation metadata and creation for Canvas entities.
 */
class ApiTranslationController extends ControllerBase {

  /**
   * Maps route entity type slugs to Drupal internal entity type IDs.
   */
  private const ENTITY_TYPE_MAP = [
    'canvas_page'             => 'canvas_page',
    'canvas_content_template' => 'content_template',
    'canvas_page_region'      => 'page_region',
  ];

  /**
   * Entity type slugs that are config entities using config overrides.
   */
  private const CONFIG_ENTITY_TYPES = [
    'canvas_content_template',
    'canvas_page_region',
  ];

  /**
   * The config language override service.
   *
   * @var \Drupal\Core\Config\ConfigFactoryOverrideInterface
   */
  protected ConfigFactoryOverrideInterface $configLanguageOverride;

  /**
   * Constructs an ApiTranslationController object.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   The entity type manager.
   * @param \Drupal\Core\Language\LanguageManagerInterface $languageManager
   *   The language manager.
   * @param \Drupal\Core\Config\ConfigFactoryOverrideInterface $configLanguageOverride
   *   The language config factory override.
   * @param \Drupal\Core\KeyValueStore\KeyValueFactoryInterface $keyValueFactory
   *   The key-value factory.
   * @param \Drupal\Core\Cache\CacheTagsInvalidatorInterface $cacheTagsInvalidator
   *   The cache tags invalidator.
   */
  public function __construct(
    EntityTypeManagerInterface $entityTypeManager,
    LanguageManagerInterface $languageManager,
    ConfigFactoryOverrideInterface $configLanguageOverride,
    private readonly KeyValueFactoryInterface $keyValueFactory,
    private readonly CacheTagsInvalidatorInterface $cacheTagsInvalidator,
  ) {
    $this->entityTypeManager = $entityTypeManager;
    $this->languageManager = $languageManager;
    $this->configLanguageOverride = $configLanguageOverride;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): self {
    return new self(
      $container->get('entity_type.manager'),
      $container->get('language_manager'),
      $container->get('language.config_factory_override'),
      $container->get('keyvalue'),
      $container->get('cache_tags.invalidator'),
    );
  }

  /**
   * Returns translation metadata for all configured languages for an entity.
   *
   * @param string $entity_type
   *   The route entity type slug (e.g. canvas_page).
   * @param string $id
   *   The entity ID.
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The current request.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   A JSON response containing translation metadata per language.
   */
  public function getTranslations(string $entity_type, string $id, Request $request): JsonResponse {
    if (!isset(self::ENTITY_TYPE_MAP[$entity_type])) {
      throw new NotFoundHttpException("Unsupported entity type: $entity_type");
    }

    $drupal_entity_type = self::ENTITY_TYPE_MAP[$entity_type];
    $languages = $this->languageManager->getLanguages();

    $data = in_array($entity_type, self::CONFIG_ENTITY_TYPES, TRUE)
      ? $this->buildConfigEntityMetadata($drupal_entity_type, $entity_type, $id, $languages)
      : $this->buildContentEntityMetadata($drupal_entity_type, $entity_type, $id, $languages);

    return new JsonResponse(['data' => $data]);
  }

  /**
   * Creates a translation auto-save entry copied from a source language.
   *
   * @param string $entity_type
   *   The route entity type slug (e.g. canvas_page).
   * @param string $id
   *   The entity ID.
   * @param string $target_langcode
   *   The target language code to create.
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The current request. Must contain source_langcode in the JSON body.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   A JSON response with the result of the translation creation.
   */
  public function createTranslation(string $entity_type, string $id, string $target_langcode, Request $request): JsonResponse {
    if (!isset(self::ENTITY_TYPE_MAP[$entity_type])) {
      throw new NotFoundHttpException("Unsupported entity type: $entity_type");
    }

    $body = json_decode($request->getContent(), TRUE);
    if (!is_array($body) || empty($body['source_langcode'])) {
      return new JsonResponse(
        ['error' => 'Missing required body parameter: source_langcode'],
        Response::HTTP_BAD_REQUEST
      );
    }

    $source_langcode = $body['source_langcode'];
    $languages = $this->languageManager->getLanguages();

    if (!isset($languages[$source_langcode])) {
      return new JsonResponse(
        ['error' => "Source language '$source_langcode' is not configured."],
        Response::HTTP_BAD_REQUEST
      );
    }

    if (!isset($languages[$target_langcode])) {
      return new JsonResponse(
        ['error' => "Target language '$target_langcode' is not configured."],
        Response::HTTP_BAD_REQUEST
      );
    }

    if ($source_langcode === $target_langcode) {
      return new JsonResponse(
        ['error' => 'Source and target language must be different.'],
        Response::HTTP_BAD_REQUEST
      );
    }

    $drupal_entity_type = self::ENTITY_TYPE_MAP[$entity_type];

    return in_array($entity_type, self::CONFIG_ENTITY_TYPES, TRUE)
      ? $this->createConfigEntityTranslation($drupal_entity_type, $entity_type, $id, $source_langcode, $target_langcode)
      : $this->createContentEntityTranslation($drupal_entity_type, $entity_type, $id, $source_langcode, $target_langcode);
  }

  /**
   * Builds translation metadata for a content entity across all languages.
   *
   * @param string $drupal_entity_type
   *   The internal Drupal entity type ID.
   * @param string $route_entity_type
   *   The route entity type slug.
   * @param string $id
   *   The entity ID.
   * @param \Drupal\Core\Language\LanguageInterface[] $languages
   *   All configured languages.
   *
   * @return array
   *   An array of language metadata entries.
   */
  private function buildContentEntityMetadata(
    string $drupal_entity_type,
    string $route_entity_type,
    string $id,
    array $languages,
  ): array {
    $storage = $this->entityTypeManager->getStorage($drupal_entity_type);
    $entity = $storage->load($id);
    if (!$entity) {
      throw new NotFoundHttpException("Entity $drupal_entity_type:$id not found.");
    }
    if (!$entity instanceof TranslatableInterface) {
      throw new NotFoundHttpException("Entity $drupal_entity_type:$id is not translatable.");
    }

    $default_langcode = $entity->getUntranslated()->language()->getId();
    $autoSaveStore = $this->keyValueFactory->get(AutoSaveManager::AUTO_SAVE_STORE);
    $result = [];

    foreach ($languages as $langcode => $language) {
      $is_default = ($langcode === $default_langcode);
      $exists = $entity->hasTranslation($langcode);

      if ($exists) {
        $translation = $entity->getTranslation($langcode);
        $result[] = [
          'langcode'      => $langcode,
          'label'         => $language->getName(),
          'exists'        => TRUE,
          'status'        => $this->resolveContentEntityStatus($translation),
          'last_modified' => $translation->hasField('changed') && !$translation->get('changed')->isEmpty()
            ? $this->formatTimestamp((int) $translation->get('changed')->value)
            : NULL,
          'created'       => $translation->hasField('created') && !$translation->get('created')->isEmpty()
            ? $this->formatTimestamp((int) $translation->get('created')->value)
            : NULL,
          'is_default'    => $is_default,
        ];
      }
      else {
        $autoSaveKey = $drupal_entity_type . ':' . $id . ':' . $langcode;
        $autoSaveDraft = $autoSaveStore->get($autoSaveKey);

        $result[] = [
          'langcode'      => $langcode,
          'label'         => $language->getName(),
          'exists'        => FALSE,
          'status'        => $autoSaveDraft !== NULL ? 'draft' : 'not_translated',
          'last_modified' => $autoSaveDraft !== NULL
            ? $this->formatTimestamp((int) $autoSaveDraft['updated'])
            : NULL,
          'created'       => NULL,
          'is_default'    => $is_default,
        ];
      }
    }

    return $result;
  }

  /**
   * Resolves the translation status string for a content entity translation.
   *
   * @param object $translation
   *   The entity translation object.
   *
   * @return string
   *   Either 'published' or 'draft'.
   */
  private function resolveContentEntityStatus(object $translation): string {
    if (method_exists($translation, 'isPublished') && $translation->isPublished()) {
      return 'published';
    }
    return 'draft';
  }

  /**
   * Creates a content entity translation auto-save from a source language.
   *
   * @param string $drupal_entity_type
   *   The internal Drupal entity type ID.
   * @param string $route_entity_type
   *   The route entity type slug.
   * @param string $id
   *   The entity ID.
   * @param string $source_langcode
   *   The source language code to copy from.
   * @param string $target_langcode
   *   The target language code to create.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   A JSON response with the result.
   */
  private function createContentEntityTranslation(
    string $drupal_entity_type,
    string $route_entity_type,
    string $id,
    string $source_langcode,
    string $target_langcode,
  ): JsonResponse {
    $storage = $this->entityTypeManager->getStorage($drupal_entity_type);
    $entity = $storage->load($id);
    if (!$entity) {
      throw new NotFoundHttpException("Entity $drupal_entity_type:$id not found.");
    }
    if (!$entity instanceof TranslatableInterface) {
      throw new NotFoundHttpException("Entity $drupal_entity_type:$id is not translatable.");
    }

    if (!$entity->hasTranslation($source_langcode)) {
      return new JsonResponse(
        ['error' => "Source translation '$source_langcode' does not exist."],
        Response::HTTP_BAD_REQUEST
      );
    }

    if ($entity->hasTranslation($target_langcode)) {
      return new JsonResponse(
        ['error' => "Translation '$target_langcode' already exists."],
        Response::HTTP_CONFLICT
      );
    }

    $source_translation = $entity->getTranslation($source_langcode);
    $source_values = $source_translation->toArray();

    foreach (['langcode', 'default_langcode', 'content_translation_source', 'revision_translation_affected'] as $key) {
      unset($source_values[$key]);
    }

    $new_translation = $entity->addTranslation($target_langcode, $source_values);

    $data                                  = $new_translation->toArray();
    $data['langcode']                      = [['value' => $target_langcode]];
    $data['default_langcode']              = [['value' => '0']];
    $data['content_translation_source']    = [['value' => $source_langcode]];
    $data['revision_translation_affected'] = [['value' => '1']];

    $autoSaveKey = $drupal_entity_type . ':' . $id . ':' . $target_langcode;
    $data_hash = hash('xxh64', json_encode($data, JSON_THROW_ON_ERROR));

    $this->keyValueFactory->get(AutoSaveManager::AUTO_SAVE_STORE)->set($autoSaveKey, [
      'entity_type' => $drupal_entity_type,
      'entity_id'   => $id,
      'data'        => $data,
      'langcode'    => $target_langcode,
      'label'       => $entity->label(),
      'data_hash'   => $data_hash,
      'client_id'   => NULL,
      'owner'       => (int) $this->currentUser()->id(),
      'updated'     => time(),
    ]);

    $this->cacheTagsInvalidator->invalidateTags([AutoSaveManager::CACHE_TAG]);

    return new JsonResponse([
      'message'         => "Translation '$target_langcode' created from '$source_langcode'.",
      'langcode'        => $target_langcode,
      'source_langcode' => $source_langcode,
      'entity_type'     => $drupal_entity_type,
      'entity_id'       => $id,
    ], Response::HTTP_CREATED);
  }

  /**
   * Builds translation metadata for a config entity across all languages.
   *
   * @param string $drupal_entity_type
   *   The internal Drupal entity type ID.
   * @param string $route_entity_type
   *   The route entity type slug.
   * @param string $id
   *   The entity ID.
   * @param \Drupal\Core\Language\LanguageInterface[] $languages
   *   All configured languages.
   *
   * @return array
   *   An array of language metadata entries.
   */
  private function buildConfigEntityMetadata(
    string $drupal_entity_type,
    string $route_entity_type,
    string $id,
    array $languages,
  ): array {
    $storage = $this->entityTypeManager->getStorage($drupal_entity_type);
    $entity = $storage->load($id);
    if (!$entity) {
      throw new NotFoundHttpException("Entity $drupal_entity_type:$id not found.");
    }

    $config_name = $entity->getConfigDependencyName();
    $default_langcode = $this->languageManager->getDefaultLanguage()->getId();
    $autoSaveStore = $this->keyValueFactory->get(AutoSaveManager::AUTO_SAVE_STORE);
    \assert($this->configLanguageOverride instanceof LanguageConfigFactoryOverride);
    $result = [];

    foreach ($languages as $langcode => $language) {
      $is_default = ($langcode === $default_langcode);

      if ($is_default) {
        $result[] = [
          'langcode'      => $langcode,
          'label'         => $language->getName(),
          'exists'        => TRUE,
          'status'        => 'published',
          'last_modified' => NULL,
          'created'       => NULL,
          'is_default'    => TRUE,
        ];
        continue;
      }

      $override = $this->configLanguageOverride->getOverride($langcode, $config_name);
      $exists = !empty($override->get('component_tree'));

      $autoSaveKey = $drupal_entity_type . ':' . $id . ':' . $langcode . ':config_override';
      $autoSaveDraft = $autoSaveStore->get($autoSaveKey);

      if ($exists) {
        $status = 'published';
      }
      elseif ($autoSaveDraft !== NULL) {
        $status = 'draft';
      }
      else {
        $status = 'not_translated';
      }

      $result[] = [
        'langcode'      => $langcode,
        'label'         => $language->getName(),
        'exists'        => $exists,
        'status'        => $status,
        'last_modified' => $autoSaveDraft !== NULL
          ? $this->formatTimestamp((int) $autoSaveDraft['updated'])
          : NULL,
        'created'       => NULL,
        'is_default'    => FALSE,
      ];
    }

    return $result;
  }

  /**
   * Creates a config entity translation auto-save from a source language.
   *
   * @param string $drupal_entity_type
   *   The internal Drupal entity type ID.
   * @param string $route_entity_type
   *   The route entity type slug.
   * @param string $id
   *   The entity ID.
   * @param string $source_langcode
   *   The source language code to copy from.
   * @param string $target_langcode
   *   The target language code to create.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   A JSON response with the result.
   */
  private function createConfigEntityTranslation(
    string $drupal_entity_type,
    string $route_entity_type,
    string $id,
    string $source_langcode,
    string $target_langcode,
  ): JsonResponse {
    $storage = $this->entityTypeManager->getStorage($drupal_entity_type);
    $entity = $storage->load($id);
    if (!$entity) {
      throw new NotFoundHttpException("Entity $drupal_entity_type:$id not found.");
    }

    $config_name = $entity->getConfigDependencyName();
    $default_langcode = $this->languageManager->getDefaultLanguage()->getId();
    \assert($this->configLanguageOverride instanceof LanguageConfigFactoryOverride);

    if ($source_langcode === $default_langcode) {
      $source_component_tree = $entity->toArray()['component_tree'] ?? [];
    }
    else {
      $source_override = $this->configLanguageOverride->getOverride(
        $source_langcode,
        $config_name
      );
      $source_component_tree = $source_override->get('component_tree') ?? [];
      if (empty($source_component_tree)) {
        return new JsonResponse(
          ['error' => "Source translation '$source_langcode' does not exist or has no component tree."],
          Response::HTTP_BAD_REQUEST
        );
      }
    }

    if ($target_langcode !== $default_langcode) {
      $existing = $this->configLanguageOverride->getOverride($target_langcode, $config_name);
      if (!empty($existing->get('component_tree'))) {
        return new JsonResponse(
          ['error' => "Translation '$target_langcode' already exists."],
          Response::HTTP_CONFLICT
        );
      }
    }

    $autoSaveKey = $drupal_entity_type . ':' . $id . ':' . $target_langcode . ':config_override';
    $this->keyValueFactory->get(AutoSaveManager::AUTO_SAVE_STORE)->set($autoSaveKey, [
      'entity_type'        => $drupal_entity_type,
      'entity_id'          => $id,
      'config_name'        => $config_name,
      'langcode'           => $target_langcode,
      'data'               => ['component_tree' => $source_component_tree],
      'label'              => $entity->label(),
      'data_hash'          => hash('xxh64', json_encode(['component_tree' => $source_component_tree], JSON_THROW_ON_ERROR)),
      'client_id'          => NULL,
      'owner'              => (int) $this->currentUser()->id(),
      'updated'            => time(),
      'is_config_override' => TRUE,
    ]);

    $this->cacheTagsInvalidator->invalidateTags([AutoSaveManager::CACHE_TAG]);

    return new JsonResponse([
      'message'         => "Translation '$target_langcode' created from '$source_langcode'.",
      'langcode'        => $target_langcode,
      'source_langcode' => $source_langcode,
      'entity_type'     => $drupal_entity_type,
      'entity_id'       => $id,
    ], Response::HTTP_CREATED);
  }

  /**
   * Formats a Unix timestamp into a structured array for API responses.
   *
   * @param int|null $timestamp
   *   The Unix timestamp to format.
   *
   * @return array|null
   *   An array with 'timestamp' and 'formatted' keys, or NULL if empty.
   */
  private function formatTimestamp(?int $timestamp): ?array {
    if (!$timestamp) {
      return NULL;
    }
    return [
      'timestamp' => $timestamp,
      'formatted' => date('Y-m-d H:i:s', $timestamp),
    ];
  }

}
