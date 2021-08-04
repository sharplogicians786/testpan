<?php

namespace Drupal\Tests\jsonrpc\Functional;

use Drupal\Component\Serialization\Json;
use Drupal\user\Entity\Role;
use Drupal\user\RoleInterface;

/**
 * Test turning the maintenance mode on or off using JSON RPC.
 *
 * @group jsonrpc
 */
class ResponseHeadersTest extends JsonRpcTestBase {

  protected static $modules = [
    'jsonrpc',
    'jsonrpc_test',
    'serialization',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->grantPermissions(Role::load(RoleInterface::ANONYMOUS_ID), [
      'use jsonrpc services',
      'access content',
    ]);
  }

  /**
   * Tests enabling the maintenance mode.
   */
  public function testResponseHeaders() {
    $batch_request = [
      [
        'jsonrpc' => '2.0',
        'method' => 'second.test',
        'id' => 'second',
      ],
      [
        'jsonrpc' => '2.0',
        'method' => 'first.test',
        'id' => 'first',
      ],
    ];

    $response = $this->postRpc($batch_request);
    $this->assertSame('oof', $response->getHeader('foo')[0]);
    $this->assertSame('', $response->getHeader('hello')[0]);
    $this->assertEmpty($response->getHeader('lorem'));
    $this->assertEmpty($response->getHeader('bye'));
  }

  /**
   * Ensures that a response not matching the schema produces invalid results.
   */
  public function testInvalidSchema() {
    $request = [
      'jsonrpc' => '2.0',
      'method' => 'third.test',
      'id' => 'third',
    ];
    $response = $this->postRpc($request);
    $contents = $response->getBody();
    $this->assertSame(-32603, Json::decode($contents)['error']['code']);
  }

}
