<?php

namespace LumenPress\Routing;

use Illuminate\Support\Arr;
use LumenPress\Nimble\Models\Post;
use LumenPress\Nimble\Models\User;
use LumenPress\Nimble\Models\Taxonomy;
use LumenPress\Routing\Exceptions\RouteConditionException;

class Router
{
    /**
     * The application instance.
     *
     * @var \Laravel\Lumen\Application
     */
    public $app;

    /**
     * The route group attribute stack.
     *
     * @var array
     */
    protected $groupStack = [];

    /**
     * All of the routes waiting to be registered.
     *
     * @var array
     */
    protected $routes = [];

    /**
     * Route conditions.
     *
     * @var array
     */
    protected $routeConditions = [];

    /**
     * All of the named routes and URI pairs.
     *
     * @var array
     */
    public $namedRoutes = [];

    /**
     * Router constructor.
     *
     * @param  \Laravel\Lumen\Application  $app
     */
    public function __construct($app)
    {
        $this->app = $app;
        $this->registerCondition([
            'front'     => 'is_front_page',
            'template'  => 'is_page_template',
            'page'      => [$this, 'pageRouteCondition'],
            'single'    => [$this, 'singleRouteCondition'],
            'archive'   => [$this, 'archiveRouteCondition'],
        ]);
    }

    /**
     * Register a set of routes with a set of shared attributes.
     *
     * @param  array  $attributes
     * @param  \Closure|string  $routes
     * @return void
     */
    public function group(array $attributes, $routes)
    {
        if (isset($attributes['middleware']) && is_string($attributes['middleware'])) {
            $attributes['middleware'] = explode('|', $attributes['middleware']);
        }

        $this->updateGroupStack($attributes);

        // Once we have updated the group stack, we'll load the provided routes and
        // merge in the group's attributes when the routes are created. After we
        // have created the routes, we will pop the attributes off the stack.
        $this->loadRoutes($routes);

        array_pop($this->groupStack);
    }

    /**
     * Load the provided routes.
     *
     * @param  \Closure|string  $routes
     * @return void
     */
    protected function loadRoutes($routes)
    {
        if ($routes instanceof \Closure) {
            $routes($this);
        } else {
            $router = $this;

            require $routes;
        }
    }

    /**
     * Update the group stack with the given attributes.
     *
     * @param  array  $attributes
     * @return void
     */
    protected function updateGroupStack(array $attributes)
    {
        if (! empty($this->groupStack)) {
            $attributes = $this->mergeWithLastGroup($attributes);
        }

        $this->groupStack[] = $attributes;
    }

    /**
     * Merge the given group attributes.
     *
     * @param  array  $new
     * @param  array  $old
     * @return array
     */
    public function mergeGroup($new, $old)
    {
        $new['namespace'] = static::formatUsesPrefix($new, $old);

        $new['prefix'] = static::formatGroupPrefix($new, $old);

        if (isset($new['domain'])) {
            unset($old['domain']);
        }

        if (isset($old['as'])) {
            $new['as'] = $old['as'].(isset($new['as']) ? '.'.$new['as'] : '');
        }

        if (isset($old['suffix']) && ! isset($new['suffix'])) {
            $new['suffix'] = $old['suffix'];
        }

        return array_merge_recursive(Arr::except($old, ['namespace', 'prefix', 'as', 'suffix']), $new);
    }

    /**
     * Merge the given group attributes with the last added group.
     *
     * @param  array $new
     * @return array
     */
    protected function mergeWithLastGroup($new)
    {
        return $this->mergeGroup($new, end($this->groupStack));
    }

    /**
     * Format the uses prefix for the new group attributes.
     *
     * @param  array  $new
     * @param  array  $old
     * @return string|null
     */
    protected static function formatUsesPrefix($new, $old)
    {
        if (isset($new['namespace'])) {
            return isset($old['namespace']) && strpos($new['namespace'], '\\') !== 0
                ? trim($old['namespace'], '\\').'\\'.trim($new['namespace'], '\\')
                : trim($new['namespace'], '\\');
        }

        return isset($old['namespace']) ? $old['namespace'] : null;
    }

    /**
     * Format the prefix for the new group attributes.
     *
     * @param  array  $new
     * @param  array  $old
     * @return string|null
     */
    protected static function formatGroupPrefix($new, $old)
    {
        $oldPrefix = isset($old['prefix']) ? $old['prefix'] : null;

        if (isset($new['prefix'])) {
            return trim($oldPrefix, '/').'/'.trim($new['prefix'], '/');
        }

        return $oldPrefix;
    }

    /**
     * Add a route to the collection.
     *
     * @param  array|string  $method
     * @param  string  $uri
     * @param  mixed  $action
     * @return void
     */
    public function addRoute($method, $condition, $action)
    {
        $action = $this->parseAction($action);

        $condition = $this->parseCondition($condition);

        $attributes = null;

        if ($this->hasGroupStack()) {
            $attributes = $this->mergeWithLastGroup([]);
        }

        if (isset($attributes) && is_array($attributes)) {
            $action = $this->mergeGroupAttributes($action, $attributes);
        }

        // if (isset($action['as'])) {
        //     $this->namedRoutes[$action['as']] = $uri;
        // }

        if (is_array($method)) {
            foreach ($method as $verb) {
                $this->routes[$verb][] = ['condition' => $condition, 'action' => $action];
            }
        } else {
            $this->routes[$method][] = ['condition' => $condition, 'action' => $action];
        }
    }

    protected function parseCondition($condition)
    {
        if (is_string($condition)) {
            return [[
                'callback' => $this->parseConditionKey($condition),
                'parameters' => [[]],
            ]];
        }

        if (is_array($condition)) {
            $newCondition = [];
            foreach ($condition as $key => $value) {
                $newCondition[] = [
                    'callback' => $this->parseConditionKey($key),
                    'parameters' => $this->parseConditionParameters($value),
                ];
            }

            return $newCondition;
        }

        return [];
    }

    protected function parseConditionKey($key)
    {
        if (isset($this->routeConditions[$key])) {
            $condition = $this->routeConditions[$key];
        } else {
            $condition = "is_{$key}";
        }

        if (! is_callable($condition)) {
            throw new RouteConditionException("$key condition does not exists.", 1);
        }

        return $condition;
    }

    protected function parseConditionParameters($parameters)
    {
        if (! is_array($parameters)) {
            return [[$parameters]];
        }

        return array_map(function ($parameter) {
            return is_array($parameter) ? $parameter : [$parameter];
        }, $parameters);
    }

    /**
     * Parse the action into an array format.
     *
     * @param  mixed  $action
     * @return array
     */
    protected function parseAction($action)
    {
        if (is_string($action)) {
            return ['uses' => $action];
        } elseif (! is_array($action)) {
            return [$action];
        }

        if (isset($action['middleware']) && is_string($action['middleware'])) {
            $action['middleware'] = explode('|', $action['middleware']);
        }

        return $action;
    }

    /**
     * Determine if the router currently has a group stack.
     *
     * @return bool
     */
    public function hasGroupStack()
    {
        return ! empty($this->groupStack);
    }

    /**
     * Merge the group attributes into the action.
     *
     * @param  array  $action
     * @param  array  $attributes The group attributes
     * @return array
     */
    protected function mergeGroupAttributes(array $action, array $attributes)
    {
        $namespace = isset($attributes['namespace']) ? $attributes['namespace'] : null;
        $middleware = isset($attributes['middleware']) ? $attributes['middleware'] : null;
        $as = isset($attributes['as']) ? $attributes['as'] : null;

        return $this->mergeNamespaceGroup(
            $this->mergeMiddlewareGroup(
                $this->mergeAsGroup($action, $as),
                $middleware),
            $namespace
        );
    }

    /**
     * Merge the namespace group into the action.
     *
     * @param  array  $action
     * @param  string $namespace
     * @return array
     */
    protected function mergeNamespaceGroup(array $action, $namespace = null)
    {
        if (isset($namespace) && isset($action['uses'])) {
            $action['uses'] = $namespace.'\\'.$action['uses'];
        }

        return $action;
    }

    /**
     * Merge the middleware group into the action.
     *
     * @param  array  $action
     * @param  array  $middleware
     * @return array
     */
    protected function mergeMiddlewareGroup(array $action, $middleware = null)
    {
        if (isset($middleware)) {
            if (isset($action['middleware'])) {
                $action['middleware'] = array_merge($middleware, $action['middleware']);
            } else {
                $action['middleware'] = $middleware;
            }
        }

        return $action;
    }

    /**
     * Merge the as group into the action.
     *
     * @param  array $action
     * @param  string $as
     * @return array
     */
    protected function mergeAsGroup(array $action, $as = null)
    {
        if (isset($as) && ! empty($as)) {
            if (isset($action['as'])) {
                $action['as'] = $as.'.'.$action['as'];
            } else {
                $action['as'] = $as;
            }
        }

        return $action;
    }

    public function is($uri, $action)
    {
        $this->addRoute(['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS'], $uri, $action);

        return $this;
    }

    /**
     * Register a route with the application.
     *
     * @param  string  $uri
     * @param  mixed  $action
     * @return $this
     */
    public function get($uri, $action)
    {
        $this->addRoute('GET', $uri, $action);

        return $this;
    }

    /**
     * Register a route with the application.
     *
     * @param  string  $uri
     * @param  mixed  $action
     * @return $this
     */
    public function post($uri, $action)
    {
        $this->addRoute('POST', $uri, $action);

        return $this;
    }

    /**
     * Register a route with the application.
     *
     * @param  string  $uri
     * @param  mixed  $action
     * @return $this
     */
    public function put($uri, $action)
    {
        $this->addRoute('PUT', $uri, $action);

        return $this;
    }

    /**
     * Register a route with the application.
     *
     * @param  string  $uri
     * @param  mixed  $action
     * @return $this
     */
    public function patch($uri, $action)
    {
        $this->addRoute('PATCH', $uri, $action);

        return $this;
    }

    /**
     * Register a route with the application.
     *
     * @param  string  $uri
     * @param  mixed  $action
     * @return $this
     */
    public function delete($uri, $action)
    {
        $this->addRoute('DELETE', $uri, $action);

        return $this;
    }

    /**
     * Register a route with the application.
     *
     * @param  string  $uri
     * @param  mixed  $action
     * @return $this
     */
    public function options($uri, $action)
    {
        $this->addRoute('OPTIONS', $uri, $action);

        return $this;
    }

    /**
     * Get the raw routes for the application.
     *
     * @return array
     */
    public function getRoutes()
    {
        return $this->routes;
    }

    public function dispatch($httpMethod, $uri)
    {
        if (! isset($this->routes[$httpMethod])) {
            return [0];
        }

        $routes = $this->routes[$httpMethod];

        foreach ($routes as $route) {
            foreach ($route['condition'] as $condition) {
                foreach ($condition['parameters'] as $parameters) {
                    if (call_user_func_array($condition['callback'], $parameters)) {
                        return [1, $route['action'], $this->getQueriedVars()];
                    }
                }
            }
        }

        return [0];
    }

    public function getQueriedVars()
    {
        $obj = get_queried_object();

        if ($obj instanceof \WP_Post) {
            $class = Post::getClassNameByType($obj->post_type, Post::class);
            $post = $class::find($obj->ID);

            return ['post' => $post];
        }

        if ($obj instanceof \WP_Term) {
            $class = Taxonomy::getClassNameByType($obj->taxonomy, Taxonomy::class);
            $term = $class::find($obj->term_id);

            return ['term' => $term];
        }

        if ($obj instanceof \WP_User) {
            $user = User::find($obj->ID);

            return ['user' => $user];
        }

        if ($obj) {
            return [$obj];
        }

        return [];
    }

    protected function archiveRouteCondition($postType = '')
    {
        if (empty($postType)) {
            return is_archive();
        }

        return is_post_type_archive($postType);
    }

    protected function pageRouteCondition($page = '')
    {
        if (! is_page($page)) {
            return false;
        }

        if (is_string($page) && get_queried_object()->post_parent !== 0) {
            return stripos($page, '/') !== false;
        }

        return true;
    }

    protected function singleRouteCondition($type = '', $slug = '')
    {
        if (is_numeric($type)) {
            return is_single($type);
        }

        if (! is_singular($type)) {
            return false;
        }

        if (empty($slug)) {
            return true;
        }

        return is_single($slug);
    }

    public function registerCondition($key, callable $callback = null)
    {
        if (is_array($key)) {
            $this->routeConditions = array_merge($this->routeConditions, $key);
        } elseif (is_string($key) && ! empty($key)) {
            $this->routeConditions[$key] = $callback;
        }

        return $this;
    }
}
