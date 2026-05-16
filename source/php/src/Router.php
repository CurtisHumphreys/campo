<?php

class Router {
    private $routes = [];

    public function get($path, $callback) {
        $this->routes['GET'][$path] = $callback;
    }

    public function post($path, $callback) {
        $this->routes['POST'][$path] = $callback;
    }

    public function options($path, $callback) {
        $this->routes['OPTIONS'][$path] = $callback;
    }

    public function dispatch() {
        $method = $_SERVER['REQUEST_METHOD'];
        $uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);

        // Strip the sub-directory base path so routes work under both
        // campoffice.nix.local/ and nix.local/apps/campoffice/
        $scriptDir = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/');
        if ($scriptDir !== '' && strpos($uri, $scriptDir) === 0) {
            $uri = substr($uri, strlen($scriptDir));
        }

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
