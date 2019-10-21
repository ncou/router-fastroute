<?php

declare(strict_types=1);

namespace Chiron\Router;

use FastRoute\Dispatcher\GroupCountBased as GroupCountBasedDispatcher;
use Psr\Http\Message\ServerRequestInterface;

class Dispatcher extends GroupCountBasedDispatcher
{
    //private $routes;

    // mixed[] $data
    /*
    public function __construct(array $routes, array $data)
    {
        $this->routes = $routes;
        parent::__construct($data);
    }*/

    /**
     * Dispatch the current route.
     *
     * @see   https://github.com/nikic/FastRoute/blob/master/src/Dispatcher.php
     *
     * @param \Psr\Http\Message\ServerRequestInterface $request
     *
     * @return \Psr\Http\Message\ResponseInterface
     */
    public function dispatchRequest(ServerRequestInterface $request): RouteResult
    {
        $httpMethod = $request->getMethod();
        $uri = rawurldecode($request->getUri()->getPath()); //$uri = '/' . ltrim($request->getUri()->getPath(), '/');

        $result = $this->dispatch($httpMethod, $uri);

        return $result[0] !== self::FOUND
            ? $this->marshalFailedRoute($result)
            : $this->marshalMatchedRoute($result);
    }

    /**
     * Marshal a routing failure result.
     *
     * If the failure was due to the HTTP method, passes the allowed HTTP
     * methods to the factory.
     */
    private function marshalFailedRoute(array $result): RouteResult
    {
        if ($result[0] === self::METHOD_NOT_ALLOWED) {
            return RouteResult::fromRouteFailure($result[1]);
        }

        return RouteResult::fromRouteFailure(RouteResult::HTTP_METHOD_ANY);
    }

    /**
     * Marshals a route result based on the results of matching and the current HTTP method.
     */
    // TODO : attention le paramétre $method n'est pas utilisé !!!! => https://github.com/zendframework/zend-expressive-fastroute/blob/master/src/FastRouteRouter.php#L397
    private function marshalMatchedRoute(array $result): RouteResult
    {
        //$identifier = $result[1];
        //$route = $this->routes[$identifier];

        $route = $result[1];
        $params = $result[2];

        return RouteResult::fromRoute($route, $params);
    }
}
