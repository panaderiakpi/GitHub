<?php
// Datos necesarios
$APP_KEY_DROPBOX = "dlp5gp2wnpwpemw";  // API App key Dropbox
$APP_SECRET_DROPBOX = "629f1z2kzqmpqit";  // API App secret Dropbox
$REFRESH_TOKEN_DROPBOX = "2ZaA-C17U9cAAAAAAAAAATT4xMMzRLPty-8HNohgKI83BO7fYperM0Nn2HkHuPg9";  // API refresh token Dropbox
$ARCHIVO_ORIGEN_O_URL = "https://ottcache.dof6.com/movistarplus/webplayer/DIFUSION/contents/epg";  // URL del archivo M3U
$ARCHIVO_SALIDA = "btf.m3u";  // Archivo de salida
$ARCHIVO_PROCESO_FINALIZADO = "resultado.txt";  // Archivo para el proceso finalizado

// Función para cargar el token AUTHORIZATION_BEARER
function cargar_autorizacion_bearer($archivo) {
    if (!file_exists($archivo)) {
        die("Error: El archivo $archivo no existe.");
    }
    return trim(file_get_contents($archivo));
}

// Función para obtener Access Token desde Refresh Token de Dropbox
function obtener_access_token_dropbox() {
    global $APP_KEY_DROPBOX, $APP_SECRET_DROPBOX, $REFRESH_TOKEN_DROPBOX;

    $url_token = "https://api.dropbox.com/oauth2/token";
    $headers = ['Content-Type: application/x-www-form-urlencoded'];
    $data = http_build_query([
        'grant_type' => 'refresh_token',
        'refresh_token' => $REFRESH_TOKEN_DROPBOX,
        'client_id' => $APP_KEY_DROPBOX,
        'client_secret' => $APP_SECRET_DROPBOX
    ]);

    $curl = curl_init($url_token);
    curl_setopt($curl, CURLOPT_POST, true);
    curl_setopt($curl, CURLOPT_POSTFIELDS, $data);
    curl_setopt($curl, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);

    $response = curl_exec($curl);
    if (curl_errno($curl)) {
        die("Error al obtener Access Token: " . curl_error($curl));
    }
    curl_close($curl);

    $result = json_decode($response, true);
    return $result['access_token'] ?? null;
}

// Función para realizar la solicitud POST
function hacer_peticion_post($url, $datos, $token) {
    $headers = [
        "Authorization: Bearer $token",
        "Content-Type: application/json",
        "Accept: application/json"
    ];

    $curl = curl_init($url);
    curl_setopt($curl, CURLOPT_POST, true);
    curl_setopt($curl, CURLOPT_POSTFIELDS, json_encode($datos));
    curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($curl, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($curl, CURLOPT_HTTP_VERSION, CURL_HTTP_VERSION_1_1); // Forzar HTTP 1.1

    $respuesta = curl_exec($curl);
    if (curl_errno($curl)) {
        die("Error en la solicitud: " . curl_error($curl));
    }

    $http_status = curl_getinfo($curl, CURLINFO_HTTP_CODE);
    if ($http_status != 200) {
        die("Error: Código HTTP $http_status, respuesta: " . $respuesta);
    }

    curl_close($curl);
    return json_decode($respuesta, true);
}

// Función para obtener el token diario de 24 horas de M+
function obtener_token_diario($authorization_bearer) {
    $url_token = "https://idserver.dof6.com/00QSp000006KYmsMAG-L/devices/webplayer/cdn/token/refresh";
    $datos = [];  // Cuerpo vacío en formato JSON
    return hacer_peticion_post($url_token, $datos, $authorization_bearer);
}

// Función para descargar y procesar el archivo M3U
function procesar_m3u($url_m3u, $archivo_salida, $token_diario) {
    $contenido_m3u = file_get_contents($url_m3u);
    if ($contenido_m3u === false) {
        die("Error al descargar el archivo M3U.");
    }

    $lineas = explode("\n", $contenido_m3u);
    $archivo = fopen($archivo_salida, 'w');
    if (!$archivo) {
        die("Error al crear el archivo de salida.");
    }

    // Añadir el token en las líneas correspondientes
    foreach ($lineas as $linea) {
        if (strpos($linea, "http") === 0) {
            fwrite($archivo, "#KODIPROP:inputstream.adaptive.stream_headers=X-TCDN-token=$token_diario\n");
        }
        fwrite($archivo, $linea . "\n");
    }

    fclose($archivo);
    echo "Archivo procesado y guardado como '$archivo_salida'.\n";
}

// Función para subir el archivo a Dropbox
function subir_archivo_dropbox($archivo_local) {
    $access_token = obtener_access_token_dropbox();
    if (!$access_token) {
        die("Error al obtener Access Token para Dropbox.");
    }

    $dropbox_url = "https://content.dropboxapi.com/2/files/upload";
    $headers = [
        "Authorization: Bearer $access_token",
        "Content-Type: application/octet-stream",
        "Dropbox-API-Arg: " . json_encode([
            "path" => "/" . basename($archivo_local),
            "mode" => "overwrite",
            "autorename" => true,
            "mute" => false
        ])
    ];

    $file_content = file_get_contents($archivo_local);
    if (!$file_content) {
        die("Error al leer el archivo local para subir a Dropbox.");
    }

    $curl = curl_init($dropbox_url);
    curl_setopt($curl, CURLOPT_POST, true);
    curl_setopt($curl, CURLOPT_POSTFIELDS, $file_content);
    curl_setopt($curl, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($curl, CURLOPT_HTTP_VERSION, CURL_HTTP_VERSION_1_1); // Forzar HTTP 1.1

    $response = curl_exec($curl);
    if (curl_errno($curl)) {
        die("Error en la subida a Dropbox: " . curl_error($curl));
    }

    $http_status = curl_getinfo($curl, CURLINFO_HTTP_CODE);
    if ($http_status != 200) {
        die("Error: Código HTTP $http_status al subir a Dropbox.");
    }

    curl_close($curl);

    // Generar enlace compartido
    $dropbox_api_share = "https://api.dropboxapi.com/2/sharing/create_shared_link_with_settings";
    $headers_share = [
        "Authorization: Bearer $access_token",
        "Content-Type: application/json"
    ];
    $data_share = json_encode(["path" => "/" . basename($archivo_local), "settings" => ["requested_visibility" => "public"]]);

    $curl_share = curl_init($dropbox_api_share);
    curl_setopt($curl_share, CURLOPT_POST, true);
    curl_setopt($curl_share, CURLOPT_HTTPHEADER, $headers_share);
    curl_setopt($curl_share, CURLOPT_POSTFIELDS, $data_share);
    curl_setopt($curl_share, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($curl_share, CURLOPT_HTTP_VERSION, CURL_HTTP_VERSION_1_1); // Forzar HTTP 1.1

    $response_share = curl_exec($curl_share);
    if (curl_errno($curl_share)) {
        die("Error al crear enlace compartido en Dropbox: " . curl_error($curl_share));
    }

    $http_status_share = curl_getinfo($curl_share, CURLINFO_HTTP_CODE);
    if ($http_status_share != 200) {
        die("Error: Código HTTP $http_status_share al crear enlace compartido.");
    }

    $link_data = json_decode($response_share, true);
    curl_close($curl_share);

    $shared_link = $link_data['url'];
    $direct_link = str_replace("?dl=0", "?dl=1", $shared_link);
    echo "Archivo subido correctamente a Dropbox. Enlace de descarga directa: $direct_link\n";
}

// Script principal
$AUTHORIZATION_BEARER = cargar_autorizacion_bearer('AUTHORIZATION_BEARER.ini');

// Obtener el token diario de 24 horas
$token_diario_data = obtener_token_diario($AUTHORIZATION_BEARER);
$token_diario = $token_diario_data['access_token'] ?? die("Error al obtener el token diario.");

// Procesar el archivo M3U
procesar_m3u($ARCHIVO_ORIGEN_O_URL, $ARCHIVO_SALIDA, $token_diario);

// Subir archivo a Dropbox
subir_archivo_dropbox($ARCHIVO_SALIDA);

// Guardar informe del proceso
file_put_contents($ARCHIVO_PROCESO_FINALIZADO, "PROCEDIMIENTO FINALIZADO CON ÉXITO. ¡PAZ Y AMOR, Y EL PLUS PAL SALÓN!\n");
?>
