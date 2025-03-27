# SII Folios Service

## Descripción

SII Folios Service es un servicio API escrito en PHP que permite la obtención automatizada de folios (rangos de números de documentos tributarios) desde el Servicio de Impuestos Internos (SII) de Chile. Este servicio facilita la integración de la solicitud de folios dentro de sistemas de facturación electrónica o ERPs, eliminando la necesidad de realizar este proceso manualmente a través del sitio web del SII.

## Características

- Autenticación mediante certificado digital
- Solicitud de folios para diferentes tipos de documentos tributarios (facturas, boletas, notas de crédito, etc.)
- Soporte para ambientes de certificación (maullin) y producción (palena)
- Almacenamiento de archivos XML de folios obtenidos
- Sistema de logs detallados para monitoreo y depuración
- Contenedor Docker para fácil despliegue
- API REST para integración con otros sistemas

## Requisitos

- Docker y Docker Compose (recomendado para despliegue)
- PHP 8.1 o superior (para instalación sin Docker)
- Certificado digital válido emitido por el SII
- RUT con permisos para solicitar folios en el SII

## Instalación

### Usando Docker (recomendado)

1. Clone el repositorio:

   ```bash
   git clone https://github.com/tu-usuario/sii-folios-service.git
   cd sii-folios-service
   ```

2. Configure las variables de entorno en `docker-compose.yml` si es necesario:

   ```yaml
   environment:
     - SII_SERVIDOR=maullin # maullin (certificación) o palena (producción)
     - FOLIOS_PATH=/var/www/html/storage/folios/
     - LOG_PATH=/var/www/html/storage/logs/
     - DEBUG_PATH=/var/www/html/storage/debug/
     - ENABLE_LOGGING=true
     - ENABLE_HTML_DEBUG=true
   ```

3. Inicie el servicio:
   ```bash
   docker-compose up -d
   ```

### Instalación manual (sin Docker)

1. Clone el repositorio:

   ```bash
   git clone https://github.com/tu-usuario/sii-folios-service.git
   cd sii-folios-service
   ```

2. Instale las dependencias:

   ```bash
   composer install
   ```

3. Cree un archivo `config.php` en la raíz del proyecto:

   ```php
   <?php
   $config = [
       'servidor' => 'maullin',  // maullin (certificación) o palena (producción)
       'foliosPath' => __DIR__ . '/storage/folios/',
       'logPath' => __DIR__ . '/storage/logs/',
       'debugPath' => __DIR__ . '/storage/debug/',
       'enableLogging' => true,
       'enableHtmlDebug' => true,
   ];
   ```

4. Cree los directorios necesarios y asigne permisos:

   ```bash
   mkdir -p storage/folios storage/logs storage/debug
   chmod -R 755 storage
   ```

5. Configure su servidor web para apuntar a la carpeta `public` o utilice el servidor web integrado de PHP:
   ```bash
   php -S localhost:8080
   ```

## Uso

El servicio expone un endpoint API en `/api.php` que acepta solicitudes POST con los siguientes parámetros:

### Parámetros requeridos

| Parámetro           | Tipo   | Descripción                                                                       |
| ------------------- | ------ | --------------------------------------------------------------------------------- |
| folioInicial        | int    | Número de folio inicial a solicitar                                               |
| folioFinal          | int    | Número de folio final a solicitar                                                 |
| tipoDte             | int    | Tipo de documento tributario (33: factura, 39: boleta, 61: nota de crédito, etc.) |
| rutCert             | string | RUT del certificado digital (formato: 12345678-9)                                 |
| rutEmpresa          | string | RUT de la empresa para la que se solicitan folios (formato: 12345678-9)           |
| certificadoPem      | string | Contenido del certificado en formato PEM codificado en Base64                     |
| certificadoPassword | string | Contraseña del certificado digital                                                |

### Parámetros opcionales

| Parámetro       | Tipo    | Descripción                                                                                   | Valor por defecto   |
| --------------- | ------- | --------------------------------------------------------------------------------------------- | ------------------- |
| ambiente        | string  | Ambiente SII a utilizar: "certificacion" o "produccion"                                       | "produccion"        |
| servidor        | string  | Servidor SII (sobrescribe ambiente): "maullin" o "palena"                                     | Depende de ambiente |
| returnXml       | boolean | Si es true, la respuesta será el archivo XML. Si es false, devuelve JSON con el XML en Base64 | false               |
| enableLogging   | boolean | Activa o desactiva el registro de logs                                                        | true                |
| enableHtmlDebug | boolean | Activa o desactiva el guardado de HTML de depuración                                          | true                |

### Ejemplo de solicitud

```bash
curl -X POST http://localhost:8080/api.php \
  -H "Content-Type: application/json" \
  -d '{
    "folioInicial": 1,
    "folioFinal": 100,
    "tipoDte": 33,
    "rutCert": "12345678-9",
    "rutEmpresa": "98765432-1",
    "certificadoPem": "LS0tLS1CRUdJTiBDRVJUSUZJQ0FURS0tLS0tCi4uLgotLS0tLUVORCBDRVJUSUZJQ0FURS0tLS0t",
    "certificadoPassword": "clave_del_certificado",
    "ambiente": "certificacion",
    "returnXml": false
  }'
```

### Respuesta exitosa (JSON)

```json
{
  "success": true,
  "message": "Folios obtenidos correctamente",
  "filename": "/var/www/html/storage/folios/maullin/98765432_1/33/DESDE1_HASTA100.xml",
  "xml": "PD94bWwgdmVyc2lvbj0iMS4wIiBlbmNvZGluZz0iSVNPLTg4NTktMSI/Pgo8Rk9MSU8geG1sbnM..."
}
```

### Respuesta exitosa (XML)

Si `returnXml` es `true`, la respuesta será directamente el archivo XML de folios.

### Respuesta de error

```json
{
  "success": false,
  "message": "Error al solicitar folios",
  "code": "of_solicita_folios",
  "error": "Detalle del error"
}
```

## Estructura de archivos

- `/src/FoliosService.php`: Clase principal que implementa la comunicación con el SII
- `/api.php`: Punto de entrada de la API REST
- `/config.php`: Archivo de configuración (debe crearse manualmente si no se usa Docker)
- `/storage/folios/`: Directorio donde se almacenan los archivos XML de folios
- `/storage/logs/`: Directorio donde se almacenan los logs
- `/storage/debug/`: Directorio donde se almacenan los HTML de depuración

## Logs y depuración

El servicio genera logs detallados en el directorio `/storage/logs/` que pueden ser útiles para identificar problemas. Además, guarda los HTML de respuesta del SII en el directorio `/storage/debug/` para facilitar la depuración.

## Consideraciones importantes

1. **Certificado digital**: Es necesario contar con un certificado digital válido emitido por el SII para la autenticación.

2. **Permisos en SII**: El RUT asociado al certificado debe tener permisos para solicitar folios en el SII.

3. **Cantidad de folios**: El SII tiene limitaciones en cuanto a la cantidad de folios que se pueden solicitar en una sola operación, dependiendo del tipo de documento.

4. **Ambientes**: Recuerde utilizar el ambiente de certificación (maullin) para pruebas y el de producción (palena) para operaciones reales.

5. **Seguridad**: La contraseña del certificado y el certificado mismo se transmiten en la solicitud API. Asegúrese de implementar medidas de seguridad adecuadas (HTTPS, firewalls, etc.) para proteger esta información sensible.

## Licencia

Este proyecto está licenciado bajo la Licencia Pública General de GNU v3 (GPL-3.0) - vea el archivo [LICENSE](LICENSE) para más detalles.

La GPL-3.0 es una licencia copyleft, lo que significa que el software derivado solo puede distribuirse bajo los mismos términos de licencia. Esta licencia:

- Permite a cualquier persona usar, estudiar, compartir (copiar) y modificar el software
- Requiere que cualquier software derivado se distribuya bajo la misma licencia GPL-3.0
- Requiere que el código fuente de versiones modificadas esté disponible
- Garantiza que el software y sus derivados permanezcan libres y de código abierto

Al utilizar este código, usted acepta cumplir con los términos de la licencia GPL-3.0.

## Contribuciones

Las contribuciones son bienvenidas. Por favor, envíe sus pull requests al repositorio principal.
