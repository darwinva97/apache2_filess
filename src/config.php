<?php

// Configuración inicial
$ROOT_PATH = __DIR__ ."/../";

$STORAGE_PATH = realpath($ROOT_PATH . "/storage");
$CACHE_PATH = realpath($ROOT_PATH . "/cache");

define("STORAGE_PATH", $STORAGE_PATH);
define('CACHE_PATH', $CACHE_PATH);