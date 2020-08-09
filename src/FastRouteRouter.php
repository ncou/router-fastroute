<?php

declare(strict_types=1);

namespace Chiron\Router\FastRoute;

use Chiron\Router\Traits\MiddlewareAwareInterface;
use Chiron\Router\Traits\MiddlewareAwareTrait;
use Chiron\Router\Traits\RouteCollectionInterface;
use Chiron\Router\Traits\RouteCollectionTrait;
use Chiron\Router\Exception\RouterException;
use Chiron\Router\Exception\RouteNotFoundException;
use Chiron\Pipe\PipelineBuilder;
use Chiron\Router\RouterInterface;
use Chiron\Router\Route;
use Chiron\Router\Method;
use Chiron\Router\RouteCollectorInterface;
use Chiron\Router\RouteGroup;
use Chiron\Router\MatchingResult;
use Chiron\Router\RoutingHandler;
use FastRoute\DataGenerator\GroupCountBased as RouteGenerator;
use FastRoute\RouteParser\Std as RouteParser;
use FastRoute\Dispatcher as DispatcherInterface;
use FastRoute\Dispatcher\GroupCountBased as RouteDispatcher;
use InvalidArgumentException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use RuntimeException;
use Chiron\Router\UrlGeneratorInterface;
use Psr\Http\Message\UriInterface;

//https://github.com/zendframework/zend-expressive-fastroute/blob/master/src/FastRouteRouter.php
//https://github.com/Wandu/Router/blob/master/RouteCollection.php

// TODO : il manque head et options dans la phpdoc, et le check sur la collision n'est plus d'actualité !!!!
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



// TODO : boucler sur le tableau des routes et mettre dans un tableau le nom de la route et utiliser la méthode ci dessous pour détecter si il y a des doublons de noms pour les routes (il faudra appeller cette méthode à deux endroits, lors de injectRoutes() et lors de getNamedRoute() [car l'injection n'a peut etre pas encore eu lieux!!!!]) :
/*
function array_has_dupes($array): bool {
   return count($array) !== count(array_unique($array));
}*/



// TODO : vérifier comment ca se passe si on ajoute plusieurs fois une route avec le même nom !!!!
final class FastRouteRouter implements RouterInterface, UrlGeneratorInterface
{
    /** @var FastRoute\RouteParser */
    private $routeParser;

    /** @var FastRoute\DataGenerator */
    private $routeGenerator;

     /** @var UrlGenerator */
    private $urlGenerator;

    /** @var \Chiron\Router\Route[] */
    private $routes = [];

    /** @var bool */
    // TODO : renommer la variable en "injected"
    private $isInjected = false;

    /**
     * @var array
     */
    // TODO : regarder ici : https://github.com/ncou/router-group-middleware/blob/master/src/Router/Router.php#L25
    // TODO : regarder ici : https://github.com/ncou/php-router-group-middleware/blob/master/src/Router.php#L26
    // TODO : faire un tableau plus simple et ensuite dans le constructeur faire un array walk pour ajouter ces patterns.
    // TODO : on devrait pas utiliser plutot cet regex pour les UUID : '[0-9a-fA-F]{8}\-[0-9a-fA-F]{4}\-[0-9a-fA-F]{4}\-[0-9a-fA-F]{4}\-[0-9a-fA-F]{12}'
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

    private $placeholders = [
        'any'      => '.*',
        'segment'  => '[^/]+',
        'alphanum' => '[a-zA-Z0-9]+',
        'num'      => '[0-9]+',
        'alpha'    => '[a-zA-Z]+',
        'hash'     => '[^/]+',
    ];


    */


    /**
     * Constructor.
     *
     * @param \FastRoute\RouteParser   $parser
     * @param \FastRoute\DataGenerator $generator
     */
    // TODO : créer un constructeur qui prendra en paramétre un routeCollector, ca évitera de faire un appel à setRouteCollector() !!!!
    // TODO : virer le DataGenerator qui est en paramétre et faire un new directement dans le constructeur.
    public function __construct()
    {
        $this->routeParser = new RouteParser();
        // build parent route collector
        $this->routeGenerator = new RouteGenerator();

        $this->urlGenerator = new UrlGenerator($this->routeParser);

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
     * Add a Route to the collection, and return the route for chaining calls.
     *
     * @param Route $route
     */
    public function addRoute(Route $route): Route
    {
        // TODO : Lever une logica exception si on essaye d'ajouter une route, alors que que le booleen $this->isInjected est à true. Car cela n'a pas de sens d'ajouter une route aprés avoir appellé la méthode ->match de cette classe !!!!
        $this->routes[] = $route;

        return $route;
    }

    /**
     * Get a named route.
     *
     * @param string $name Route name
     *
     * @throws RouteNotFoundException If named route does not exist
     *
     * @return \Chiron\Router\Route
     */
    // TODO : il faudrait rajouter un contrôle sur les doublons de "name" ??? car cela peut poser soucis (notamment si on souhaite générer une url pour une route nommée) !!!!
    // TODO : renommer la méthode en getRoute() ????
    // TODO : passer cette méthode en private et la retirer de l'interface du router car elle n'a pas grand importance !!!! ???? et dans ce cas il faudrait aussi supprimer la classe RouteNotFoundException du package pour utiliser l'exception générique RouterException.
    public function getNamedRoute(string $name): Route
    {
        foreach ($this->getRoutes() as $route) {
            if ($route->getName() === $name) {
                return $route;
            }
        }

        throw new RouteNotFoundException($name);
    }

    /**
     * Get route objects.
     *
     * @return Route[]
     */
    public function getRoutes(): array
    {
        return $this->routes;
    }


    public function match(ServerRequestInterface $request): MatchingResult
    {
        // prepare routes
        $this->injectRoutes($request);

        // process routes
        $dispatcher = $this->getDispatcher();

        $httpMethod = $request->getMethod();
        $uri = $request->getUri()->getPath(); //$uri = '/' . ltrim($request->getUri()->getPath(), '/');

        $result = $dispatcher->dispatch($httpMethod, rawurldecode($uri));

        //die(var_dump($result));

        return $result[0] !== DispatcherInterface::FOUND
            ? $this->marshalFailedRoute($result)
            : $this->marshalMatchedRoute($result);
    }

    private function getDispatcher(): DispatcherInterface
    {
        return new RouteDispatcher($this->routeGenerator->getData());
    }

    /**
     * Marshal a routing failure result.
     *
     * If the failure was due to the HTTP method, passes the allowed HTTP
     * methods to the factory.
     */
    private function marshalFailedRoute(array $result): MatchingResult
    {
        if ($result[0] === DispatcherInterface::METHOD_NOT_ALLOWED) {
            return MatchingResult::fromRouteFailure($result[1]);
        }

        return MatchingResult::fromRouteFailure(Method::ANY);
    }

    /**
     * Marshals a route result based on the results of matching and the current HTTP method.
     */
    private function marshalMatchedRoute(array $result): MatchingResult
    {
        $route = $result[1];
        $params = $result[2];

        return MatchingResult::fromRoute($route, $params);
    }

    /**
     * Prepare all routes, build name index and filter out none matching
     * routes before being passed off to the parser.
     *
     * @param \Psr\Http\Message\ServerRequestInterface $request
     */
    // TODO : passer uniquement en paramétre un UriInterface plutot que toute la Request !!!!
    // TODO : il faudrait faire une vérification si il n'y a pas des routes en double avec le même "name", car cela va nous poser des soucis lorsqu'on va essayer de la récupérer en utilisant la méthode ->getNamedRoute() !!!!
    private function injectRoutes(ServerRequestInterface $request): void
    {
        // only inject routes once.
        if ($this->isInjected) {
            return;
        }

        foreach ($this->routes as $route) {
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

            $this->injectRoute($route, $route->getAllowedMethods(), $routePath);
        }

        $this->isInjected = true;
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
     *
     * @throws RouterException If two routes match the same url+method, or if the route is shadowed by a previous route.
     */
    private function injectRoute(Route $route, array $httpMethod, string $routePath): void
    {
        $routeDatas = $this->routeParser->parse($routePath);
        foreach ($httpMethod as $method) {
            foreach ($routeDatas as $routeData) {
                try {
                    $this->routeGenerator->addRoute($method, $routeData, $route);
                } catch (\FastRoute\BadRouteException $e) {
                    throw new RouterException($e->getMessage());
                }
            }
        }
    }


    public function absoluteUrlFor(UriInterface $uri, string $routeName, array $substitutions = [], array $queryParams = []): string
    {
        $path = $this->relativeUrlFor($routeName, $substitutions, $queryParams);

        $scheme = $uri->getScheme();
        $authority = $uri->getAuthority();
        $protocol = ($scheme ? $scheme . ':' : '') . ($authority ? '//' . $authority : '');

        return $protocol . $path;
    }

    /**
     * Build the path for a named route excluding the base url.
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
        $route = $this->getNamedRoute($routeName);

        return $this->urlGenerator->generate($route->getPath(), $substitutions, $queryParams);
    }

}
