<?php
/**
 * PHPUnit bootstrap: the Composer autoloader plus the WordPress constants and
 * classes the plugin touches. WordPress functions are mocked per test with
 * Brain Monkey, so WordPress itself is not loaded.
 *
 * @package AryanX\AgentGovernance
 */

require dirname( __DIR__ ) . '/vendor/autoload.php';
require __DIR__ . '/Stubs/wordpress.php';

defined( 'ABSPATH' ) || define( 'ABSPATH', __DIR__ . '/' );
