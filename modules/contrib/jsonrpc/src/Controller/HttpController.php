<?php

namespace Drupal\jsonrpc\Controller;

use Drupal\Component\Serialization\Json;
use Drupal\Core\Cache\CacheableJsonResponse;
use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Cache\CacheableResponseInterface;
use Drupal\Core\Controller\ControllerBase;
use Drupal\jsonrpc\Exception\JsonRpcException;
use Drupal\jsonrpc\Object\Request as RpcRequest;
use Drupal\jsonrpc\Object\Response as RpcResponse;
use Drupal\jsonrpc\Shaper\RpcRequestFactory;
use Drupal\jsonrpc\Shaper\RpcResponseFactory;
use Shaper\Util\Context;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\HeaderBag;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The main front controller.
 *
 * Handles all the incoming requests HTTP requests and responses.
 */
class HttpController extends ControllerBase {

  /**
   * The RPC handler service.
   *
   * @var \Drupal\jsonrpc\HandlerInterface
   */
  protected $handler;

  /**
   * The JSON Schema validator service.
   *
   * @var \JsonSchema\Validator
   */
  protected $validator;

  /**
   * The service container.
   *
   * @var \Symfony\Component\DependencyInjection\ContainerInterface
   */
  protected $container;

  /**
   * HttpController constructor.
   *
   * @param \Symfony\Component\DependencyInjection\ContainerInterface $container
   *   The service container.
   */
  public function __construct(ContainerInterface $container) {
    $this->handler = $container->get('jsonrpc.handler');
    $this->validator = $container->get('jsonrpc.schema_validator');
    $this->container = $container;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static($container);
  }

  /**
   * Resolves a preflight request for an RPC request.
   *
   * @param \Symfony\Component\HttpFoundation\Request $http_request
   *   The HTTP request.
   *
   * @return \Drupal\Core\Cache\CacheableResponseInterface
   *   The HTTP response.
   */
  public function preflight(Request $http_request) {
    try {
      $rpc_requests = $this->getRpcRequests($http_request);
    }
    catch (JsonRpcException $e) {
      return $this->exceptionResponse($e, Response::HTTP_BAD_REQUEST);
    }
    $response_headers = array_reduce($rpc_requests, function (?HeaderBag $carry, RpcRequest $request) {
      $headers = $this->handler->getMethod($request->getMethod())->responseHeaders;
      $intersected_headers = $carry
        ? array_intersect_key($carry->all(), $headers)
        : $headers;
      return new HeaderBag($intersected_headers);
    });
    $http_response = CacheableJsonResponse::create(
      NULL,
      Response::HTTP_NO_CONTENT,
      $response_headers->all()
    );
    // Make sure to provide allowed methods.
    $http_response->headers->add(['allow' => ['OPTIONS, GET, HEAD, POST']]);
    // Varies the response based on the 'query' parameter.
    $cache_context = (new CacheableMetadata())
      ->setCacheContexts(['url.query_args:query', 'headers:origin']);
    $http_response->addCacheableDependency($cache_context);

    return $http_response;
  }

  /**
   * Resolves an RPC request over HTTP.
   *
   * @param \Symfony\Component\HttpFoundation\Request $http_request
   *   The HTTP request.
   *
   * @return \Drupal\Core\Cache\CacheableResponseInterface
   *   The HTTP response.
   */
  public function resolve(Request $http_request) {
    // Handle preflight requests.
    if ($http_request->getMethod() === Request::METHOD_OPTIONS) {
      return $this->preflight($http_request);
    }
    // Map the HTTP request to an RPC request.
    try {
      $rpc_requests = $this->getRpcRequests($http_request);
    }
    catch (JsonRpcException $e) {
      return $this->exceptionResponse($e, Response::HTTP_BAD_REQUEST);
    }

    // Execute the RPC request and get the RPC response.
    try {
      $rpc_responses = $this->getRpcResponses($rpc_requests);

      // Aggregate the response headers so we can add them to the HTTP response.
      $header_bag = $this->aggregateResponseHeaders($rpc_responses);

      // If no RPC response(s) were generated (happens if all of the request(s)
      // were notifications), then return a 204 HTTP response.
      if (empty($rpc_responses)) {
        $response = CacheableJsonResponse::create(NULL, Response::HTTP_NO_CONTENT);
        $response->headers->add($header_bag->all());
        return $response;
      }

      // Map the RPC response(s) to an HTTP response.
      $is_batched_response = count($rpc_requests) !== 1 || $rpc_requests[0]->isInBatch();
      $response = $this->getHttpResponse($rpc_responses, $is_batched_response);
      assert($response instanceof Response);
      $response->headers->add($header_bag->all());
      return $response;
    }
    catch (JsonRpcException $e) {
      return $this->exceptionResponse($e, Response::HTTP_INTERNAL_SERVER_ERROR);
    }
  }

  /**
   * Get the JSON RPC request objects for the given Request object.
   *
   * @param \Symfony\Component\HttpFoundation\Request $http_request
   *   The HTTP request.
   *
   * @return \Drupal\jsonrpc\Object\Request[]
   *   The JSON-RPC request or requests.
   *
   * @throws \Drupal\jsonrpc\Exception\JsonRpcException
   *   When there was an error handling the response.
   */
  protected function getRpcRequests(Request $http_request) {
    $version = $this->handler->supportedVersion();
    try {
      if ($http_request->getMethod() === Request::METHOD_POST) {
        $content = Json::decode($http_request->getContent(FALSE));
      }
      elseif ($http_request->getMethod() === Request::METHOD_GET) {
        $content = Json::decode($http_request->query->get('query'));
      }
      elseif ($http_request->getMethod() === Request::METHOD_OPTIONS) {
        // OPTIONS requests generated from a browser during a preflight will not
        // contain a body.
        $content = Json::decode($http_request->query->get('query'));
      }
      $context = new Context([
        RpcRequestFactory::REQUEST_VERSION_KEY => $version,
      ]);
      $factory = new RpcRequestFactory($this->handler, $this->container, $this->validator);
      return $factory->transform($content, $context);
    }
    catch (\Exception $e) {
      $id = (isset($content) && is_object($content) && isset($content->id)) ? $content->id : FALSE;
      throw JsonRpcException::fromPrevious($e, $id, $version);
    }
    catch (\TypeError $e) {
      $id = (isset($content) && is_object($content) && isset($content->id)) ? $content->id : FALSE;
      throw JsonRpcException::fromPrevious($e, $id, $version);
    }
  }

  /**
   * Get the JSON RPC request objects for the given JSON RPC request objects.
   *
   * @param \Drupal\jsonrpc\Object\Request[] $rpc_requests
   *   The RPC request objects.
   *
   * @return \Drupal\jsonrpc\Object\Response[]|null
   *   The JSON-RPC response(s). NULL when the RPC request contains only
   *   notifications.
   *
   * @throws \Drupal\jsonrpc\Exception\JsonRpcException
   */
  protected function getRpcResponses(array $rpc_requests) {
    $rpc_responses = $this->handler->batch($rpc_requests);
    return empty($rpc_responses)
      ? NULL
      : $rpc_responses;
  }

  /**
   * Map RPC response(s) to an HTTP response.
   *
   * @param \Drupal\jsonrpc\Object\Response[] $rpc_responses
   *   The RPC responses.
   * @param bool $is_batched_response
   *   True if the response is batched.
   *
   * @return \Drupal\Core\Cache\CacheableResponseInterface
   *   The cacheable HTTP version of the RPC response(s).
   *
   * @throws \Drupal\jsonrpc\Exception\JsonRpcException
   */
  protected function getHttpResponse(array $rpc_responses, $is_batched_response) {
    try {
      $serialized = $this->serializeRpcResponse($rpc_responses, $is_batched_response);
      $http_response = CacheableJsonResponse::fromJsonString($serialized, Response::HTTP_OK);
      // Varies the response based on the 'query' parameter.
      $cache_context = (new CacheableMetadata())
        ->setCacheContexts(['url.query_args:query']);
      $http_response->addCacheableDependency($cache_context);
      // Adds the cacheability information of the RPC response(s) to the HTTP
      // response.
      return array_reduce($rpc_responses, function (CacheableResponseInterface $http_response, $response) {
        return $http_response->addCacheableDependency($response);
      }, $http_response);
    }
    catch (\Exception | \TypeError $e) {
      throw JsonRpcException::fromPrevious($e, FALSE, $this->handler->supportedVersion());
    }
  }

  /**
   * Serializes the RPC response object into JSON.
   *
   * @param \Drupal\jsonrpc\Object\Response[] $rpc_responses
   *   The response objects.
   * @param bool $is_batched_response
   *   True if this is a batched response.
   *
   * @return string
   *   The serialized JSON-RPC response body.
   */
  protected function serializeRpcResponse(array $rpc_responses, $is_batched_response) {
    $context = new Context([
      RpcResponseFactory::RESPONSE_VERSION_KEY => $this->handler->supportedVersion(),
      RpcRequestFactory::REQUEST_IS_BATCH_REQUEST => $is_batched_response,
    ]);
    // This following is needed to prevent the serializer from using array
    // indices as JSON object keys like {"0": "foo", "1": "bar"}.
    $data = array_values($rpc_responses);
    $normalizer = new RpcResponseFactory($this->validator);
    return Json::encode($normalizer->transform($data, $context));
  }

  /**
   * Generates the expected response for a given exception.
   *
   * @param \Drupal\jsonrpc\Exception\JsonRpcException $e
   *   The exception that generates the error response.
   * @param int $status
   *   The response HTTP status.
   *
   * @return \Drupal\Core\Cache\CacheableResponseInterface
   *   The response object.
   */
  protected function exceptionResponse(JsonRpcException $e, $status = Response::HTTP_INTERNAL_SERVER_ERROR) {
    $context = new Context([
      RpcResponseFactory::RESPONSE_VERSION_KEY => $this->handler->supportedVersion(),
      RpcRequestFactory::REQUEST_IS_BATCH_REQUEST => FALSE,
    ]);
    $normalizer = new RpcResponseFactory($this->validator);
    $rpc_response = $e->getResponse();
    $serialized = Json::encode($normalizer->transform([$rpc_response], $context));
    $response = CacheableJsonResponse::fromJsonString($serialized, $status);
    return $response->addCacheableDependency($rpc_response);
  }

  /**
   * Intersects all the headers in the RPC responses into a single bag.
   *
   * @param \Drupal\jsonrpc\Object\Response[] $rpc_responses
   *   The RPC responses.
   *
   * @return \Symfony\Component\HttpFoundation\HeaderBag
   *   The aggregated header bag.
   */
  public function aggregateResponseHeaders(array $rpc_responses): HeaderBag {
    return array_reduce($rpc_responses, function (?HeaderBag $carry, RpcResponse $response) {
      $intersected_headers = $carry ? array_intersect_key(
        $carry->all(),
        $response->getHeaders()->all()
      ) : $response->getHeaders()->all();
      return new HeaderBag($intersected_headers);
    });
  }

}
