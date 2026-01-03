<?php

declare(strict_types=1);

namespace Drupal\Tests\jsonapi_frontend_layout\Unit;

use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\RevisionableStorageInterface;
use Drupal\jsonapi_frontend_layout\Service\LayoutTreeBuilder;
use Drupal\layout_builder\SectionComponent;
use Drupal\layout_builder\SectionStorage\SectionStorageManagerInterface;
use Drupal\Tests\UnitTestCase;

/**
 * Unit tests for LayoutTreeBuilder normalization helpers.
 *
 * @group jsonapi_frontend_layout
 * @coversDefaultClass \Drupal\jsonapi_frontend_layout\Service\LayoutTreeBuilder
 */
final class LayoutTreeBuilderTest extends UnitTestCase {

  private function createBuilder(EntityTypeManagerInterface $entityTypeManager): LayoutTreeBuilder {
    return new LayoutTreeBuilder(
      $this->createMock(SectionStorageManagerInterface::class),
      $entityTypeManager,
    );
  }

  private function normalizeComponent(LayoutTreeBuilder $builder, SectionComponent $component): ?array {
    $cacheability = new CacheableMetadata();
    $ref = new \ReflectionMethod($builder, 'normalizeComponent');
    return $ref->invoke($builder, $component, $cacheability);
  }

  public function testNormalizeComponentReturnsNullWhenPluginIdMissing(): void {
    $entityTypeManager = $this->createMock(EntityTypeManagerInterface::class);
    $builder = $this->createBuilder($entityTypeManager);

    $component = new SectionComponent('uuid', 'content', [
      'label' => 'Broken',
    ]);

    $this->assertNull($this->normalizeComponent($builder, $component));
  }

  public function testNormalizeComponentParsesFieldBlocks(): void {
    $entityTypeManager = $this->createMock(EntityTypeManagerInterface::class);
    $builder = $this->createBuilder($entityTypeManager);

    $component = new SectionComponent('uuid', 'content', [
      'id' => 'field_block:node:page:title',
      'label' => 'Title',
      'label_display' => FALSE,
    ]);

    $normalized = $this->normalizeComponent($builder, $component);
    $this->assertIsArray($normalized);
    $this->assertSame('field', $normalized['type']);
    $this->assertSame([
      'entity_type_id' => 'node',
      'bundle' => 'page',
      'field_name' => 'title',
    ], $normalized['field']);
  }

  public function testNormalizeComponentHandlesMalformedFieldBlockIds(): void {
    $entityTypeManager = $this->createMock(EntityTypeManagerInterface::class);
    $builder = $this->createBuilder($entityTypeManager);

    $component = new SectionComponent('uuid', 'content', [
      'id' => 'field_block:node:page',
      'label' => 'Malformed',
      'label_display' => FALSE,
    ]);

    $normalized = $this->normalizeComponent($builder, $component);
    $this->assertIsArray($normalized);
    $this->assertSame('field', $normalized['type']);
    $this->assertNull($normalized['field']);
  }

  public function testNormalizeComponentInlineBlockHandlesInvalidRevisionIds(): void {
    $entityTypeManager = $this->createMock(EntityTypeManagerInterface::class);
    $builder = $this->createBuilder($entityTypeManager);

    $component = new SectionComponent('uuid', 'content', [
      'id' => 'inline_block:basic',
      'label' => 'Inline block',
      'block_revision_id' => 'nope',
      'view_mode' => 'full',
    ]);

    $normalized = $this->normalizeComponent($builder, $component);
    $this->assertIsArray($normalized);
    $this->assertSame('inline_block', $normalized['type']);
    $this->assertSame([
      'view_mode' => 'full',
      'block_revision_id' => NULL,
      'block' => NULL,
    ], $normalized['inline_block']);
  }

  public function testNormalizeComponentInlineBlockHandlesMissingBlockContentDefinition(): void {
    $entityTypeManager = $this->createMock(EntityTypeManagerInterface::class);
    $entityTypeManager->method('hasDefinition')->with('block_content')->willReturn(FALSE);

    $builder = $this->createBuilder($entityTypeManager);

    $component = new SectionComponent('uuid', 'content', [
      'id' => 'inline_block:basic',
      'label' => 'Inline block',
      'block_revision_id' => 123,
      'view_mode' => 'full',
    ]);

    $normalized = $this->normalizeComponent($builder, $component);
    $this->assertIsArray($normalized);
    $this->assertSame('inline_block', $normalized['type']);
    $this->assertSame(123, $normalized['inline_block']['block_revision_id']);
    $this->assertNull($normalized['inline_block']['block']);
  }

  public function testNormalizeComponentInlineBlockSkipsNotViewableBlocks(): void {
    $block = $this->createMock(ContentEntityInterface::class);
    $block->method('access')->with('view')->willReturn(FALSE);

    $storage = $this->createMock(RevisionableStorageInterface::class);
    $storage->method('loadRevision')->with(123)->willReturn($block);

    $entityTypeManager = $this->createMock(EntityTypeManagerInterface::class);
    $entityTypeManager->method('hasDefinition')->with('block_content')->willReturn(TRUE);
    $entityTypeManager->method('getStorage')->with('block_content')->willReturn($storage);

    $builder = $this->createBuilder($entityTypeManager);

    $component = new SectionComponent('uuid', 'content', [
      'id' => 'inline_block:basic',
      'label' => 'Inline block',
      'block_revision_id' => 123,
      'view_mode' => 'full',
    ]);

    $normalized = $this->normalizeComponent($builder, $component);
    $this->assertIsArray($normalized);
    $this->assertNull($normalized['inline_block']['block']);
  }

}
