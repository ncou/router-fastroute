<?php

declare(strict_types=1);

namespace Chiron\Router\FastRoute;

use Chiron\Router\Traits\MiddlewareAwareInterface;
use Chiron\Router\Traits\MiddlewareAwareTrait;
use Chiron\Router\Traits\RouteCollectionInterface;
use Chiron\Router\Traits\RouteCollectionTrait;
use Chiron\Pipe\PipelineBuilder;
use Chiron\Router\RouterInterface;
use Chiron\Router\Route;
use Chiron\Router\Method;
use Chiron\Router\RouteCollector;
use Chiron\Router\RequestHandler;
use Chiron\Router\RouteCollectorInterface;
use Chiron\Router\RouteGroup;
use Chiron\Router\RouteResult;
use Chiron\Router\RouteRunner;
use FastRoute\DataGenerator;
use FastRoute\RouteParser;
use InvalidArgumentException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use RuntimeException;

//https://github.com/zendframework/zend-expressive-fastroute/blob/master/src/FastRouteRouter.php
//https://github.com/Wandu/Router/blob/master/RouteCollection.php

// TODO : il manque head et options dans la phpdoc
/**
 * Aggregate routes for the router.
 *
 * This class provides * methods for creating path+HTTP method-based routes and
 * injecting them into the router:
 *
 * - get
 * - post
 * - put
 * - patch
 * - delete
 * - any
 *
 * A general `route()` method allows specifying multiple request methods and/or
 * arbitrary request methods when creating a path-based route.
 *
 * Internally, the class performs some checks for duplicate routes when
 * attaching via one of the exposed methods, and will raise an exception when a
 * collision occurs.
 */
class FastRoute implements RouterInterface
{
    use MiddlewareAwareTrait;

    /** @var FastRoute\RouteParser */
    private $parser;

    /** @var FastRoute\DataGenerator */
    private $generator;

    private $routeCollector;

    /**
     * @var array
     */
    // TODO : regarder ici : https://github.com/ncou/router-group-middleware/blob/master/src/Router/Router.php#L25
    // TODO : regarder ici : https://github.com/ncou/php-router-group-middleware/blob/master/src/Router.php#L26
    // TODO : faire un tableau plus simple et ensuite dans le constructeur faire un array walk pour ajouter ces patterns.
    private $patternMatchers = [
        '/{(.+?):number}/'        => '{$1:[0-9]+}',
        '/{(.+?):word}/'          => '{$1:[a-zA-Z]+}',
        '/{(.+?):alphanum_dash}/' => '{$1:[a-zA-Z0-9-_]+}',
        '/{(.+?):slug}/'          => '{$1:[a-z0-9-]+}',
        '/{(.+?):uuid}/'          => '{$1:[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}}',
    ];

    /*

    ':any' => '[^/]+',
    ':all' => '.*'


    '*'  => '.+?',
    '**' => '.++',


    */

    /*
    //https://github.com/codeigniter4/CodeIgniter4/blob/develop/system/Router/RouteCollection.php#L122

    protected $placeholders = [
        'any'      => '.*',
        'segment'  => '[^/]+',
        'alphanum' => '[a-zA-Z0-9]+',
        'num'      => '[0-9]+',
        'alpha'    => '[a-zA-Z]+',
        'hash'     => '[^/]+',
    ];


    */

    /**
     * @var string Can be used to ignore leading part of the Request URL (if main file lives in subdirectory of host)
     */
    private $basePath = '';

    /**
     * Constructor.
     *
     * @param \FastRoute\RouteParser   $parser
     * @param \FastRoute\DataGenerator $generator
     */
    // TODO : créer un constructeur qui prendra en paramétre un routeCollector, ca évitera de faire un appel à setRouteCollector() !!!!
    // TODO : virer le DataGenerator qui est en paramétre et faire un new directement dans le constructeur.
    public function __construct(DataGenerator $generator = null)
    {
        $this->parser = new RouteParser\Std();
        // build parent route collector
        $this->generator = ($generator) ?? new DataGenerator\GroupCountBased();

        $this->routeCollector = new RouteCollector();

        // TODO utiliser ce bout de code et faire un tableau de pattern dans la classe de ce type ['slug' => 'xxxx', 'number' => 'yyyy']
/*
        array_walk($this->patternMatchers, function ($value, $key) {
            $this->addPatternMatcher($key, $value);
        });*/
    }

    /**
     * Add a convenient pattern matcher to the internal array for use with all routes.
     *
     * @param string $alias
     * @param string $regex
     *
     * @return self
     */
    public function addPatternMatcher(string $alias, string $regex): self
    {
        $pattern = '/{(.+?):' . $alias . '}/';
        $regex = '{$1:' . $regex . '}';

        $this->patternMatchers[$pattern] = $regex;

        return $this;
    }

    /**
     * Set the base path.
     * Useful if you are running your application from a subdirectory.
     */
    public function setBasePath(string $basePath): void
    {
        $this->basePath = rtrim($basePath, '/');
        //$this->basePath = $basePath;
        //$this->basePath = '/' . ltrim($basePath, '/');
    }

    /**
     * Get the router base path.
     * Useful if you are running your application from a subdirectory.
     */
    public function getBasePath(): string
    {
        return $this->basePath;
    }

    public function getRouteCollector(): RouteCollectorInterface
    {
        return $this->routeCollector;
    }

    public function setRouteCollector(RouteCollectorInterface $collector): void
    {
        $this->routeCollector = $collector;
    }


    /**
     * {@inheritdoc}
     */
    /*
    public function dispatch_OLD(ServerRequestInterface $request) : ResponseInterface
    {
        if (is_null($this->getStrategy())) {
            $this->setStrategy(new HtmlStrategy);
        }

        $this->prepareRoutes($request);

        return (new Dispatcher($this->getData()))
            ->middlewares($this->getMiddlewareStack())
            ->setStrategy($this->getStrategy())
            ->dispatchRequest($request)
        ;
    }*/

    public function match(ServerRequestInterface $request): RouteResult
    {
        $this->injectRoutes($request);

        // process routes
        //$dispatcher = new Dispatcher($this->routeCollector->getRoutes(), $this->generator->getData());
        // TODO : on doit aussi pouvoir récupérer le ->getData() depuis la classe RouteCollector de FastRoute
        $dispatcher = new Dispatcher($this->generator->getData());

        return $dispatcher->dispatchRequest($request);
    }

    /**
     * Prepare all routes, build name index and filter out none matching
     * routes before being passed off to the parser.
     *
     * @param \Psr\Http\Message\ServerRequestInterface $request
     */
    private function injectRoutes(ServerRequestInterface $request): void
    {
        foreach ($this->routeCollector->getRoutes() as $route) {
            // check for scheme condition
            if (! is_null($route->getScheme()) && $route->getScheme() !== $request->getUri()->getScheme()) {
                continue;
            }
            // check for domain condition
            if (! is_null($route->getHost()) && $route->getHost() !== $request->getUri()->getHost()) {
                continue;
            }
            // check for port condition
            if (! is_null($route->getPort()) && $route->getPort() !== $request->getUri()->getPort()) {
                continue;
            }

            $routePath = $this->replaceAssertPatterns($route->getRequirements(), $route->getPath());
            $routePath = $this->replaceWordPatterns($routePath);

            $this->injectRoute($route, $route->getAllowedMethods(), $this->basePath . $routePath);
        }
    }

    /**
     * Add or replace the requirement pattern inside the route path.
     *
     * @param array  $requirements
     * @param string $path
     *
     * @return string
     */
    private function replaceAssertPatterns(array $requirements, string $path): string
    {
        $patternAssert = [];
        foreach ($requirements as $attribute => $pattern) {
            // it will replace {attribute_name} to {attribute_name:$pattern}, work event if there is alreay a patter {attribute_name:pattern_to_remove} to {attribute_name:$pattern}
            // the second regex group (starting with the char ':') will be discarded.
            $patternAssert['/{(' . $attribute . ')(\:.*)?}/'] = '{$1:' . $pattern . '}';
            //$patternAssert['/{(' . $attribute . ')}/'] = '{$1:' . $pattern . '}'; // TODO : réfléchir si on utilise cette regex, dans ce cas seulement les propriétés qui n'ont pas déjà un pattern de défini (c'est à dire une partie avec ':pattern')
        }

        return preg_replace(array_keys($patternAssert), array_values($patternAssert), $path);
    }

    /**
     * Replace word patterns with regex in route path.
     *
     * @param string $path
     *
     * @return string
     */
    private function replaceWordPatterns(string $path): string
    {
        return preg_replace(array_keys($this->patternMatchers), array_values($this->patternMatchers), $path);
    }

    /**
     * Adds a route to the collection.
     *
     * The syntax used in the $route string depends on the used route parser.
     *
     * @param string   $routeId
     * @param string[] $httpMethod
     * @param string   $routePath
     */
    private function injectRoute(Route $route, array $httpMethod, string $routePath): void
    {
        $routeDatas = $this->parser->parse($routePath);
        foreach ($httpMethod as $method) {
            foreach ($routeDatas as $routeData) {
                // TODO : réactiver le try catch si on souhaite pouvoir gérer les doublons de routes.
                //try {
                $this->generator->addRoute($method, $routeData, $route);
                //} catch (\Throwable $e) {
                //}
            }
        }
    }

    /**
     * Build the path for a named route including the base path.
     *
     * @param string $routeName     Route name
     * @param array  $substitutions Named argument replacement data
     * @param array  $queryParams   Optional query string parameters
     *
     * @throws InvalidArgumentException If named route does not exist
     * @throws InvalidArgumentException If required data not provided
     *
     * @return string
     */
    public function urlFor(string $routeName, array $substitutions = [], array $queryParams = []): string
    {
        $url = $this->relativeUrlFor($routeName, $substitutions, $queryParams);

        if ($basePath = $this->getBasePath()) {
            $url = $basePath . $url;
        }

        return $url;
    }

    /**
     * Build the path for a named route excluding the base path.
     *
     * @param string $routeName     Route name
     * @param array  $substitutions Named argument replacement data
     * @param array  $queryParams   Optional query string parameters
     *
     * @throws InvalidArgumentException If named route does not exist
     * @throws InvalidArgumentException If required data not provided
     *
     * @return string
     */
    public function relativeUrlFor(string $routeName, array $substitutions = [], array $queryParams = []): string
    {
        $route = $this->routeCollector->getNamedRoute($routeName);

        return RouteUrlGenerator::generate($route->getPath(), $substitutions, $queryParams);
    }

    public function handle(ServerRequestInterface $request) : ResponseInterface
    {
        //$builder = new PipelineBuilder($this);
        $handler = new RequestHandler($this->getMiddlewareStack(), new RouteRunner($this));

        return $handler->handle($request);
    }

    /*
     * {@inheritdoc}
     */
    /*
    public function lookupRoute(string $identifier): Route
    {
        if (!isset($this->routes[$identifier])) {
            throw new InvalidArgumentException('Route not found for identifier: ' . $identifier);
        }
        return $this->routes[$identifier];
    }*/

    /*
     * Determine if the route is duplicated in the current list.
     *
     * Checks if a route with the same name or path exists already in the list;
     * if so, and it responds to any of the $methods indicated, raises
     * a DuplicateRouteException indicating a duplicate route.
     *
     * @throws Exception\DuplicateRouteException on duplicate route detection.
     */
    //https://github.com/zendframework/zend-expressive-router/blob/master/src/RouteCollector.php#L149
    /*
    private function checkForDuplicateRoute(string $path, array $methods = null) : void
    {
        if (null === $methods) {
            $methods = Route::HTTP_METHOD_ANY;
        }
        $matches = array_filter($this->routes, function (Route $route) use ($path, $methods) {
            if ($path !== $route->getPath()) {
                return false;
            }
            if ($methods === Route::HTTP_METHOD_ANY) {
                return true;
            }
            return array_reduce($methods, function ($carry, $method) use ($route) {
                return ($carry || $route->allowsMethod($method));
            }, false);
        });
        if (! empty($matches)) {
            $match = reset($matches);
            $allowedMethods = $match->getAllowedMethods() ?: ['(any)'];
            $name = $match->getName();
            throw new Exception\DuplicateRouteException(sprintf(
                'Duplicate route detected; path "%s" answering to methods [%s]%s',
                $match->getPath(),
                implode(',', $allowedMethods),
                $name ? sprintf(', with name "%s"', $name) : ''
            ));
        }
    }*/
}
