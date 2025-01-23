<?php

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/utils.php';
require_once __DIR__ . '/transform.php';

use Intervention\Image\Drivers\Gd\Driver;
use Slim\Factory\AppFactory;
use Intervention\Image\ImageManager;

$manager = new ImageManager(Driver::class);
$transformer = new ImageTransformer(
    $manager, 
    CACHE_PATH,
    7 * 60 * 60 * 24, // 1 semana en segundos
    2 * 1024 * 1024 * 1024 // 2GB en bytes
);

$app = AppFactory::create();

$app->addErrorMiddleware(true, true, true);

// crea una carpeta
$app->post('/create-folder', function ($request, $response) {
    $data = $request->getParsedBody();

    if (!$data) {
        $rawData = file_get_contents("php://input");
        $data = json_decode($rawData, true);
    }

    $folderPath = $data['path'];

    // Verificar que la ruta no esté vacía
    if (empty($folderPath)) {
        $response->getBody()->write("No se proporcionó una ruta válida." . $folderPath);
        return $response->withStatus(400);
    }

    $folderPath = STORAGE_PATH . $folderPath;

    // Verificar si el directorio ya existe
    if (is_dir($folderPath)) {
        $response->getBody()->write("El directorio ya existe.");
        return $response->withStatus(400);
    }

    // Crear el directorio de manera recursiva
    if (mkdir($folderPath, 0777, true)) {
        $response->getBody()->write("La carpeta fue creada correctamente.");
        return $response->withStatus(200);
    } else {
        $error = error_get_last();
        $response->getBody()->write("Hubo un problema al crear la carpeta." . $folderPath . " Error: " . $error['message']);
        return $response->withStatus(500);
    }
});

// sube muchos archivos
$app->post('/upload', function ($request, $response) {
    $uploadedFiles = $request->getUploadedFiles();
    $uploadedFilesArray = $uploadedFiles['files'];
    $path = $request->getQueryParams()['path'] ?? '/default';
    $path = STORAGE_PATH . $path;
    // Verificar si hay archivos en la solicitud

    if (empty($uploadedFilesArray)) {
        $response->getBody()->write("No se ha subido ningún archivo.");
        return $response->withStatus(400);
    }

    if (!is_dir($path)) {
        mkdir($path, 0755, true);
    }

    foreach ($uploadedFilesArray as $uploadedFile) {
        if ($uploadedFile->getError() === UPLOAD_ERR_OK) {
            $filename = $uploadedFile->getClientFilename();
            $uploadedFile->moveTo($path . DIRECTORY_SEPARATOR . $filename);
        } else {
            $response->getBody()->write("Error al subir el archivo: " . $uploadedFile->getClientFilename());
            return $response->withStatus(500);
        }
    }

    $response->getBody()->write(json_encode(['message' => 'Files uploaded successfully']));
    return $response->withHeader('Content-Type', 'application/json');
});

// elimina muchos archivos/carpetas
$app->delete('/delete', function ($request, $response) {
    $data = $request->getParsedBody();

    if (!$data) {
        $rawData = file_get_contents("php://input");
        $data = json_decode($rawData, true);
    }

    $target = $data['paths'];

    // Verificar que la ruta no esté vacía
    if (!isset($data['paths']) || !is_array($data['paths'])) {
        $response->getBody()->write("No se proporcionó una ruta válida.");
        return $response->withStatus(400);
    }
    $paths = $data['paths'];
    $results = [];
    $errors = [];

    foreach ($paths as $target) {
        $target = STORAGE_PATH . $target;

        try {
            $result = null;

            if (is_file($target)) {
                global $result;
                $result = unlink($target);
                $message = "File deleted successfully.";
            } elseif (is_dir($target)) {
                global $result;
                $result = deleteDir($target);
                $message = "Folder deleted successfully.";
            } else {
                global $result;
                $result = false;
                $response = $response->withStatus(404);
                $message = "Target not found.";
            }
            global $results;
            $results[] = [
                'target' => $target,
                'message' => $message,
                'result' => $result
            ];
        } catch (Exception $e) {
            global $errors;
            $errors[] = [
                'target' => $target,
                'error' => $e->getMessage()
            ];
        }
    }
    $responseData = [
        'deleted' => $results,
        'errors' => $errors
    ];
    $response->getBody()->write(json_encode($responseData));
    return $response->withHeader('Content-Type', 'application/json');
});

// renombra un archivo o carpeta
$app->put('/rename', function ($request, $response) {
    $data = $request->getParsedBody();

    if (!$data) {
        $rawData = file_get_contents("php://input");
        $data = json_decode($rawData, true);
    }

    if (!isset($data['old_name']) || !isset($data['new_name'])) {
        $response->getBody()->write("No se proporcionó una ruta válida.");
        return $response->withStatus(400);
    }

    $oldPath = STORAGE_PATH . $data['old_name'];
    $newPath = STORAGE_PATH . $data['new_name'];
    $result = null;

    if (file_exists($oldPath)) {
        global $result, $message;
        $result = rename($oldPath, $newPath);
    } else {
        global $result, $message;
        $result = false;
        $response = $response->withStatus(404);
        $message = "Item not found: " . $oldPath;
    }

    $message = $message ?? $result ? "Renamed successfully." : "Error renaming item.";

    $response->getBody()->write(json_encode(['message' => $message, 'result' => $result]));
    return $response->withHeader('Content-Type', 'application/json');
});

// lista todos los archivos y carpetas de un path
$app->get('/list', function ($request, $response) {
    $queryParams = $request->getQueryParams();
    $path = STORAGE_PATH . ($queryParams['path'] ?? '/');

    if (!is_dir($path)) {
        $response = $response->withStatus(404);
        $response->getBody()->write(json_encode(['error' => 'Directory not found']));
        return $response->withHeader('Content-Type', 'application/json');
    }

    $items = scandir($path);
    $result = array_values(array_filter($items, function ($item) {
        return $item !== '.' && $item !== '..';
    }));

    $data = array_map(function ($item) use ($path) {
        return [
            'name' => $item,
            'type' => is_dir("{$path}/{$item}") ? 'folder' : 'file',
        ];
    }, $result);

    $response->getBody()->write(json_encode($data));
    return $response->withHeader('Content-Type', 'application/json');
});

// mueve archivos y/o carpetas adentro de un path
$app->post('/move', function ($request, $response) {
    $data = $request->getParsedBody();

    if (!$data) {
        $rawData = file_get_contents("php://input");
        $data = json_decode($rawData, true);
    }

    // Verificar que el objeto de entrada tiene la clave 'destination' y 'files'
    if (!isset($data['destination']) || !isset($data['sources']) || !is_array($data['sources'])) {
        $response->getBody()->write(json_encode(['message' => 'Se esperaba un objeto con las claves "destination" y "sources".']));
        return $response->withStatus(400)->withHeader('Content-Type', 'application/json');
    }

    $destination = STORAGE_PATH . $data['destination'];  // Ruta destino común
    $sources = ($data['sources']);  // Archivos y carpetas a mover
    $results = [];

    // Verificar si el destino es válido
    if (!is_dir($destination)) {
        $result = mkdir($destination, 0777, true);
        if (!$result) {
            $results[] = ['message' => 'El destino no es una carpeta válida.', 'destination' => $destination, 'result' => $result];
            // return $response->withStatus(400)->withHeader('Content-Type', 'application/json')->write(json_encode($results));
            $response->getBody()->write(json_encode($results));
            return $response->withStatus(400)->withHeader('Content-Type', 'application/json');
        }
    }

    // Iterar sobre los archivos y carpetas y moverlos
    foreach ($sources as $source) {
        $sourcePath = STORAGE_PATH . $source;
        $destinationPath = $destination . "/" . basename($source);

        if (file_exists($sourcePath)) {
            $result_rename = rename($sourcePath, $destinationPath);

            if ($result_rename) {
                $results[] = ['message' => "Movido correctamente: $source", 'source' => $sourcePath, 'destination' => $destinationPath];
            } else {
                $error = error_get_last();
                $results[] = [
                    'message' => "Error al mover: $source",
                    'source' => $sourcePath,
                    'destination' => $destinationPath,
                    'error' => $error['message'],
                ];
            }
        } else {
            $results[] = ['message' => "El archivo o carpeta no existe: $source", 'source' => $sourcePath];
        }
    }

    // Responder con los resultados
    $response->getBody()->write(json_encode($results));
    return $response->withHeader('Content-Type', 'application/json');
});

// Endpoint para transformar imágenes
$app->get('/image/{path:.*}', function ($request, $response, $args) use ($transformer) {
    $path = STORAGE_PATH . '/' . $args['path'];
    
    if (!file_exists($path)) {
        return $response
            ->withStatus(404)
            ->withHeader('Content-Type', 'application/json')
            ->write(json_encode(['error' => 'Image not found']));
    }
    
    $params = $request->getQueryParams();
    $validParams = array_intersect_key($params, array_flip([
        'width',
        'height',
        'format',
        'quality',
        'fit',
        'blur'
    ]));
    
    try {
        $transformedPath = $transformer->transform($path, $validParams);
        $stream = fopen($transformedPath, 'r');
        
        return $response
            ->withHeader('Content-Type', mime_content_type($transformedPath))
            ->withHeader('Cache-Control', 'public, max-age=31536000')
            ->withBody(new \Slim\Psr7\Stream($stream));
    } catch (Exception $e) {
        var_dump($e->getMessage());
        return $response
            ->withStatus(500)
            ->withHeader('Content-Type', 'application/json')
            ->write(json_encode(['error' => $e->getMessage()]));
    }
});

// Endpoint para obtener metadatos de imagen
$app->get('/metadata/{path:.*}', function ($request, $response, $args) {
    $path = STORAGE_PATH . '/' . $args['path'];
    
    if (!file_exists($path)) {
        return $response
            ->withStatus(404)
            ->withHeader('Content-Type', 'application/json')
            ->write(json_encode(['error' => 'Image not found']));
    }
    
    $imageSize = getimagesize($path);

    $metadata = [
        'width' => $imageSize[0],
        'height' => $imageSize[1],
        'mime' => $imageSize['mime'],
        'size' => filesize($path),
        'modified' => filemtime($path)
    ];
    
    $response->getBody()->write(json_encode($metadata));
    return $response->withHeader('Content-Type', 'application/json');
});

// 404
$app->get('/{path:.*}', function ($request, $response, $args) {
    $response->getBody()->write('<h1>404 Not Found</h1>');
    return $response->withStatus(404);
});

$app->run();
