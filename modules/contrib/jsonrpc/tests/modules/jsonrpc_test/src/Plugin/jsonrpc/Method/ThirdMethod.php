<?php

namespace Drupal\jsonrpc_test\Plugin\jsonrpc\Method;

use Drupal\jsonrpc\Handler;
use Drupal\jsonrpc\Object\ParameterBag;
use Drupal\jsonrpc\Object\Response;
use Drupal\jsonrpc\Plugin\JsonRpcMethodBase;
use Symfony\Component\HttpFoundation\HeaderBag;

/**
 * Third test method.
 *
 * @JsonRpcMethod(
 *   id = "third.test",
 *   usage = @Translation("Third test method."),
 *   access = {"access content"},
 * )
 */
class ThirdMethod extends JsonRpcMethodBase {

  /**
   * {@inheritdoc}
   */
  public function execute(ParameterBag $params) {
    return new Response(
      Handler::SUPPORTED_VERSION,
      $this->currentRequest()->id(),
      'invalid',
      NULL,
      new HeaderBag(['foo' => 'oof', 'hello' => NULL, 'bye' => 'bye!'])
    );
  }

  /**
   * {@inheritdoc}
   */
  public static function outputSchema() {
    return [
      // Schema is invalid intentionally.
      'type' => 'number',
    ];
  }

}
