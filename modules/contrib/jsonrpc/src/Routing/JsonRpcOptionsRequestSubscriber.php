<?php

namespace Drupal\jsonrpc\Routing;

use Drupal\Core\EventSubscriber\OptionsRequestSubscriber;
use Drupal\Core\Routing\RouteProviderInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\KernelEvent;

/**
 * Handles options requests.
 */
class JsonRpcOptionsRequestSubscriber implements EventSubscriberInterface {

  /**
   * The route provider.
   *
   * @var \Drupal\Core\Routing\RouteProviderInterface
   */
  protected $routeProvider;

  /**
   * The decorated service.
   *
   * @var \Drupal\Core\EventSubscriber\OptionsRequestSubscriber
   */
  protected $subject;

  /**
   * Creates a new OptionsRequestSubscriber instance.
   *
   * @param \Drupal\Core\Routing\RouteProviderInterface $route_provider
   *   The route provider.
   * @param \Drupal\Core\EventSubscriber\OptionsRequestSubscriber $subject
   *   The decorated service.
   */
  public function __construct(RouteProviderInterface $route_provider, OptionsRequestSubscriber $subject) {
    $this->routeProvider = $route_provider;
    $this->subject = $subject;
  }

  /**
   * Tries to handle the options request.
   *
   * @param \Symfony\Component\HttpKernel\Event\KernelEvent $event
   *   The request event.
   */
  public function onRequest(KernelEvent $event) {
    $request = $event->getRequest();
    if (!$request->isMethod('OPTIONS') || $request->headers->get('access-control-request-method') === 'POST') {
      return;
    }
    $routes = $this->routeProvider->getRouteCollectionForRequest($request);
    // If all routes are for JSON-RPC let the module handle them.
    $all_jsonrpc = array_reduce(
      array_keys($routes->all()),
      function (bool $carry, string $route_name) {
        return $carry && $route_name === 'jsonrpc.handler';
      },
      TRUE
    );
    if (!$all_jsonrpc) {
      $this->subject->onRequest($event);
    }
  }

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents() {
    return OptionsRequestSubscriber::getSubscribedEvents();
  }

}
