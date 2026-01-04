<?php

declare(strict_types=1);

namespace Drupal\jsonapi_frontend_layout\Controller;

use Drupal\Core\Cache\CacheableJsonResponse;
use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\jsonapi_frontend\Service\PathResolverInterface;
use Drupal\jsonapi_frontend_layout\Service\LayoutTreeBuilder;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;

/**
 * Layout-aware resolver endpoint.
 */
final class LayoutResolverController extends ControllerBase {

  private const CONTENT_TYPE = 'application/json; charset=utf-8';

  public function __construct(
    private readonly PathResolverInterface $resolver,
    private readonly LayoutTreeBuilder $layoutTreeBuilder,
  ) {}

  public static function create(ContainerInterface $container): self {
    return new self(
      $container->get('jsonapi_frontend.path_resolver'),
      $container->get('jsonapi_frontend_layout.layout_tree_builder'),
    );
  }

  public function resolve(Request $request): CacheableJsonResponse {
    $path = (string) $request->query->get('path', '');

    if (trim($path) === '') {
      return $this->errorResponse(
        status: 400,
        title: 'Bad Request',
        detail: 'Missing required query parameter: path',
      );
    }

    $langcode = $request->query->get('langcode');
    $langcode = is_string($langcode) && $langcode !== '' ? $langcode : NULL;

    $result = $this->resolver->resolve($path, $langcode);

    $cacheable = new CacheableMetadata();
    $cacheable->setCacheMaxAge($this->getCacheMaxAge());
    $cacheable->addCacheTags(['config:jsonapi_frontend.settings']);
    $cacheable->addCacheContexts([
      'url.query_args:path',
      'url.query_args:langcode',
      'url.site',
    ]);

    // Mirror jsonapi_frontend's language fallback cache behavior.
    $config = $this->config('jsonapi_frontend.settings');
    $langcode_fallback = (string) ($config->get('resolver.langcode_fallback') ?? 'site_default');
    if ($langcode_fallback === 'current') {
      $cacheable->addCacheContexts(['languages:language_content']);
    }

    // If this resolved to an entity, attempt to attach a layout tree.
    if (($result['resolved'] ?? FALSE) === TRUE && ($result['kind'] ?? NULL) === 'entity' && is_array($result['entity'] ?? NULL)) {
      $entity = $this->loadResolvedEntity($result['entity'], $langcode);
      if ($entity) {
        $cacheable->addCacheableDependency($entity);
        $layout = $this->layoutTreeBuilder->build($entity, $cacheable);
        if ($layout) {
          $result['layout'] = $layout;
        }
      }
    }

    $response = new CacheableJsonResponse($result, 200, [
      'Content-Type' => self::CONTENT_TYPE,
    ]);

    $response->addCacheableDependency($cacheable);
    $this->applySecurityHeaders($response, $cacheable->getCacheMaxAge());

    return $response;
  }

  /**
   * Load the resolved entity (by UUID) from a resolver result.
   *
   * @param array{type?: mixed, id?: mixed, langcode?: mixed} $entity_info
   *   The "entity" object from jsonapi_frontend.
   */
  private function loadResolvedEntity(array $entity_info, ?string $langcode): ?ContentEntityInterface {
    $resource_type = $entity_info['type'] ?? NULL;
    $uuid = $entity_info['id'] ?? NULL;

    if (!is_string($resource_type) || !is_string($uuid) || $resource_type === '' || $uuid === '') {
      return NULL;
    }

    $parts = explode('--', $resource_type, 2);
    if (count($parts) !== 2) {
      return NULL;
    }

    [$entity_type_id] = $parts;
    if ($entity_type_id === '') {
      return NULL;
    }

    $definition = $this->entityTypeManager()->getDefinition($entity_type_id, FALSE);
    if (!$definition || !$definition->entityClassImplements(ContentEntityInterface::class)) {
      return NULL;
    }

    $storage = $this->entityTypeManager()->getStorage($entity_type_id);
    $entities = $storage->loadByProperties(['uuid' => $uuid]);
    $entity = $entities ? reset($entities) : NULL;

    if (!$entity instanceof ContentEntityInterface) {
      return NULL;
    }

    // Apply the negotiated langcode from the resolver response if possible.
    $resolved_langcode = $entity_info['langcode'] ?? NULL;
    $resolved_langcode = is_string($resolved_langcode) && $resolved_langcode !== '' ? $resolved_langcode : $langcode;
    if ($resolved_langcode && method_exists($entity, 'hasTranslation') && $entity->hasTranslation($resolved_langcode)) {
      $entity = $entity->getTranslation($resolved_langcode);
    }

    // Re-check view access (defense in depth).
    return $entity->access('view') ? $entity : NULL;
  }

  private function errorResponse(int $status, string $title, string $detail): CacheableJsonResponse {
    $response = new CacheableJsonResponse([
      'errors' => [
        [
          'status' => (string) $status,
          'title' => $title,
          'detail' => $detail,
        ],
      ],
    ], $status, [
      'Content-Type' => self::CONTENT_TYPE,
    ]);

    $cacheable = new CacheableMetadata();
    $cacheable->setCacheMaxAge(0);
    $response->addCacheableDependency($cacheable);
    $this->applySecurityHeaders($response, 0);

    return $response;
  }

  private function getCacheMaxAge(): int {
    if (!$this->currentUser()->isAnonymous()) {
      return 0;
    }

    $config = $this->config('jsonapi_frontend.settings');
    $max_age = (int) ($config->get('resolver.cache_max_age') ?? 0);

    return max(0, $max_age);
  }

  private function applySecurityHeaders(CacheableJsonResponse $response, int $max_age): void {
    $response->headers->set('X-Content-Type-Options', 'nosniff');

    if ($max_age > 0) {
      $response->headers->set('Cache-Control', 'public, max-age=' . $max_age);
    }
    else {
      $response->headers->set('Cache-Control', 'no-store');
    }
  }

}
