<?php

/* Macaw updated by xp
 *
 * 修复了使用 PHP_SELF 导致 URL 重写后无法使用的问题
 * 简单增加了对控制器命名空间的支持
 * 本身增加了对 HTTP_METHOD 的过滤，但考虑到使用时并不是什么大问题，注释掉了
 * 删除了关于 halt 的代码，因为看起来并没有什么用
 *
 * TODO 2015-09-04 01:14:59
 * 增加对 HTTP_METHOD 多对一的支持
 * 增加对 RESTful 风格路由和控制器的便捷接口
 * 以上，总之就是尽量向 Laravel 靠拢的意思
*/

namespace NoahBuscher\Macaw;

/**
 * @method static Macaw get(string $route, Callable $callback)
 * @method static Macaw post(string $route, Callable $callback)
 * @method static Macaw put(string $route, Callable $callback)
 * @method static Macaw delete(string $route, Callable $callback)
 * @method static Macaw options(string $route, Callable $callback)
 * @method static Macaw head(string $route, Callable $callback)
 */
class Macaw
{

    public static $patterns = array(
        ':any' => '[^/]+',
        ':num' => '[0-9]+',
        ':all' => '.*'
    );

    public static $controller_namespace = '';

    public static $routes = array();

    //public static $access_methods = array('GET', 'POST', 'PUT', 'PATCH', 'DELETE');

    public static $methods = array();

    public static $callbacks = array();

    public static $error_callback;

    /**
     * Defines a route w/ callback and method
     */
    public static function __callstatic($method, $params) 
    {
        $method = strtoupper($method);
        //if (!in_array($method, self::$access_methods)) return ;

        // PHP_SELF = SCRIPT_NAME . PATH_INFO
        $uri = dirname($_SERVER['SCRIPT_NAME']).$params[0];
        $callback = $params[1];

        array_push(self::$routes, $uri);
        array_push(self::$methods, $method);
        array_push(self::$callbacks, $callback);
    }

    /**
     * Defines callback if route is not found
    */
    public static function error($callback)
    {
        self::$error_callback = $callback;
    }

    /**
     * Use namespace when controllers matched
     * @author xp
     * @param string $namespace
     * @return void
     */
    public static function useNamespace($namespace)
    {
        if (strlen($namespace) === 0) return;
        if ($namespace[strlen($namespace)-1] !== '\\') {
            $namespace .= '\\';
        }
        self::$controller_namespace = $namespace;
    }

    /**
     * Runs the callback for the given request
     */
    public static function dispatch()
    {
        $uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
        $method = $_SERVER['REQUEST_METHOD'];  

        $searches = array_keys(static::$patterns);
        $replaces = array_values(static::$patterns);

        $found_route = false;

        // check if route is defined without regex
        if (in_array($uri, self::$routes)) {
            $route_pos = array_keys(self::$routes, $uri);
            foreach ($route_pos as $route) {

                if (self::$methods[$route] == $method) {
                    $found_route = true;

                    // if route is not an object
                    if (!is_object(self::$callbacks[$route])){

                        // format: path\to\controller@action
                        $segments = explode('@', self::$callbacks[$route]);

                        // add global controller prefix
                        $segments[0] = self::$controller_namespace . $segments[0];

                        // instanitate controller
                        $controller = new $segments[0]();

                        // call method
                        $controller->$segments[1]();

                    } else {
                        // call closure
                        call_user_func(self::$callbacks[$route]);
                    }
                }
            }
        } else {
            // check if defined with regex
            $pos = 0;
            foreach (self::$routes as $route) {

                // Semantic replace
                if (strpos($route, ':') !== false) {
                    $route = str_replace($searches, $replaces, $route);
                }

                if (preg_match('#^' . $route . '$#', $uri, $matched)) {
                    if (self::$methods[$pos] == $method) {
                        $found_route = true;

                        array_shift($matched); // remove $matched[0] as [1] is the first parameter.

                        if (!is_object(self::$callbacks[$pos])){

                            // format: path\to\controller@action
                            $segments = explode('@', self::$callbacks[$route]);

                            // add global controller prefix
                            $segments[0] = self::$controller_namespace . $segments[0];

                            // instanitate controller
                            $controller = new $segments[0]();

                            // call method and pass any extra parameters to the method
                            $controller->$segments[1](implode(",", $matched)); 

                        } else {
                            call_user_func_array(self::$callbacks[$pos], $matched);
                        }
                        
                    }
                }

                $pos++;
            }
        }

        // run the error callback if the route was not found
        if ($found_route == false) {
            if (!self::$error_callback) {
                self::$error_callback = function() {
                    header($_SERVER['SERVER_PROTOCOL']." 404 Not Found");
                    echo '404';
                };
            }
            call_user_func(self::$error_callback);
        }
    }
}
