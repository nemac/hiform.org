<?php

namespace Drupal\Core\Menu;

use Drupal\Component\Plugin\PluginBase;
use Drupal\Core\Cache\Cache;
use Drupal\Core\Cache\CacheableDependencyInterface;
use Drupal\Core\DependencyInjection\DependencySerializationTrait;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\Core\Routing\RouteProviderInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;

/**
 * Provides a default implementation for local action plugins.
 */
class LocalActionDefault extends PluginBase implements LocalActionInterface, ContainerFactoryPluginInterface, CacheableDependencyInterface {

  use DependencySerializationTrait;

  /**
   * The route provider to load routes by name.
   *
   * @var \Drupal\Core\Routing\RouteProviderInterface
   */
  protected $routeProvider;

  /**
   * Constructs a LocalActionDefault object.
   *
   * @param array $configuration
   *   A configuration array containing information about the plugin instance.
   * @param string $plugin_id
   *   The plugin ID for the plugin instance.
   * @param mixed $plugin_definition
   *   The plugin implementation definition.
   * @param \Drupal\Core\Routing\RouteProviderInterface $route_provider
   *   The route provider to load routes by name.
   */
  public function __construct(array $configuration, $plugin_id, $plugin_definition, RouteProviderInterface $route_provider) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);

    $this->routeProvider = $route_provider;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('router.route_provider')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getRouteName() {
    return $this->pluginDefinition['route_name'];
  }

  /**
   * {@inheritdoc}
   */
  public function getTitle(?Request $request = NULL) {
    // Subclasses may pull in the request or specific attributes as parameters.
    // The title from YAML file discovery may be a TranslatableMarkup object.
    return (string) $this->pluginDefinition['title'];
  }

  /**
   * {@inheritdoc}
   */
  public function getWeight() {
    return $this->pluginDefinition['weight'];
  }

  /**
   * {@inheritdoc}
   */
  public function getRouteParameters(RouteMatchInterface $route_match) {
    $route_parameters = $this->pluginDefinition['route_parameters'] ?? [];
    $route = $this->routeProvider->getRouteByName($this->getRouteName());
    $variables = $route->compile()->getVariables();

    // Normally the \Drupal\Core\ParamConverter\ParamConverterManager has
    // run, and the route parameters have been upcast. The original values can
    // be retrieved from the raw parameters. For example, if the route's path is
    // /filter/tips/{filter_format} and the path is /filter/tips/plain_text then
    // $raw_parameters->get('filter_format') == 'plain_text'. Parameters that
    // are not represented in the route path as slugs might be added by a route
    // enhancer and will not be present in the raw parameters.
    $raw_parameters = $route_match->getRawParameters();
    $parameters = $route_match->getParameters();

    foreach ($variables as $name) {
      if (isset($route_parameters[$name])) {
        continue;
      }

      if ($raw_parameters->has($name)) {
        $route_parameters[$name] = $raw_parameters->get($name);
      }
      elseif ($parameters->has($name)) {
        $route_parameters[$name] = $parameters->get($name);
      }
    }

    // The UrlGenerator will throw an exception if expected parameters are
    // missing. This method should be overridden if that is possible.
    return $route_parameters;
  }

  /**
   * {@inheritdoc}
   */
  public function getOptions(RouteMatchInterface $route_match) {
    return (array) $this->pluginDefinition['options'];
  }

  /**
   * {@inheritdoc}
   */
  public function getCacheTags() {
    if (!isset($this->pluginDefinition['cache_tags'])) {
      return [];
    }
    return $this->pluginDefinition['cache_tags'];
  }

  /**
   * {@inheritdoc}
   */
  public function getCacheContexts() {
    if (!isset($this->pluginDefinition['cache_contexts'])) {
      return [];
    }
    return $this->pluginDefinition['cache_contexts'];
  }

  /**
   * {@inheritdoc}
   */
  public function getCacheMaxAge() {
    if (!isset($this->pluginDefinition['cache_max_age'])) {
      return Cache::PERMANENT;
    }
    return $this->pluginDefinition['cache_max_age'];
  }

}
