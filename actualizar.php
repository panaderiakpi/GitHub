<?php

// Configurar la zona horaria para Europa/Madrid
date_default_timezone_set('Europe/Madrid');

// Datos necesarios
// Cargar datos necesarios desde archivos de configuración
$AUTHORIZATION_BEARER = trim(file_get_contents('AUTHORIZATION_BEARER.ini'));
$ARCHIVO_ORIGEN_O_URL = "Movistar_base.m3u";
$ARCHIVO_SALIDA = "gitbtf.m3u";  // Archivo M3U que generamos
$ARCHIVO_SALIDA = "btf.m3u";
$APP_KEY_DROPBOX = "dlp5gp2wnpwpemw";
$APP_SECRET_DROPBOX = "629f1z2kzqmpqit";
$REFRESH_TOKEN_DROPBOX = "0VakNqGAM6QAAAAAAAAAAaed3z_RGR57MDxB6iiwhDXt9VqEIP5BvSgwrHO2m9w4";
$ARCHIVO_PROCESO_FINALIZADO = "resultado.txt";  // Archivo de informe
$DROPBOX_API_UPLOAD_URL = "https://content.dropboxapi.com/2/files/upload";
$DROPBOX_API_TOKEN_URL = "https://api.dropbox.com/oauth2/token";
$DROPBOX_API_SHARED_LINK_URL = "https://api.dropboxapi.com/2/sharing/create_shared_link_with_settings";
$DROPBOX_API_LIST_FOLDER_URL = "https://api.dropboxapi.com/2/files/list_folder";
// Variables para el informe
$fecha_ejecucion = date("D M d H:i:s T Y"); // Hora de ejecución en Europa/Madrid
$cambios_realizados = false; // Para verificar si hubo cambios
$codigo_cliente = ""; // Código de cliente simulado (puedes modificarlo según tu script)
$fecha_validez_bearer = ""; // Fecha de validez del bearer (actualiza según tu script)
$token_24h = ''; // Será asignado más adelante al generarse
$enlace_dropbox_m3u = ''; // Enlace final que se asignará después de la subida a Dropbox del archivo M3U
$enlace_dropbox_informe = ''; // Enlace final que se asignará después de la subida a Dropbox del archivo de informe
// Crear el informe base
file_put_contents($ARCHIVO_PROCESO_FINALIZADO, "Proceso en ejecución...\n");
$REFRESH_TOKEN_DROPBOX = "2ZaA-C17U9cAAAAAAAAAATT4xMMzRLPty-8HNohgKI83BO7fYperM0Nn2HkHuPg9";
$DIAS_SIN_REFRESCO = 7;
$ARCHIVO_PROCESO_FINALIZADO = "resultado.txt";
$MENSAJE_TERMUX = "Algo fue mal en el proceso, sin plus en el salón";

// Inicializar el archivo de proceso finalizado
file_put_contents($ARCHIVO_PROCESO_FINALIZADO, $MENSAJE_TERMUX);
// Leer claves desde archivo de configuración INI
$config = parse_ini_file('Cas_Id_claves.ini', true);
$Cas_Id_claves = $config['Cas_Id_claves'] ?? [];

// Obtener contenido M3U
$url = "https://ottcache.dof6.com/movistarplus/webplayer/DIFUSION/contents/epg";
$response = file_get_contents($url);
// Endpoint de Movistar para obtener la lista EPG
$url_epg = "https://ottcache.dof6.com/movistarplus/webplayer/DIFUSION/contents/epg";
$response = file_get_contents($url_epg);
if ($response === false) {
    die("Error al realizar la solicitud.");
    die("Error al realizar la solicitud EPG.");
}

$data = json_decode($response, true);
@@ -62,182 +53,150 @@
    $extracted_data[] = implode("\n", [$line1, $line2, $line3, $line4, $line5, $line6]);
}

$new_content = implode("\n\n", $extracted_data);
if (!file_exists($ARCHIVO_SALIDA) || file_get_contents($ARCHIVO_SALIDA) !== $new_content) {
    file_put_contents($ARCHIVO_SALIDA, $new_content);
    $cambios_realizados = true;
    echo "Información extraída y guardada en $ARCHIVO_SALIDA\n";
} else {
    echo "No hay cambios. El archivo no se ha modificado.\n";
// Guardar la lista M3U generada en el archivo de salida
file_put_contents($ARCHIVO_SALIDA, implode("\n\n", $extracted_data));
echo "Información extraída y guardada en $ARCHIVO_SALIDA\n";
// Decodificar el JWT para obtener deviceId, id_user y validez del token
try {
    $jwt_data = json_decode(base64_decode(explode('.', $AUTHORIZATION_BEARER)[1] . '=='), true);
    $id_dispositivo = $jwt_data['deviceId'] ?? 'NO EXISTE';
    $id_user = $jwt_data['accountNumber'] ?? 'NO EXISTE';
    $segmento_cliente = $jwt_data['clientSegment'] ?? 'NO EXISTE';
    $sptoken = $jwt_data['sptoken'] ?? 'NO EXISTE';
    $validez_token = date('d/m/Y-H:i:s', $jwt_data['exp']);
} catch (Exception $e) {
    die("No se pudo decodificar el JWT.");
}

// Función para obtener Access Token desde Refresh Token
function obtener_access_token_dropbox() {
    global $APP_KEY_DROPBOX, $APP_SECRET_DROPBOX, $REFRESH_TOKEN_DROPBOX, $DROPBOX_API_TOKEN_URL;
echo "Código de cliente de M+ '$id_user' decodificado (segmento de cliente: $segmento_cliente, ID de dispositivo: $id_dispositivo).\n";
echo "Authorization bearer de M+ válido hasta: $validez_token\n";

// Obtener el device_id necesario
function get_devices($id_user, $AUTHORIZATION_BEARER) {
    $url = "https://auth.dof6.com/movistarplus/webplayer/accounts/$id_user/devices/?qspVersion=ssp";
    $headers = [
        'Content-Type: application/x-www-form-urlencoded'
        'Authorization: Bearer ' . $AUTHORIZATION_BEARER,
        'Accept: application/json, text/javascript, */*; q=0.01',
        'User-Agent: Mozilla/5.0 (X11; Ubuntu; Linux x86_64)',
    ];

    $data = http_build_query([
        'grant_type' => 'refresh_token',
        'refresh_token' => $REFRESH_TOKEN_DROPBOX,
        'client_id' => $APP_KEY_DROPBOX,
        'client_secret' => $APP_SECRET_DROPBOX
    ]);
    $options = [
        'http' => [
            'header' => implode("\r\n", $headers),
            'method' => 'POST',
        ]
    ];

    $curl = curl_init($DROPBOX_API_TOKEN_URL);
    curl_setopt($curl, CURLOPT_POST, true);
    curl_setopt($curl, CURLOPT_POSTFIELDS, $data);
    curl_setopt($curl, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
    $context = stream_context_create($options);
    $response = file_get_contents($url, false, $context);

    $response = curl_exec($curl);
    if (curl_errno($curl)) {
        die("Error al obtener Access Token: " . curl_error($curl));
    if ($response === false) {
        die("Error al obtener device_id.");
    }
    curl_close($curl);

    $result = json_decode($response, true);
    return $result['access_token'] ?? null;
    return trim($response, '"'); // Limpiar comillas en la respuesta
}

// Función para verificar si un archivo ya existe en Dropbox
function archivo_existe_dropbox($archivo) {
    global $DROPBOX_API_LIST_FOLDER_URL;
// Obtener el token de 10 días
function get_ten_days_token($id_user, $AUTHORIZATION_BEARER, $device_id) {
    $url = "https://clientservices.dof6.com/movistarplus/webplayer/sdp/mediaPlayers/$device_id/initData?qspVersion=ssp&version=8&status=default";
    $data = json_encode([
        'accountNumber' => $id_user,
        'userProfile' => '0',
        'streamMiscellanea' => 'HTTPS',
        'deviceType' => 'WP_OTT',
        'deviceManufacturerProduct' => 'Firefox',
        'streamDRM' => 'Widevine',
        'streamFormat' => 'DASH'
    ]);

    $access_token = obtener_access_token_dropbox();
    $headers = [
        "Authorization: Bearer $access_token",
        "Content-Type: application/json"
        'Authorization: Bearer ' . $AUTHORIZATION_BEARER,
        'Content-Type: application/json',
        'X-Movistarplus-Deviceid: ' . $device_id,
    ];

    $data = json_encode([
        "path" => ""
    ]);
    $options = [
        'http' => [
            'header' => implode("\r\n", $headers),
            'method' => 'POST',
            'content' => $data,
        ]
    ];

    $curl = curl_init($DROPBOX_API_LIST_FOLDER_URL);
    curl_setopt($curl, CURLOPT_POST, true);
    curl_setopt($curl, CURLOPT_POSTFIELDS, $data);
    curl_setopt($curl, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
    $context = stream_context_create($options);
    $response = file_get_contents($url, false, $context);

    $response = curl_exec($curl);
    curl_close($curl);
    if ($response === false) {
        die("Error al obtener el token de 10 días.");
    }

    $result = json_decode($response, true);
    $response_data = json_decode($response, true);
    return $response_data['accessToken'] ?? null;
}

    if (isset($result['entries'])) {
        foreach ($result['entries'] as $entry) {
            if ($entry['name'] === $archivo) {
                return true;
            }
        }
    }
    return false;
// Obtener el device_id y el nuevo token
$device_id = get_devices($id_user, $AUTHORIZATION_BEARER);
$ten_days_token = get_ten_days_token($id_user, $AUTHORIZATION_BEARER, $device_id);
if ($ten_days_token) {
    echo "Nuevo authorization bearer de 10 días: $ten_days_token\n";
    file_put_contents('AUTHORIZATION_BEARER.ini', $ten_days_token);
}

// Función para subir un archivo a Dropbox y obtener el enlace
function subir_archivo_dropbox($archivo_salida, &$enlace_dropbox) {
    global $DROPBOX_API_UPLOAD_URL, $DROPBOX_API_SHARED_LINK_URL;
// Procesar la línea para OTT Navigator con el token de 24 horas
$parsed_url = parse_url($ARCHIVO_ORIGEN_O_URL);
$my_token = ''; // Asume que ya tienes el token de 24 horas obtenido

    $access_token = obtener_access_token_dropbox();
    if (!$access_token) {
        die("Error al obtener Access Token para Dropbox.");
    }
if ($parsed_url['scheme'] ?? false) {
    $nombre_archivo_leer = basename($parsed_url['path']);
} else {
    $nombre_archivo_leer = $ARCHIVO_ORIGEN_O_URL;
}

    $headers = [
        "Authorization: Bearer $access_token",
        "Content-Type: application/octet-stream",
        "Dropbox-API-Arg: " . json_encode([
            "path" => "/" . basename($archivo_salida),
            "mode" => "overwrite",
            "autorename" => true,
            "mute" => false
        ])
    ];
$lines = file($nombre_archivo_leer, FILE_IGNORE_NEW_LINES);
$nuevas_lineas = [];
$nueva_linea = "#KODIPROP:inputstream.adaptive.stream_headers=X-TCDN-token=$my_token\n";

    $file_content = file_get_contents($archivo_salida);
    if ($file_content === false) {
        die("Error leyendo el contenido del archivo.");
foreach ($lines as $line) {
    if (strpos($line, 'http') === 0) {
        $nuevas_lineas[] = $nueva_linea;
    }
    $nuevas_lineas[] = $line;
}

    $curl = curl_init($DROPBOX_API_UPLOAD_URL);
    curl_setopt($curl, CURLOPT_POST, true);
    curl_setopt($curl, CURLOPT_POSTFIELDS, $file_content);
    curl_setopt($curl, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
file_put_contents($ARCHIVO_SALIDA, implode("\n", $nuevas_lineas));
echo "Creado archivo final '$ARCHIVO_SALIDA' con el token de 24 horas.\n";

    $response = curl_exec($curl);
    if (curl_errno($curl)) {
        die("Error en la subida a Dropbox: " . curl_error($curl));
    }
    curl_close($curl);
    $resultado_dropbox = json_decode($response, true);
    // Obtener el enlace público del archivo
    if (isset($resultado_dropbox['path_lower'])) {
        $path_lower = $resultado_dropbox['path_lower'];
        // Crear el enlace compartido
        $curl = curl_init($DROPBOX_API_SHARED_LINK_URL);
        $shared_link_headers = [
            "Authorization: Bearer $access_token",
            "Content-Type: application/json"
        ];
        $shared_link_data = json_encode([
            "path" => $path_lower,
            "settings" => ["requested_visibility" => "public"]
        ]);
        curl_setopt($curl, CURLOPT_POST, true);
        curl_setopt($curl, CURLOPT_POSTFIELDS, $shared_link_data);
        curl_setopt($curl, CURLOPT_HTTPHEADER, $shared_link_headers);
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
        $shared_link_response = curl_exec($curl);
        curl_close($curl);
        $shared_link_result = json_decode($shared_link_response, true);
        if (isset($shared_link_result['url'])) {
            // Modificar el enlace para que sea de descarga directa (dl=1)
            $enlace_dropbox = str_replace('dl=0', 'dl=1', $shared_link_result['url']);
            echo "Archivo subido a Dropbox exitosamente. Enlace de descarga: $enlace_dropbox\n";
        } else {
            echo "Error al crear el enlace compartido de Dropbox.\n";
        }
    } else {
        echo "Error al subir archivo a Dropbox.\n";
    }
// Subida del archivo resultante a Dropbox
require 'vendor/autoload.php';  // Cargar el SDK de Dropbox

    return $resultado_dropbox;
}
function subir_archivo_dropbox($archivo_salida) {
    global $APP_KEY_DROPBOX, $APP_SECRET_DROPBOX, $REFRESH_TOKEN_DROPBOX;

// Subida del archivo M3U
if (archivo_existe_dropbox(basename($ARCHIVO_SALIDA))) {
    echo "El archivo $ARCHIVO_SALIDA ya existe en Dropbox. Será sobrescrito.\n";
}
subir_archivo_dropbox($ARCHIVO_SALIDA, $enlace_dropbox_m3u);
    $dbx = new \Dropbox\Client([
        'app_key' => $APP_KEY_DROPBOX,
        'app_secret' => $APP_SECRET_DROPBOX,
        'refresh_token' => $REFRESH_TOKEN_DROPBOX,
    ]);

// Simular generación de token de 24 horas
$token_24h = ''; // Actualiza con el valor real generado
    $file = fopen($archivo_salida, 'rb');
    $result = $dbx->files()->upload("/$archivo_salida", \Dropbox\WriteMode::overwrite(), $file);
    fclose($file);

// Crear informe de resultado
$informe = "Ejecución realizada el: $fecha_ejecucion\n";
$informe .= $cambios_realizados ? "Hubo cambios. El archivo ha sido modificado.\n" : "No hay cambios. El archivo no se ha modificado.\n";
$informe .= "Código de cliente de M+ '$codigo_cliente' decodificado (segmento de cliente: 1 sptoken: NO EXISTE e ID de dispositivo: 1cdf6519bdc4480fa9c325d383301b41).\n";
$informe .= "Authorization bearer de M+ válido hasta: $fecha_validez_bearer.\n";
$informe .= "Token de 24 horas de M+ '$token_24h' generado.\n";
$informe .= "Archivo final subido a Dropbox con enlace directo: $enlace_dropbox_m3u\n";
$informe .= "--- PROCEDIMIENTO FINALIZADO CON ÉXITO. ¡PAZ Y AMOR, Y EL PLUS PAL SALÓN! ---\n";
    $shared_link = $dbx->sharing()->create_shared_link_with_settings("/$archivo_salida");
    $shared_link_url = str_replace('dl=0', 'dl=1', $shared_link->url);

// Guardar el informe en el archivo 'resultado.txt'
file_put_contents($ARCHIVO_PROCESO_FINALIZADO, $informe);
    return $shared_link_url;
}

// Subir el archivo de informe a Dropbox
subir_archivo_dropbox($ARCHIVO_PROCESO_FINALIZADO, $enlace_dropbox_informe);
// Subir los archivos a Dropbox
$url_compartir_m3u = subir_archivo_dropbox($ARCHIVO_SALIDA);
$url_compartir_resultado = subir_archivo_dropbox($ARCHIVO_PROCESO_FINALIZADO);

echo "Informe de resultado generado y subido a Dropbox.\n";
echo "Archivo final subido a Dropbox: $url_compartir_m3u\n";
echo "Archivo resultado subido a Dropbox: $url_compartir_resultado\n";

?>
