<?php

function deleteDir($dirPath) {
    // Verificar si el directorio existe
    if (!is_dir($dirPath)) {
        return false;
    }

    // Obtener todos los elementos dentro del directorio
    $files = array_diff(scandir($dirPath), array('.', '..'));

    foreach ($files as $file) {
        $filePath = $dirPath . DIRECTORY_SEPARATOR . $file;

        // Si es un archivo, eliminarlo
        if (is_file($filePath)) {
            unlink($filePath);
        } 
        // Si es un directorio, llamar a la función recursiva
        elseif (is_dir($filePath)) {
            deleteDir($filePath);
            rmdir($filePath); // Eliminar el directorio vacío después de eliminar su contenido
        }
    }

    return rmdir($dirPath);
}