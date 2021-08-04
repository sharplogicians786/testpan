<?php

namespace Drupal\jsonrpc_test\Plugin\jsonrpc\Method;

use Drupal\jsonrpc\Object\ParameterBag;
use Drupal\jsonrpc\Plugin\JsonRpcMethodBase;

/**
 * First test method.
 *
 * @JsonRpcMethod(
 *   id = "first.test",
 *   usage = @Translation("First test method."),
 *   access = {"access content"},
 *   responseHeaders = {
 *     "foo": "bar",
 *     "lorem": "ipsum",
 *     "hello": "world",
 *   }
 * )
 */
class FirstMethod extends JsonRpcMethodBase {

  /**
   * {@inheritdoc}
   */
  public function execute(ParameterBag $params) {
    return mt_rand(0, 100);
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
