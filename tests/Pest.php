<?php
/**
 * Pest configuration: Brain Monkey lifecycle and shared stubs.
 *
 * @package JevCommentTriage
 */

declare( strict_types=1 );

use Brain\Monkey;
use Brain\Monkey\Functions;

uses()
	->beforeEach(
		function (): void {
			Monkey\setUp();
			Functions\when( 'apply_filters' )->returnArg( 2 );
			Functions\when( 'do_action' )->justReturn( true );
		}
	)
	->afterEach(
		function (): void {
			// Count Brain Monkey/Mockery expectations as assertions.
			$container = \Mockery::getContainer();
			if ( $container ) {
				$this->addToAssertionCount( $container->mockery_getExpectationCount() );
			}
			Monkey\tearDown();
		}
	)
	->in( 'Unit' );
