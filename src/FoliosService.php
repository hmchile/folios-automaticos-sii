<?php

namespace SiiService;

use GuzzleHttp\Client;
use GuzzleHttp\Cookie\CookieJar;

class FoliosService
{
    private $dteConfig;
    private $rutCert;
    private $rutEmpresa;
    private $nombreCert;
    private $servidor;
    private $foliosPath;
    private $logPath;
    private $enableLogging;
    private $enableHtmlDebug;
    private $debugPath;
    private $sessionId;

    public function __construct(array $config)
    {
        $this->dteConfig = [
            'pem' => $config['pem'] ?? null,
            'pass' => $config['pass'] ?? null
        ];
        $this->rutCert = $config['rutCert'] ?? null;
        $this->rutEmpresa = $config['rutEmpresa'] ?? null;
        $this->servidor = $config['servidor'] ?? "maullin";
        $this->foliosPath = $config['foliosPath'] ?? __DIR__ . '/../storage/folios/';
        $this->logPath = $config['logPath'] ?? __DIR__ . '/../storage/logs/';
        $this->enableLogging = $config['enableLogging'] ?? true;
        $this->enableHtmlDebug = $config['enableHtmlDebug'] ?? true;
        $this->debugPath = $config['debugPath'] ?? __DIR__ . '/../storage/debug/';

        // Asegurar que existan los directorios necesarios
        if ($this->enableLogging && !file_exists($this->logPath)) {
            mkdir($this->logPath, 0755, true);
        }

        if ($this->enableHtmlDebug && !file_exists($this->debugPath)) {
            mkdir($this->debugPath, 0755, true);
        }

        // Intentar extraer el nombre del certificado si no fue proporcionado
        if (empty($config['nombreCert']) && !empty($this->dteConfig['pem'])) {
            $this->nombreCert = $this->extractCertificateName($this->dteConfig['pem'], $this->dteConfig['pass']);
            if ($this->enableLogging) {
                $this->log("Nombre del certificado extraído automáticamente: " . $this->nombreCert);
            }
        } else {
            $this->nombreCert = $config['nombreCert'] ?? null;
        }

        // Crear un ID único para esta sesión (para los archivos de depuración)
        $this->sessionId = date('Ymd_His') . '_' . substr(md5(uniqid()), 0, 6);
        if ($this->enableLogging) {
            $this->log("ID de sesión de debug: " . $this->sessionId);
        }
    }

    /**
     * Extrae el nombre del certificado desde el archivo PEM
     * 
     * @param string $pemFile Ruta al archivo PEM
     * @param string $password Contraseña del certificado
     * @return string|null Nombre del certificado o null si no se pudo extraer
     */
    private function extractCertificateName($pemFile, $password)
    {
        try {
            if (!file_exists($pemFile)) {
                $this->log("ERROR: El archivo de certificado no existe: $pemFile");
                return null;
            }

            $certData = file_get_contents($pemFile);
            if (!$certData) {
                $this->log("ERROR: No se pudo leer el archivo de certificado");
                return null;
            }

            $cert = openssl_x509_read($certData);
            if (!$cert) {
                // Intentar con la clave privada
                $pkeyid = openssl_pkey_get_private($certData, $password);
                if (!$pkeyid) {
                    $this->log("ERROR: No se pudo cargar el certificado: " . openssl_error_string());
                    return null;
                }
                $certInfo = openssl_pkey_get_details($pkeyid);
                if (!isset($certInfo['key'])) {
                    $this->log("ERROR: No se pudo extraer información del certificado");
                    openssl_free_key($pkeyid);
                    return null;
                }
                openssl_free_key($pkeyid);
                return $this->extractNameFromCertInfo($certInfo);
            }

            // Obtener la información del certificado
            $certInfo = openssl_x509_parse($cert);
            if (!$certInfo) {
                $this->log("ERROR: No se pudo analizar el certificado: " . openssl_error_string());
                if (PHP_VERSION_ID < 80100) {
                    openssl_x509_free($cert);
                }
                return null;
            }

            if (PHP_VERSION_ID < 80100) {
                openssl_x509_free($cert);
            }
            return $this->extractNameFromCertInfo($certInfo);
        } catch (\Throwable $th) {
            $this->log("ERROR al extraer el nombre del certificado: " . $th->getMessage());
            return null;
        }
    }


    /**
     * Extrae el nombre del certificado desde la información analizada
     * 
     * @param array $certInfo Información del certificado
     * @return string|null Nombre extraído o null
     */
    private function extractNameFromCertInfo($certInfo)
    {
        // Buscar en subject
        if (isset($certInfo['subject'])) {
            // Intentar extraer CN (Common Name)
            if (isset($certInfo['subject']['CN'])) {
                return $certInfo['subject']['CN'];
            }

            // Intentar extraer nombre completo
            if (isset($certInfo['subject']['name'])) {
                return $certInfo['subject']['name'];
            }

            // Intentar extraer de otros campos comunes
            foreach (['CN', 'commonName', 'O', 'organizationName', 'OU', 'organizationalUnitName'] as $field) {
                if (isset($certInfo['subject'][$field])) {
                    return $certInfo['subject'][$field];
                }
            }
        }

        // Buscar en issuer como alternativa
        if (isset($certInfo['issuer'])) {
            foreach (['CN', 'commonName', 'O', 'organizationName'] as $field) {
                if (isset($certInfo['issuer'][$field])) {
                    return $certInfo['issuer'][$field];
                }
            }
        }

        return null;
    }

    /**
     * Registra información en el archivo de log
     * 
     * @param string $message Mensaje a registrar
     * @param array|string $data Datos adicionales
     * @return void
     */
    private function log($message, $data = null)
    {
        if (!$this->enableLogging) {
            return;
        }

        $logFile = $this->logPath . 'log.txt';
        $timestamp = date('Y-m-d H:i:s');
        $logMessage = "[$timestamp] $message";

        if ($data) {
            if (is_array($data) || is_object($data)) {
                $logMessage .= " - " . json_encode($data, JSON_UNESCAPED_UNICODE);
            } else {
                $logMessage .= " - $data";
            }
        }

        file_put_contents($logFile, $logMessage . PHP_EOL, FILE_APPEND);
    }

    /**
     * Guarda el contenido HTML de una respuesta para depuración
     * 
     * @param string $step Nombre del paso (para el nombre del archivo)
     * @param string $html Contenido HTML a guardar
     * @param array $requestInfo Información adicional sobre la solicitud
     * @return string Ruta al archivo guardado
     */
    private function saveDebugHtml($step, $html, $requestInfo = [])
    {
        if (!$this->enableHtmlDebug) {
            return null;
        }

        $filename = sprintf(
            "%s/%s_%s.html",
            $this->debugPath,
            $this->sessionId,
            $step
        );

        // Agregar información de la solicitud al inicio del HTML
        $debugInfo = "<!--\n";
        $debugInfo .= "Fecha: " . date('Y-m-d H:i:s') . "\n";
        $debugInfo .= "Paso: " . $step . "\n";

        if (!empty($requestInfo)) {
            $debugInfo .= "Información de la solicitud:\n";
            foreach ($requestInfo as $key => $value) {
                if (is_array($value) || is_object($value)) {
                    $debugInfo .= "$key: " . json_encode($value, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n";
                } else {
                    $debugInfo .= "$key: $value\n";
                }
            }
        }

        $debugInfo .= "-->\n";

        // Guardar HTML con información de depuración
        file_put_contents($filename, $debugInfo . $html);

        $this->log("HTML de depuración guardado", ["paso" => $step, "archivo" => $filename]);

        return $filename;
    }

    /**
     * Obtiene folios desde el SII
     * 
     * @param array $params Arreglo con folioInicial, folioFinal y tipoDte
     * @return string|array Contenido del archivo XML o error
     */
    public function getFolios(array $params)
    {
        $this->log("Iniciando proceso de obtención de folios", $params);

        // Validar parámetros
        if (!isset($params['folioInicial']) || !isset($params['folioFinal']) || !isset($params['tipoDte'])) {
            $this->log("Error: Parámetros incompletos");
            return [
                'success' => false,
                'message' => 'Parámetros incompletos. Se requiere folioInicial, folioFinal y tipoDte.'
            ];
        }

        $cookies = new CookieJar();

        $headers = [
            'content-type' => 'application/json',
            'Accept' => 'application/json, text/plain, */*',
            'Accept-Encoding' => 'gzip, deflate, br',
        ];

        $credentials = [$this->dteConfig['pem'], $this->dteConfig['pass']];
        $this->log("Configuración de credenciales realizada");

        try {
            // Iniciar sesión en SII
            $this->log("Intentando iniciar sesión en SII");
            $loginResult = $this->login($cookies, $credentials);
            if (!$loginResult['success']) {
                $this->log("Error en login", $loginResult);
                return $loginResult;
            }
            $this->log("Login exitoso");

            // Obtener rutEmpresa y dvEmpresa
            [$rutEmpresa, $dvEmpresa] = explode(
                '-',
                str_replace('.', '', $this->rutEmpresa),
            );
            $this->log("RUT empresa: $rutEmpresa-$dvEmpresa");

            // Solicitar folios
            $this->log("Solicitando folios");
            $foliosSolicitud = $this->solicitarFolios($cookies, $headers, $rutEmpresa, $dvEmpresa);
            if (!$foliosSolicitud['success']) {
                $this->log("Error en solicitud de folios", $foliosSolicitud);
                $this->logout($cookies, $headers);
                return $foliosSolicitud;
            }
            $this->log("Solicitud de folios exitosa");

            // Confirmar folios
            $this->log("Confirmando folios", ["folioInicial" => $params['folioInicial'], "folioFinal" => $params['folioFinal'], "tipoDte" => $params['tipoDte']]);
            $foliosConfirmacion = $this->confirmarFolios($cookies, $headers, $rutEmpresa, $dvEmpresa, $params);
            if (!$foliosConfirmacion['success']) {
                $this->log("Error en confirmación de folios", $foliosConfirmacion);
                $this->logout($cookies, $headers);
                return $foliosConfirmacion;
            }
            $this->log("Confirmación de folios exitosa");

            // Generar folios
            $this->log("Generando folios");
            $foliosGeneracion = $this->generarFolios($cookies, $headers, $rutEmpresa, $dvEmpresa, $params);
            if (!$foliosGeneracion['success']) {
                $this->log("Error en generación de folios", $foliosGeneracion);
                $this->logout($cookies, $headers);
                return $foliosGeneracion;
            }
            $this->log("Generación de folios exitosa");

            // Generar archivo de folios
            $this->log("Generando archivo de folios");
            $foliosArchivo = $this->generarArchivoFolios($cookies, $headers, $rutEmpresa, $dvEmpresa, $params);
            if (!$foliosArchivo['success']) {
                $this->log("Error en generación de archivo de folios", $foliosArchivo);
                $this->logout($cookies, $headers);
                return $foliosArchivo;
            }
            $this->log("Generación de archivo de folios exitosa");

            // Almacenar archivo con nombre descriptivo
            $content = $foliosArchivo['content'];
            if (!file_exists($this->foliosPath)) {
                mkdir($this->foliosPath, 0755, true);
                $this->log("Creado directorio para folios: " . $this->foliosPath);
            }

            // Crear un nombre de archivo más descriptivo
            $timestamp = date('Ymd_His');
            $rutFormateado = str_replace(['.', '-'], ['', '_'], $this->rutEmpresa);
            $filename = sprintf(
                "%s/CAF_%s_TIPO%s_DESDE%s_HASTA%s_%s.xml",
                $this->foliosPath,
                $rutFormateado,
                $params['tipoDte'],
                $params['folioInicial'],
                $params['folioFinal'],
                $timestamp
            );

            file_put_contents($filename, $content);
            $this->log("Archivo de folios guardado en: " . $filename);

            // Cerrar sesión
            $this->log("Cerrando sesión en SII");
            $this->logout($cookies, $headers);
            $this->log("Sesión cerrada");

            $this->log("Proceso finalizado exitosamente");
            return [
                'success' => true,
                'content' => $content,
                'filename' => $filename
            ];
        } catch (\Throwable $th) {
            $this->log("Error no controlado: " . $th->getMessage(), [
                'file' => $th->getFile(),
                'line' => $th->getLine(),
                'trace' => $th->getTraceAsString()
            ]);
            $this->logout($cookies, $headers);
            return [
                'success' => false,
                'message' => 'Error al solicitar folios',
                'error' => $th->getMessage()
            ];
        }
    }

    /**
     * Inicia sesión en el SII
     */
    private function login(CookieJar $cookies, array $credentials)
    {
        $this->log("Preparando request de login");
        $client = new Client();
        $response = $client->request(
            'GET',
            'https://herculesr.sii.cl/cgi_AUT2000/CAutInicio.cgi?http://www.sii.cl',
            [
                'cert' => $credentials,
                'query' => [
                    'rutcntr' => $this->rutCert,
                    'rut' => explode('-', $this->rutCert)[0],
                    'referencia' => 'https://www.sii.cl',
                    'dv' => explode('-', $this->rutCert)[1],
                ],
                'cookies' => $cookies,
            ],
        );

        $responseCode = $response->getStatusCode();
        $this->log("Respuesta de login con código: " . $responseCode);

        $responseBody = (string)$response->getBody();
        $this->saveDebugHtml("1_login", $responseBody, [
            "URL" => 'https://herculesr.sii.cl/cgi_AUT2000/CAutInicio.cgi?http://www.sii.cl',
            "Método" => "GET",
            "Código de respuesta" => $responseCode,
            "RUT" => $this->rutCert
        ]);

        $this->log("Analizando respuesta de login");
        $parsed = $this->parseResponse($responseBody);

        if ($responseCode != 200 || $parsed['success'] === false) {
            $this->log("Login fallido", $parsed);
            return [
                'success' => false,
                'message' => $parsed['message'] ?? 'Error al intentar loguearse',
                'code' => 'login'
            ];
        }

        $this->log("Login exitoso");
        return [
            'success' => true,
            'message' => 'Login exitoso'
        ];
    }

    /**
     * Solicita folios al SII
     */
    private function solicitarFolios(CookieJar $cookies, array $headers, string $rutEmpresa, string $dvEmpresa)
    {
        $url = '/cvc_cgi/dte/of_solicita_folios';
        $this->log("Solicitando folios al SII", ["url" => $url]);

        $query = [
            'RUT_EMP' => $rutEmpresa,
            'DV_EMP' => $dvEmpresa,
            'ACEPTAR' => 'Continuar',
        ];
        $this->log("Parámetros de solicitud", $query);

        $client = new Client([
            'headers' => $headers,
            'base_uri' => 'https://' . $this->servidor . '.sii.cl',
            'cookies' => $cookies,
            'defaults' => [
                'exceptions' => false,
                'allow_redirects' => false,
            ],
            'query' => $query,
        ]);

        $this->log("Ejecutando request de solicitud de folios");
        $response = $client->request('POST', $url, [
            'form_params' => $query,
        ]);

        $responseCode = $response->getStatusCode();
        $this->log("Respuesta de solicitud de folios con código: " . $responseCode);

        $responseBody = (string)$response->getBody();
        $this->saveDebugHtml("2_solicitar_folios", $responseBody, [
            "URL" => 'https://' . $this->servidor . '.sii.cl' . $url,
            "Método" => "POST",
            "Código de respuesta" => $responseCode,
            "Parámetros" => $query
        ]);

        $this->log("Analizando respuesta de solicitud de folios");
        $parsed = $this->parseResponse($responseBody);

        if ($responseCode != 200 || $parsed['success'] === false) {
            $this->log("Solicitud de folios fallida", $parsed);
            return [
                'success' => false,
                'message' => $parsed['message'] ?? 'Error al solicitar folios',
                'code' => 'of_solicita_folios'
            ];
        }

        $this->log("Solicitud de folios exitosa");
        return [
            'success' => true,
            'message' => 'Solicitud de folios exitosa'
        ];
    }

    /**
     * Confirma folios solicitados
     */
    private function confirmarFolios(CookieJar $cookies, array $headers, string $rutEmpresa, string $dvEmpresa, array $params)
    {
        $url = '/cvc_cgi/dte/of_confirma_folio';
        $this->log("Confirmando folios", ["url" => $url]);

        $query = [
            'RUT_EMP' => $rutEmpresa,
            'DV_EMP' => $dvEmpresa,
            'FOLIO_INICIAL' => $params['folioInicial'],
            'COD_DOCTO' => $params['tipoDte'],
            'AFECTO_IVA' => 'S',
            'CON_CREDITO' => '0',
            'CON_AJUSTE' => '0',
            'FACTOR' => null,
            'CANT_DOCTOS' => $params['folioFinal'] - $params['folioInicial'] + 1,
            'ACEPTAR' => '(unable to decode value)',
        ];
        $this->log("Parámetros de confirmación", $query);

        $client = new Client([
            'headers' => $headers,
            'base_uri' => 'https://' . $this->servidor . '.sii.cl',
            'cookies' => $cookies,
            'defaults' => [
                'exceptions' => false,
                'allow_redirects' => false,
            ],
            'query' => $query,
        ]);

        $this->log("Ejecutando request de confirmación de folios");
        $response = $client->request('POST', $url, [
            'form_params' => $query,
        ]);

        $responseCode = $response->getStatusCode();
        $this->log("Respuesta de confirmación de folios con código: " . $responseCode);

        $responseBody = (string)$response->getBody();
        $this->saveDebugHtml("3_confirmar_folios", $responseBody, [
            "URL" => 'https://' . $this->servidor . '.sii.cl' . $url,
            "Método" => "POST",
            "Código de respuesta" => $responseCode,
            "Parámetros" => $query
        ]);

        $this->log("Analizando respuesta de confirmación de folios");
        $parsed = $this->parseResponse($responseBody);

        if ($responseCode != 200 || $parsed['success'] === false) {
            $this->log("Confirmación de folios fallida", $parsed);
            return [
                'success' => false,
                'message' => $parsed['message'] ?? 'Error al confirmar folios',
                'code' => 'of_confirma_folio'
            ];
        }

        $this->log("Confirmación de folios exitosa");
        return [
            'success' => true,
            'message' => 'Confirmación de folios exitosa'
        ];
    }

    /**
     * Genera folios
     */
    private function generarFolios(CookieJar $cookies, array $headers, string $rutEmpresa, string $dvEmpresa, array $params)
    {
        $url = '/cvc_cgi/dte/of_genera_folio';
        $this->log("Generando folios", ["url" => $url]);

        $query = [
            'NOMUSU' => strtoupper($this->nombreCert),
            'CON_CREDITO' => 0,
            'CON_AJUSTE' => 0,
            'FOLIO_INI' => $params['folioInicial'],
            'FOLIO_FIN' => $params['folioFinal'],
            'DIA' => date('d'),
            'MES' => date('m'),
            'ANO' => date('Y'),
            'HORA' => date('H'),
            'MINUTO' => date('i'),
            'RUT_EMP' => $rutEmpresa,
            'DV_EMP' => $dvEmpresa,
            'COD_DOCTO' => $params['tipoDte'],
            'CANT_DOCTOS' => $params['folioFinal'] - $params['folioInicial'] + 1,
            'ACEPTAR' => 'Obtener Folios',
        ];
        $this->log("Parámetros de generación", $query);

        $client = new Client([
            'headers' => $headers,
            'base_uri' => 'https://' . $this->servidor . '.sii.cl',
            'cookies' => $cookies,
            'defaults' => [
                'exceptions' => false,
                'allow_redirects' => false,
            ],
            'query' => $query,
        ]);

        $this->log("Ejecutando request de generación de folios");
        $response = $client->request('POST', $url, [
            'form_params' => $query,
        ]);

        $responseCode = $response->getStatusCode();
        $this->log("Respuesta de generación de folios con código: " . $responseCode);

        $responseBody = (string)$response->getBody();
        $this->saveDebugHtml("4_generar_folios", $responseBody, [
            "URL" => 'https://' . $this->servidor . '.sii.cl' . $url,
            "Método" => "POST",
            "Código de respuesta" => $responseCode,
            "Parámetros" => $query
        ]);

        $this->log("Analizando respuesta de generación de folios");
        $parsed = $this->parseResponse($responseBody);

        if ($responseCode != 200 || $parsed['success'] === false) {
            $this->log("Generación de folios fallida", $parsed);
            return [
                'success' => false,
                'message' => $parsed['message'] ?? 'Error al generar folios',
                'code' => 'of_genera_folio'
            ];
        }

        $this->log("Generación de folios exitosa");
        return [
            'success' => true,
            'message' => 'Generación de folios exitosa'
        ];
    }

    /**
     * Genera archivo de folios
     */
    private function generarArchivoFolios(CookieJar $cookies, array $headers, string $rutEmpresa, string $dvEmpresa, array $params)
    {
        $url = '/cvc_cgi/dte/of_genera_archivo';
        $this->log("Generando archivo de folios", ["url" => $url]);

        $query = [
            'RUT_EMP' => $rutEmpresa,
            'DV_EMP' => $dvEmpresa,
            'COD_DOCTO' => $params['tipoDte'],
            'FOLIO_INI' => $params['folioInicial'],
            'FOLIO_FIN' => $params['folioFinal'],
            'FECHA' => date('Y-m-d'),
            'ACEPTAR' => 'AQUI',
        ];
        $this->log("Parámetros de generación de archivo", $query);

        $client = new Client([
            'headers' => [
                'content-type' => 'application/x-www-form-urlencoded',
                'Accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,image/avif,image/webp,image/apng,*/*;q=0.8,application/signed-exchange;v=b3;q=0.9',
                'Accept-Encoding' => 'gzip, deflate, br',
            ],
            'base_uri' => 'https://' . $this->servidor . '.sii.cl',
            'cookies' => $cookies,
            'defaults' => [
                'exceptions' => false,
                'allow_redirects' => false,
            ],
            'query' => $query,
        ]);

        $this->log("Ejecutando request de generación de archivo de folios");
        $response = $client->request('POST', $url, [
            'form_params' => $query,
        ]);

        $responseCode = $response->getStatusCode();
        $this->log("Respuesta de generación de archivo con código: " . $responseCode);

        $content = $response->getBody()->getContents();
        $this->log("Analizando respuesta de generación de archivo");
        $parsed = $this->parseResponse($content);

        if ($responseCode != 200 || $parsed['success'] === false) {
            $this->log("Generación de archivo de folios fallida", $parsed);
            return [
                'success' => false,
                'message' => $parsed['message'] ?? 'Error al generar archivo de folios',
                'code' => 'of_genera_archivo'
            ];
        }

        $this->log("Generación de archivo de folios exitosa");
        $contentLength = strlen($content);
        $this->log("Tamaño del contenido generado: " . $contentLength . " bytes");

        return [
            'success' => true,
            'message' => 'Generación de archivo de folios exitosa',
            'content' => $content
        ];
    }

    /**
     * Cierra sesión en el SII
     */
    private function logout(CookieJar $cookies, array $headers)
    {
        $this->log("Iniciando cierre de sesión en SII");
        try {
            $client = new Client([
                'headers' => $headers,
                'base_uri' => 'https://' . $this->servidor . '.sii.cl',
                'cookies' => $cookies,
            ]);
            $client->request('GET', '/cgi_AUT2000/CAutLogout.cgi?http://www.sii.cl/');
            $this->log("Sesión cerrada exitosamente");
            return true;
        } catch (\Throwable $th) {
            $this->log("Error al cerrar sesión: " . $th->getMessage());
            return false;
        }
    }

    /**
     * Analiza la respuesta HTML del SII
     */
    private function parseResponse(string $html)
    {
        $this->log("Analizando respuesta HTML", ["length" => strlen($html)]);

        // Verificar errores comunes
        if (strpos($html, 'Error') !== false || strpos($html, 'error') !== false) {
            // Intentar extraer mensaje de error
            $message = null;
            if (preg_match('/<[h][1-6]>(.+?)<\/[h][1-6]>/', $html, $matches)) {
                $message = trim($matches[1]);
            } elseif (preg_match('/<p[^>]*>(.+?)<\/p>/', $html, $matches)) {
                $message = trim($matches[1]);
            } elseif (preg_match('/<div[^>]*>(.+?)<\/div>/', $html, $matches)) {
                $message = trim($matches[1]);
            }
            $this->log("Error detectado en respuesta HTML: " . ($message ?? 'Error no especificado'));
            return [
                'success' => false,
                'message' => $message ?? 'Error no especificado',
            ];
        }

        // Verificar si la sesión expiró
        if (strpos($html, 'sesión ha expirado') !== false || strpos($html, 'sesion ha expirado') !== false || strpos($html, 'ha finalizado su sesión') !== false) {
            $this->log("Sesión expirada detectada en respuesta HTML");
            return [
                'success' => false,
                'message' => 'La sesión ha expirado',
            ];
        }

        // Si no se detectan errores, se considera exitoso
        $this->log("No se detectaron errores en la respuesta HTML");
        return [
            'success' => true,
        ];
    }
}
