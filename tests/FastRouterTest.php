<?php

declare(strict_types=1);

namespace Chiron\Tests\Router\FastRoute;

use Nyholm\Psr7\ServerRequest;
use Nyholm\Psr7\Uri;
use Chiron\Router\Route;
use Chiron\Router\FastRoute\FastRoute as Router;
use PHPUnit\Framework\TestCase;

class FastRouterTest extends TestCase
{
    /**
     * @expectedException \FastRoute\BadRouteException
     * @expectedExceptionMessage Cannot use the same placeholder "test" twice
     */
    public function testDuplicateVariableNameError()
    {
        $request = new ServerRequest('GET', new Uri('/foo'));

        $router = new Router();

        $router->getRouteCollector()->get('/foo/{test}/{test:\d+}', 'handler0');

        $matchingResult = $router->match($request);
    }

    /**
     * @expectedException \FastRoute\BadRouteException
     * @expectedExceptionMessage Cannot register two routes matching "/user/([^/]+)" for method "GET"
     */
    public function testDuplicateVariableRoute()
    {
        $request = new ServerRequest('GET', new Uri('/foo'));

        $router = new Router();

        $router->getRouteCollector()->get('/user/{id}', 'handler0'); // oops, forgot \d+ restriction ;)
        $router->getRouteCollector()->get('/user/{name}', 'handler1');

        $matchingResult = $router->match($request);
    }

    /**
     * @expectedException \FastRoute\BadRouteException
     * @expectedExceptionMessage Cannot register two routes matching "/user" for method "GET"
     */
    public function testDuplicateStaticRoute()
    {
        $request = new ServerRequest('GET', new Uri('/foo'));

        $router = new Router();

        $router->getRouteCollector()->get('/user', 'handler0');
        $router->getRouteCollector()->get('/user', 'handler1');

        $matchingResult = $router->match($request);
    }

    /**
     * @codingStandardsIgnoreStart
     * @expectedException \FastRoute\BadRouteException
     * @expectedExceptionMessage Static route "/user/nikic" is shadowed by previously defined variable route "/user/([^/]+)" for method "GET"
     * @codingStandardsIgnoreEnd
     */
    public function testShadowedStaticRoute()
    {
        $request = new ServerRequest('GET', new Uri('/foo'));

        $router = new Router();

        $router->getRouteCollector()->get('/user/{name}', 'handler0');
        $router->getRouteCollector()->get('/user/nikic', 'handler1');

        $matchingResult = $router->match($request);
    }

    /**
     * @expectedException \FastRoute\BadRouteException
     * @expectedExceptionMessage Regex "(en|de)" for parameter "lang" contains a capturing group
     */
    public function testCapturing()
    {
        $request = new ServerRequest('GET', new Uri('/foo'));

        $router = new Router();

        $router->getRouteCollector()->get('/{lang:(en|de)}', 'handler0');

        $matchingResult = $router->match($request);
    }
}
