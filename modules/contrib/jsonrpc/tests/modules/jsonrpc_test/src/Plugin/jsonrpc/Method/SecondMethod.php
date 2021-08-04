<?php

namespace Drupal\jsonrpc_test\Plugin\jsonrpc\Method;

use Drupal\jsonrpc\Handler;
use Drupal\jsonrpc\Object\ParameterBag;
use Drupal\jsonrpc\Object\Response;
use Drupal\jsonrpc\Plugin\JsonRpcMethodBase;
use Symfony\Component\HttpFoundation\HeaderBag;

/**
 * Second test method.
 *
 * @JsonRpcMethod(
 *   id = "second.test",
 *   usage = @Translation("Second test method."),
 *   access = {"access content"},
 * )
 */
class SecondMethod extends JsonRpcMethodBase {

  /**
   * {@inheritdoc}
   */
  public function execute(ParameterBag $params) {
    return new Response(
      Handler::SUPPORTED_VERSION,
      $this->currentRequest()->id(),
      mt_rand(0, 100),
      NULL,
      new HeaderBag(['foo' => 'oof', 'hello' => NULL, 'bye' => 'bye!'])
    );
  }

  /**
   * {@inheritdoc}
   */
  public static function outputSchema() {
    return [
      'type' => 'number',
    ];
  }

}
