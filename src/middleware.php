<?php

function validateToken($request, $handler) {
    // Try to get token from multiple possible sources
    $token = $request->getHeaderLine('Authorization');
    
    // If not found in header, try to get from apache headers
    if (empty($token) && function_exists('apache_request_headers')) {
        $headers = apache_request_headers();
        $token = isset($headers['Authorization']) ? $headers['Authorization'] : '';
        if (empty($token)) {
            $token = isset($headers['authorization']) ? $headers['authorization'] : '';
        }
    }
    
    // If still not found, try to get from $_SERVER
    if (empty($token)) {
        $token = isset($_SERVER['HTTP_AUTHORIZATION']) ? $_SERVER['HTTP_AUTHORIZATION'] : '';
    }
    
    // Clean up the token by removing 'Bearer ' if present
    $token = str_replace('Bearer ', '', $token);
    
    // Load environment variables if not already loaded
    if (!isset($_ENV['APP_TOKEN'])) {
        $dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/..');
        $dotenv->load();
    }

    $appToken = $_ENV['APP_TOKEN'];
    
    // Debug information
    error_log('Received raw token: ' . $token);
    error_log('Expected token: ' . $appToken);
    
    if (empty($token) || $token !== $appToken) {
        $response = new \Slim\Psr7\Response();
        $response->getBody()->write(json_encode([
            'error' => 'Unauthorized: Invalid token',
            'debug' => [
                'received_token' => $token,
                'token_length' => strlen($token),
                'expected_token_length' => strlen($appToken)
            ]
        ]));
        return $response
            ->withStatus(401)
            ->withHeader('Content-Type', 'application/json');
    }

    return $handler->handle($request);
}
