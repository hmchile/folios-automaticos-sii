<?php
// Configuración global de la aplicación

$config = [
    // Configuración del servidor SII
    'servidor' => getenv('SII_SERVIDOR') ?: 'maullin', // Maullin (pruebas) o palena (producción)

    // Configuración de directorios
    'foliosPath' => getenv('FOLIOS_PATH') ?: __DIR__ . '/storage/folios/',
    'logPath' => getenv('LOG_PATH') ?: __DIR__ . '/storage/logs/',
    'debugPath' => getenv('DEBUG_PATH') ?: __DIR__ . '/storage/debug/',

    // Configuración de logging
    'enableLogging' => filter_var(getenv('ENABLE_LOGGING') ?: 'true', FILTER_VALIDATE_BOOLEAN),
    'enableHtmlDebug' => filter_var(getenv('ENABLE_HTML_DEBUG') ?: 'true', FILTER_VALIDATE_BOOLEAN),
];
