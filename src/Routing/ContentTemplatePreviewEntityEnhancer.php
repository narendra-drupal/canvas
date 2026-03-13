<?php

namespace Drupal\canvas\Routing;

use Drupal\canvas\Entity\ContentTemplate;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Routing\EnhancerInterface;
use Drupal\Core\Routing\RouteObjectInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Route;

/**
 * Enhances routes to expose preview entities as route parameters.
 *
 * Canvas content template preview routes use a generic 'preview_entity'
 * parameter that can be any entity type. However, core Views default argument
 * plugins hard-code specific entity type parameter names (e.g., 'node', 'user',
 * 'taxonomy_term') when calling $routeMatch->getParameter(). This means Views
 * contextual filters that rely on route context won't work in Canvas previews
 * without this enhancer.
 *
 * This enhancer adds the preview entity as a route parameter using the target
 * entity type ID (e.g., 'node'), allowing Views contextual filters to find the
 * entity via $routeMatch->getParameter() when rendering content template
 * previews.
 *
 * @see \Drupal\node\Plugin\views\argument_default\Node::getArgument()
 * @see \Drupal\user\Plugin\views\argument_default\User::getArgument()
 * @see \Drupal\taxonomy\Plugin\views\argument_default\Tid::getArgument()
 *
 * @internal
 */
class ContentTemplatePreviewEntityEnhancer implements EnhancerInterface {

  private static function getRoutePreviewEntityParameter(Route $route): ?string {
    $parameters = $route->getOption('parameters');
    if (empty($parameters)) {
      return NULL;
    }
    \assert(\is_array($parameters));
    foreach ($parameters as $parameter_name => $parameter) {
      if (isset($parameter['type']) && $parameter['type'] === 'canvas_content_template_preview_entity') {
        return $parameter_name;
      }
    }

    return NULL;
  }

  public function enhance(array $defaults, Request $request) {
    $route = $defaults[RouteObjectInterface::ROUTE_OBJECT];
    if (!$route instanceof Route || !isset($defaults['entity']) || !$defaults['entity'] instanceof ContentTemplate) {
      return $defaults;
    }
    $preview_entity_parameter = self::getRoutePreviewEntityParameter($route);
    if ($preview_entity_parameter === NULL) {
      return $defaults;
    }
    if (isset($defaults[$preview_entity_parameter]) && $defaults[$preview_entity_parameter] instanceof ContentEntityInterface) {
      // Add route parameter for the target entity type so views contextual
      // filters can find the entity via $routeMatch->getParameter().
      $target_entity_type_id = $defaults['entity']->getTargetEntityTypeId();
      $defaults[$target_entity_type_id] = $defaults[$preview_entity_parameter];
      // Set as route default so RouteMatch::getParameterNames() includes it.
      $route->setDefault($target_entity_type_id, $defaults[$preview_entity_parameter]);
    }
    return $defaults;
  }

}
