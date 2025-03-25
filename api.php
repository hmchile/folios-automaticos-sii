<?php
// api.php - Punto de entrada para la API REST

require_once __DIR__ . '/vendor/autoload.php';
require_once __DIR__ . '/config.php';

use SiiService\FoliosService;

// Habilitar CORS para peticiones desde diferentes dominios
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST, GET, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization");
header("Content-Type: application/json; charset=UTF-8");

// Si es una petición OPTIONS, terminar aquí (para CORS preflight)
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

// Verificar método de la petición
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode([
        'success' => false,
        'message' => 'Método no permitido. Use POST.'
    ]);
    exit;
}

// Obtener el cuerpo de la petición
$requestBody = file_get_contents('php://input');
$data = json_decode($requestBody, true);

// Verificar si la decodificación fue exitosa
if (json_last_error() !== JSON_ERROR_NONE) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => 'Cuerpo de petición inválido. Se espera JSON.'
    ]);
    exit;
}

// Verificar parámetros requeridos
$requiredParams = [
    'folioInicial',
    'folioFinal',
    'tipoDte',
    'rutCert',
    'rutEmpresa',
    'certificadoPem',
    'certificadoPassword'
];

foreach ($requiredParams as $param) {
    if (!isset($data[$param]) || empty($data[$param])) {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'message' => "Parámetro requerido '$param' no proporcionado o vacío."
        ]);
        exit;
    }
}

// Crear un directorio temporal para almacenar el certificado
$tempDir = sys_get_temp_dir() . '/sii_temp_' . uniqid();
if (!is_dir($tempDir)) {
    mkdir($tempDir, 0700, true);
}

// Guardar el certificado PEM recibido
$certContent = base64_decode($data['certificadoPem']);
if ($certContent === false) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => 'Contenido del certificado PEM inválido. Debe estar en formato Base64.'
    ]);
    rmdir($tempDir);
    exit;
}

$certPath = $tempDir . '/certificado.pem';
file_put_contents($certPath, $certContent);

// Preparar la configuración
$serviceConfig = [
    'pem' => $certPath,
    'pass' => $data['certificadoPassword'],
    'rutCert' => $data['rutCert'],
    'rutEmpresa' => $data['rutEmpresa'],
    'servidor' => $data['servidor'] ?? $config['servidor'] ?? 'maullin',
    'foliosPath' => $config['foliosPath'] ?? __DIR__ . '/storage/folios/',
    'enableLogging' => $data['enableLogging'] ?? $config['enableLogging'] ?? true,
    'logPath' => $config['logPath'] ?? __DIR__ . '/storage/logs/',
    'enableHtmlDebug' => $data['enableHtmlDebug'] ?? $config['enableHtmlDebug'] ?? true,
    'debugPath' => $config['debugPath'] ?? __DIR__ . '/storage/debug/',
];

// Instanciar el servicio con la configuración
try {
    $foliosService = new FoliosService($serviceConfig);

    // Solicitar folios
    $result = $foliosService->getFolios($data);

    // Limpiar directorio temporal
    unlink($certPath);
    rmdir($tempDir);

    if (!$result['success']) {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'message' => $result['message'],
            'code' => $result['code'] ?? 'unknown',
            'error' => $result['error'] ?? null
        ]);
        exit;
    }

    // Verificar si se quiere el archivo o solo la respuesta JSON
    if (isset($data['returnXml']) && $data['returnXml'] === true) {
        header('Content-Type: application/xml');
        header('Content-Disposition: attachment; filename="folios_' . $data['tipoDte'] . '_' . $data['folioInicial'] . '-' . $data['folioFinal'] . '.xml"');
        echo $result['content'];
    } else {
        echo json_encode([
            'success' => true,
            'message' => 'Folios obtenidos correctamente',
            'filename' => $result['filename'],
            'xml' => base64_encode($result['content'])  // Incluir el XML en la respuesta como Base64
        ]);
    }
} catch (\Throwable $th) {
    // Asegurar limpieza en caso de error
    if (file_exists($certPath)) {
        unlink($certPath);
    }
    if (is_dir($tempDir)) {
        rmdir($tempDir);
    }

    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Error interno del servidor',
        'error' => $th->getMessage()
    ]);
}
