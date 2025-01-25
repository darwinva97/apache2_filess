<?php

use Intervention\Image\ImageManager;

class ImageTransformer {
    private $manager;
    private $cachePath;
    private $maxCacheAge;
    private $maxCacheSize;
    
    public function __construct(
        ImageManager $manager, 
        string $cachePath, 
        int $maxCacheAge = 7 * 60 * 60 * 24, // 1 semana en segundos
        int $maxCacheSize = 1 * 1024 * 1024 * 1024 // 1GB en bytes
    ) {
        $this->manager = $manager;
        $this->cachePath = $cachePath;
        $this->maxCacheAge = $maxCacheAge;
        $this->maxCacheSize = $maxCacheSize;
    }
    
    private function sanitizeParams(array $params): array {
        return [
            'width' => isset($params['width']) ? (int)$params['width'] : null,
            'height' => isset($params['height']) ? (int)$params['height'] : null,
            'quality' => isset($params['quality']) ? min(max((int)$params['quality'], 1), 100) : 80,
            'format' => isset($params['format']) ? strtolower($params['format']) : 'webp',
            'fit' => isset($params['fit']) ? strtolower($params['fit']) : 'cover',
            'blur' => isset($params['blur']) ? (int)$params['blur'] : null
        ];
    }
    
    public function transform(string $path, array $params) {
        $params = $this->sanitizeParams($params);
        $cacheKey = $this->getCacheKey($path, $params);
        $cachePath = $this->getCachePath($cacheKey, $params['format']);
        
        if (file_exists($cachePath)) {
            // Verificar si el cache ha expirado
            if (time() - filemtime($cachePath) > $this->maxCacheAge) {
                unlink($cachePath);
            } else {
                return $cachePath;
            }
        }
        
        try {
            // Verificar espacio en caché antes de procesar
            $this->manageCacheSize();
            
            // Cargar y procesar imagen
            $image = $this->manager->read($path);
            $originalWidth = $image->width();
            $originalHeight = $image->height();
            $originalRatio = $originalWidth / $originalHeight;
            
            if ($params['width'] || $params['height']) {
                $width = $params['width'];
                $height = $params['height'];
                
                // Calcular dimensiones proporcionales si falta uno de los parámetros
                if ($width && !$height) {
                    $height = (int)($width / $originalRatio);
                } elseif ($height && !$width) {
                    $width = (int)($height * $originalRatio);
                }
                
                switch ($params['fit']) {
                    case 'contain':
                        $image->contain($width, $height);
                        break;
                    case 'fill':
                        $image->resize($width, $height);
                        break;
                    default:
                        $image->cover($width, $height);
                }
            }
            
            if ($params['blur']) {
                $image->blur($params['blur']);
            }
            
            // Crear estructura de directorios si no existe
            $cacheDir = dirname($cachePath);
            if (!is_dir($cacheDir)) {
                mkdir($cacheDir, 0775, true);
            }
            
            // Guardar imagen
            $image->save($cachePath, [
                'quality' => $params['quality'],
                'format' => $params['format']
            ]);
            
            return $cachePath;
            
        } finally {
            // Liberar lock y eliminar archivo de lock
            // flock($lock, LOCK_UN);
            // fclose($lock);
            @unlink($cachePath . '.lock');
        }
    }
    
    private function getCachePath(string $cacheKey, string $format): string {
        // Distribuir archivos en subdirectorios usando los primeros caracteres del hash
        $subdir = substr($cacheKey, 0, 2) . '/' . substr($cacheKey, 2, 2);
        return sprintf('%s/%s/%s.%s', $this->cachePath, $subdir, $cacheKey, $format);
    }
    
    private function manageCacheSize(): void {
        static $lastCheck = 0;
        
        // Verificar solo cada 5 minutos
        if (time() - $lastCheck < 300) {
            return;
        }
        
        $lastCheck = time();
        
        // Obtener tamaño total de la caché
        $totalSize = $this->getDirSize($this->cachePath);
        
        if ($totalSize > $this->maxCacheSize) {
            $this->cleanCache();
        }
    }
    
    private function cleanCache(): void {
        $files = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->cachePath)
        );
        
        $cacheFiles = [];
        foreach ($files as $file) {
            if ($file->isFile() && !$file->isLink()) {
                $cacheFiles[] = [
                    'path' => $file->getPathname(),
                    'time' => $file->getMTime(),
                    'size' => $file->getSize()
                ];
            }
        }
        
        // Ordenar por tiempo de modificación
        usort($cacheFiles, function($a, $b) {
            return $a['time'] <=> $b['time'];
        });
        
        // Eliminar archivos más antiguos hasta reducir el tamaño
        $currentSize = array_sum(array_column($cacheFiles, 'size'));
        $target = $this->maxCacheSize * 0.8; // Reducir al 80% del máximo
        
        foreach ($cacheFiles as $file) {
            if ($currentSize <= $target) {
                break;
            }
            @unlink($file['path']);
            $currentSize -= $file['size'];
        }
    }
    
    private function getDirSize(string $path): int {
        $size = 0;
        $files = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path)
        );
        
        foreach ($files as $file) {
            if ($file->isFile() && !$file->isLink()) {
                $size += $file->getSize();
            }
        }
        
        return $size;
    }
    
    public function getCacheKey(string $path, array $params): string {
        return md5($path . serialize($params));
    }
}