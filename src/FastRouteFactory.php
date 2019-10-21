<?php

declare(strict_types=1);

namespace Chiron\Router\FastRoute;

use FastRoute\DataGenerator\GroupCountBased;
use FastRoute\RouteCollector;
use FastRoute\RouteParser\Std;
use Psr\Container\ContainerInterface;
use Chiron\Router\RouterInterface;

class FastRouteFactory
{
    public function __invoke(ContainerInterface $container): RouterInterface
    {
        $collector = new RouteCollector(
            new Std(),
            new GroupCountBased()
        );

        $router = new FastRoute(
            $collector,
            function ($data) {
                return new \FastRoute\Dispatcher\GroupCountBased($data);
            }
        );

        return $router;
    }
}