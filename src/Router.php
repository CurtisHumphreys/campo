<?php

class Router {
    private $routes = [];

    public function get($path, $callback) {
        $this->routes['GET'][$path] = $callback;
    }

    public function post($path, $callback) {
        $this->routes['POST'][$path] = $callback;
    }

    public function dispatch() {
        $method = $_SERVER['REQUEST_METHOD'];
        $uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);

        /*
         * The application previously assumed a base path of "/campo" when hosted in
         * a subdirectory. Since the app is now hosted at the domain root, remove
         * any hard-coded base path handling. If you choose to host this app
         * under a subfolder in the future, you can reintroduce a `$basePath`
         * variable here and strip it off of `$uri` if present.
         */

        // Normalize URI by removing trailing slash (except for root)
        $uri = rtrim($uri, '/');
        if ($uri === '') {
            $uri = '/';
        }

        // Check for exact match
        if (isset($this->routes[$method][$uri])) {
            call_user_func($this->routes[$method][$uri]);
            return;
        }

        // 404 fallback
        http_response_code(404);
        echo json_encode(['error' => 'Not Found']);
    }
}
