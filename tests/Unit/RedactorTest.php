<?php
/**
 * Tests for redaction and size caps.
 *
 * @package AryanX\Axle\AgentGovernance
 */

namespace AryanX\Axle\AgentGovernance\Tests\Unit;

use AryanX\Axle\AgentGovernance\Redactor;
use Brain\Monkey\Filters;

/**
 * Covers Redactor.
 */
final class RedactorTest extends AgentTestCase {

	public function test_secret_keys_are_masked_at_any_depth(): void {
		$out = Redactor::redact(
			array(
				'title'    => 'Hello',
				'password' => 'hunter2',
				'meta'     => array(
					'api_key'       => 'k',
					'client_secret' => 's',
					'apiKey'        => 'camel',
					'X-Auth-Token'  => 't',
				),
				'user'     => (object) array( 'user_pass' => 'p' ),
				'author'   => 3,
				'passport' => 'AB123',
				'empty'    => array( 'token' => '' ),
			)
		);

		$this->assertSame( 'Hello', $out['title'] );
		$this->assertSame( Redactor::MASK, $out['password'] );
		$this->assertSame( Redactor::MASK, $out['meta']['api_key'] );
		$this->assertSame( Redactor::MASK, $out['meta']['client_secret'] );
		$this->assertSame( Redactor::MASK, $out['meta']['apiKey'] );
		$this->assertSame( Redactor::MASK, $out['meta']['X-Auth-Token'] );
		$this->assertSame( Redactor::MASK, $out['user']['user_pass'] );
		$this->assertSame( 3, $out['author'], '"author" is not "auth".' );
		$this->assertSame( 'AB123', $out['passport'], '"passport" is not "pass".' );
		$this->assertSame( '', $out['empty']['token'], 'Nothing to hide in an empty value.' );
	}

	public function test_scalars_pass_through(): void {
		$this->assertSame( 'x', Redactor::redact( 'x' ) );
		$this->assertNull( Redactor::redact( null ) );
	}

	public function test_the_redact_keys_filter_adds_keys(): void {
		Filters\expectApplied( 'agent_governance_redact_keys' )->once()->andReturnUsing(
			static function ( array $keys ) {
				$keys[] = 'Phone-Number';
				return $keys;
			}
		);

		$out = Redactor::redact( array( 'billing_phone_number' => '555' ) );

		$this->assertSame( Redactor::MASK, $out['billing_phone_number'] );
	}

	public function test_encode_redacts_by_default(): void {
		$this->assertSame( '{"pwd":"[redacted]"}', Redactor::encode( array( 'pwd' => 'x' ), 'input' ) );
		$this->assertSame( '{"pwd":"x"}', Redactor::encode( array( 'pwd' => 'x' ), 'input', false ) );
	}

	public function test_encode_cuts_big_payloads_to_a_marked_preview(): void {
		Filters\expectApplied( 'agent_governance_payload_limit' )->once()->with( 16384, 'input' )->andReturn( 600 );

		$json = Redactor::encode( array( 'content' => str_repeat( 'a', 5000 ) ), 'input' );
		$data = json_decode( $json, true );

		$this->assertTrue( $data['_truncated'] );
		$this->assertSame( 5014, $data['_bytes'] );
		$this->assertLessThanOrEqual( 600, strlen( $data['_preview'] ) );
	}
}
