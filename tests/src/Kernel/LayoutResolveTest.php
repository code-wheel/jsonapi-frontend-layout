<?php

declare(strict_types=1);

namespace Drupal\Tests\jsonapi_frontend_layout\Kernel;

use Drupal\block_content\Entity\BlockContent;
use Drupal\block_content\Entity\BlockContentType;
use Drupal\KernelTests\KernelTestBase;
use Drupal\layout_builder\Section;
use Drupal\layout_builder\SectionComponent;
use Drupal\node\Entity\Node;
use Drupal\node\Entity\NodeType;
use Drupal\user\Entity\User;
use Symfony\Component\HttpFoundation\Request;

/**
 * Kernel tests for layout-aware resolution.
 *
 * @group jsonapi_frontend_layout
 */
#[\PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses]
final class LayoutResolveTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'field',
    'file',
    'node',
    'path',
    'path_alias',
    'block',
    'layout_discovery',
    'contextual',
    'block_content',
    'layout_builder',
    'jsonapi',
    'serialization',
    'jsonapi_frontend',
    'jsonapi_frontend_layout',
  ];

  protected function setUp(): void {
    parent::setUp();

    $this->installEntitySchema('user');
    $this->installEntitySchema('node');
    $this->installEntitySchema('path_alias');
    $this->installEntitySchema('block_content');
    $this->installSchema('node', ['node_access']);

    $this->installConfig(['jsonapi_frontend']);

    // Ensure route validation and access checks run as an allowed user.
    $admin = User::create([
      'name' => 'admin',
      'status' => 1,
    ]);
    $admin->save();
    $this->container->get('current_user')->setAccount($admin);

    NodeType::create([
      'type' => 'page',
      'name' => 'Page',
    ])->save();

    BlockContentType::create([
      'id' => 'basic',
      'label' => 'Basic block',
    ])->save();

    $block = BlockContent::create([
      'type' => 'basic',
      'info' => 'Test block',
    ]);
    $block->save();

    $this->enableLayoutBuilderOnPageDisplay((int) $block->getRevisionId());
  }

  public function testLayoutResolveIncludesLayoutTree(): void {
    $node = Node::create([
      'type' => 'page',
      'title' => 'About Us',
      'status' => 1,
      'path' => ['alias' => '/about-us'],
    ]);
    $node->save();

    $controller = \Drupal\jsonapi_frontend_layout\Controller\LayoutResolverController::create($this->container);
    $request = Request::create('/jsonapi/layout/resolve', 'GET', [
      'path' => '/about-us',
      '_format' => 'json',
    ]);

    $response = $controller->resolve($request);
    $payload = json_decode((string) $response->getContent(), TRUE);

    $this->assertIsArray($payload);
    $this->assertTrue($payload['resolved']);
    $this->assertSame('entity', $payload['kind']);
    $this->assertSame($node->uuid(), $payload['entity']['id']);

    $this->assertArrayHasKey('layout', $payload);
    $this->assertIsArray($payload['layout']);
    $this->assertIsArray($payload['layout']['sections']);
    $this->assertNotEmpty($payload['layout']['sections']);

    $first_section = $payload['layout']['sections'][0];
    $this->assertSame('layout_onecol', $first_section['layout_id']);
    $this->assertIsArray($first_section['components']);

    $component_types = array_map(static fn (array $c) => $c['type'] ?? NULL, $first_section['components']);
    $this->assertContains('field', $component_types);
    $this->assertContains('inline_block', $component_types);
    $this->assertContains('block', $component_types);
  }

  public function testResolveReturns400WhenPathMissing(): void {
    $controller = \Drupal\jsonapi_frontend_layout\Controller\LayoutResolverController::create($this->container);
    $request = Request::create('/jsonapi/layout/resolve', 'GET', [
      '_format' => 'json',
    ]);

    $response = $controller->resolve($request);
    $this->assertSame(400, $response->getStatusCode());
  }

  public function testLayoutIsOmittedWhenLayoutBuilderDisabled(): void {
    NodeType::create([
      'type' => 'article',
      'name' => 'Article',
    ])->save();

    // Ensure a display exists, but keep Layout Builder disabled.
    $display = $this->container->get('entity_type.manager')
      ->getStorage('entity_view_display')
      ->create([
        'id' => 'node.article.default',
        'targetEntityType' => 'node',
        'bundle' => 'article',
        'mode' => 'default',
        'status' => TRUE,
      ]);
    $display->save();

    $node = Node::create([
      'type' => 'article',
      'title' => 'No Layout Builder',
      'status' => 1,
      'path' => ['alias' => '/no-layout'],
    ]);
    $node->save();

    $controller = \Drupal\jsonapi_frontend_layout\Controller\LayoutResolverController::create($this->container);
    $request = Request::create('/jsonapi/layout/resolve', 'GET', [
      'path' => '/no-layout',
      '_format' => 'json',
    ]);

    $response = $controller->resolve($request);
    $payload = json_decode((string) $response->getContent(), TRUE);

    $this->assertIsArray($payload);
    $this->assertTrue($payload['resolved']);
    $this->assertSame($node->uuid(), $payload['entity']['id']);
    $this->assertArrayNotHasKey('layout', $payload);
  }

  public function testLayoutIsOmittedWhenResolverEntityInfoIsInvalid(): void {
    $resolver = new class implements \Drupal\jsonapi_frontend\Service\PathResolverInterface {
      public function resolve(string $path, ?string $langcode = NULL): array {
        return [
          'resolved' => TRUE,
          'kind' => 'entity',
          'canonical' => '/fake',
          'entity' => [
            'type' => 'user_role--user_role',
            'id' => 'uuid-does-not-matter',
            'langcode' => 'en',
          ],
          'redirect' => NULL,
          'jsonapi_url' => '/jsonapi/user_role/user_role/uuid-does-not-matter',
          'data_url' => NULL,
          'headless' => TRUE,
          'drupal_url' => NULL,
        ];
      }
    };

    $this->container->set('jsonapi_frontend.path_resolver', $resolver);

    $controller = \Drupal\jsonapi_frontend_layout\Controller\LayoutResolverController::create($this->container);
    $request = Request::create('/jsonapi/layout/resolve', 'GET', [
      'path' => '/fake',
      '_format' => 'json',
    ]);

    $response = $controller->resolve($request);
    $payload = json_decode((string) $response->getContent(), TRUE);

    $this->assertIsArray($payload);
    $this->assertTrue($payload['resolved']);
    $this->assertArrayNotHasKey('layout', $payload);
  }

  private function enableLayoutBuilderOnPageDisplay(int $block_revision_id): void {
    /** @var \Drupal\layout_builder\Entity\LayoutBuilderEntityViewDisplay $display */
    $display = $this->container->get('entity_type.manager')
      ->getStorage('entity_view_display')
      ->create([
        'id' => 'node.page.default',
        'targetEntityType' => 'node',
        'bundle' => 'page',
        'mode' => 'default',
        'status' => TRUE,
      ]);

    $display->enableLayoutBuilder();
    $display->setOverridable(FALSE);
    $display->removeAllSections();

    $section = new Section('layout_onecol');

    // Include at least one field block.
    $component = new SectionComponent('component-1', 'content', [
      'id' => 'field_block:node:page:title',
      'label' => 'Title',
      'label_display' => FALSE,
    ]);
    $section->appendComponent($component);

    $inline_block = new SectionComponent('component-2', 'content', [
      'id' => 'inline_block:basic',
      'label' => 'Inline block',
      'label_display' => FALSE,
      'block_revision_id' => $block_revision_id,
      'view_mode' => 'full',
    ]);
    $section->appendComponent($inline_block);

    // Invalid revision id should still produce an inline_block entry.
    $inline_invalid = new SectionComponent('component-2b', 'content', [
      'id' => 'inline_block:basic',
      'label' => 'Inline block invalid revision',
      'label_display' => FALSE,
      'block_revision_id' => 'nope',
      'view_mode' => 'full',
    ]);
    $section->appendComponent($inline_invalid);

    // Unknown revision should return a NULL block reference.
    $inline_missing = new SectionComponent('component-2c', 'content', [
      'id' => 'inline_block:basic',
      'label' => 'Inline block missing revision',
      'label_display' => FALSE,
      'block_revision_id' => 999999,
      'view_mode' => 'full',
    ]);
    $section->appendComponent($inline_missing);

    $unknown_block = new SectionComponent('component-3', 'content', [
      'id' => 'system_powered_by_block',
      'label' => 'Powered by',
      'label_display' => FALSE,
    ]);
    $section->appendComponent($unknown_block);

    $display->appendSection($section);
    $display->save();
  }

}
