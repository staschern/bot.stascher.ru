<?php
declare(strict_types=1);

/**
 * Веб-точка входа. Apache DocumentRoot должен указывать на эту папку (public/).
 *
 * См. spec.md §14 и docs/INSTALL.md.
 */

use BybitBot\Core\Bootstrap;
use BybitBot\Web\AppFactory;

$root = dirname(__DIR__);
require_once $root . '/src/Core/Bootstrap.php';
require_once $root . '/vendor/autoload.php';

Bootstrap::init($root);

$app = AppFactory::create();
$app->run();
