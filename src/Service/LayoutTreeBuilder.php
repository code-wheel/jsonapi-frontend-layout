<?php

declare(strict_types=1);

namespace Drupal\jsonapi_frontend_layout\Service;

use Drupal\Component\Plugin\Exception\PluginException;
use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Plugin\Context\Context;
use Drupal\Core\Plugin\Context\ContextDefinition;
use Drupal\Core\Plugin\Context\EntityContext;
use Drupal\layout_builder\Entity\LayoutBuilderEntityViewDisplay;
use Drupal\layout_builder\Section;
use Drupal\layout_builder\SectionComponent;
use Drupal\layout_builder\SectionStorage\SectionStorageManagerInterface;

/**
 * Builds a normalized Layout Builder tree for headless rendering.
 */
final class LayoutTreeBuilder {

  public function __construct(
    private readonly SectionStorageManagerInterface $sectionStorageManager,
    private readonly EntityTypeManagerInterface $entityTypeManager,
  ) {}

  /**
   * Build a layout tree for an entity, if Layout Builder is enabled.
   *
   * @return array|null
   *   Normalized layout tree, or NULL if not applicable.
   */
  public function build(ContentEntityInterface $entity, CacheableMetadata $cacheability): ?array {
    // Layout Builder currently renders canonical entities in view mode "full"
    // (falling back to "default" if no dedicated display exists).
    $requested_view_mode = 'full';
    $display = LayoutBuilderEntityViewDisplay::collectRenderDisplay($entity, $requested_view_mode);
    $cacheability->addCacheableDependency($display);

    // If Layout Builder isn't enabled for this display, there is no layout tree.
    if (!method_exists($display, 'isLayoutBuilderEnabled') || !$display->isLayoutBuilderEnabled()) {
      return NULL;
    }

    $contexts = [
      'entity' => EntityContext::fromEntity($entity),
      'display' => EntityContext::fromEntity($display),
      'view_mode' => new Context(new ContextDefinition('string'), $requested_view_mode),
    ];

    $section_storage = $this->sectionStorageManager->findByContext($contexts, $cacheability);
    if (!$section_storage) {
      return NULL;
    }
    $cacheability->addCacheableDependency($section_storage);

    $sections = $section_storage->getSections();
    if (!$sections) {
      return NULL;
    }

    $normalized_sections = [];
    foreach ($sections as $section) {
      if (!$section instanceof Section) {
        continue;
      }

      $components = [];
      foreach ($section->getComponents() as $component) {
        if (!$component instanceof SectionComponent) {
          continue;
        }

        $normalized = $this->normalizeComponent($component, $cacheability);
        if ($normalized) {
          $components[] = $normalized;
        }
      }

      $normalized_sections[] = [
        'layout_id' => $section->getLayoutId(),
        'layout_settings' => $section->getLayoutSettings(),
        'components' => $components,
      ];
    }

    return [
      'source' => $section_storage->getStorageType(),
      'view_mode' => $display->getMode(),
      'sections' => $normalized_sections,
    ];
  }

  private function normalizeComponent(SectionComponent $component, CacheableMetadata $cacheability): ?array {
    try {
      $plugin_id = $component->getPluginId();
    }
    catch (PluginException) {
      return NULL;
    }

    $base = [
      'uuid' => $component->getUuid(),
      'region' => $component->getRegion(),
      'weight' => $component->getWeight(),
      'plugin_id' => $plugin_id,
    ];

    if (str_starts_with($plugin_id, 'field_block:') || str_starts_with($plugin_id, 'extra_field_block:')) {
      $field = $this->parseFieldLikePluginId($plugin_id);
      return [
        ...$base,
        'type' => 'field',
        'field' => $field,
        'settings' => $this->safeSettings($component),
      ];
    }

    if (str_starts_with($plugin_id, 'inline_block')) {
      $inline = $this->normalizeInlineBlock($component, $cacheability);
      return [
        ...$base,
        'type' => 'inline_block',
        'inline_block' => $inline,
        'settings' => $this->safeSettings($component),
      ];
    }

    // Unknown block/plugin: return metadata so frontends can decide.
    return [
      ...$base,
      'type' => 'block',
      'settings' => $this->safeSettings($component),
    ];
  }

  /**
   * Parse field-like block IDs: field_block:* and extra_field_block:*.
   *
   * @return array{entity_type_id: string, bundle: string, field_name: string}|null
   *   Parsed info, or NULL if not parsable.
   */
  private function parseFieldLikePluginId(string $plugin_id): ?array {
    $parts = explode(':', $plugin_id, 4);
    if (count($parts) !== 4) {
      return NULL;
    }

    [, $entity_type_id, $bundle, $field_name] = $parts;
    if ($entity_type_id === '' || $bundle === '' || $field_name === '') {
      return NULL;
    }

    return [
      'entity_type_id' => $entity_type_id,
      'bundle' => $bundle,
      'field_name' => $field_name,
    ];
  }

  /**
   * Normalize an inline block into a JSON:API reference when possible.
   *
   * @return array|null
   *   Inline block reference info.
   */
  private function normalizeInlineBlock(SectionComponent $component, CacheableMetadata $cacheability): ?array {
    $data = $component->toArray();
    $configuration = $data['configuration'] ?? [];
    if (!is_array($configuration)) {
      return NULL;
    }

    $view_mode = $configuration['view_mode'] ?? NULL;
    $view_mode = is_string($view_mode) && $view_mode !== '' ? $view_mode : NULL;

    $revision_id = $configuration['block_revision_id'] ?? NULL;
    if (!is_int($revision_id) && !(is_string($revision_id) && ctype_digit($revision_id))) {
      return [
        'view_mode' => $view_mode,
        'block_revision_id' => NULL,
        'block' => NULL,
      ];
    }

    $revision_id = (int) $revision_id;

    if (!$this->entityTypeManager->hasDefinition('block_content')) {
      return [
        'view_mode' => $view_mode,
        'block_revision_id' => $revision_id,
        'block' => NULL,
      ];
    }

    $block = $this->entityTypeManager->getStorage('block_content')->loadRevision($revision_id);
    if (!$block instanceof ContentEntityInterface) {
      return [
        'view_mode' => $view_mode,
        'block_revision_id' => $revision_id,
        'block' => NULL,
      ];
    }

    // Only include a reference if the block is viewable.
    if (!$block->access('view')) {
      return [
        'view_mode' => $view_mode,
        'block_revision_id' => $revision_id,
        'block' => NULL,
      ];
    }

    $cacheability->addCacheableDependency($block);

    $bundle = method_exists($block, 'bundle') ? $block->bundle() : NULL;
    if (!is_string($bundle) || $bundle === '') {
      return [
        'view_mode' => $view_mode,
        'block_revision_id' => $revision_id,
        'block' => NULL,
      ];
    }

    return [
      'view_mode' => $view_mode,
      'block_revision_id' => $revision_id,
      'block' => [
        'type' => sprintf('block_content--%s', $bundle),
        'id' => $block->uuid(),
        'jsonapi_url' => sprintf('/jsonapi/block_content/%s/%s', $bundle, $block->uuid()),
      ],
    ];
  }

  /**
   * Extract a small safe subset of block configuration for headless rendering.
   */
  private function safeSettings(SectionComponent $component): array {
    $data = $component->toArray();
    $configuration = $data['configuration'] ?? [];

    if (!is_array($configuration)) {
      return [];
    }

    $settings = [];
    foreach (['label', 'label_display', 'formatter', 'view_mode'] as $key) {
      if (array_key_exists($key, $configuration)) {
        $settings[$key] = $configuration[$key];
      }
    }

    return $settings;
  }

}
