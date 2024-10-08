<?php
// Establecer la zona horaria a Europa/Madrid
date_default_timezone_set('Europe/Madrid');

// Leer el AUTHORIZATION_BEARER desde el archivo
function cargar_autorizacion() {
    $archivo_ini = 'AUTHORIZATION_BEARER.ini';
    if (!file_exists($archivo_ini)) {
        die("Error: El archivo $archivo_ini no existe.");
    }
    $token = trim(file_get_contents($archivo_ini));
    if (empty($token)) {
        die("Error: El archivo $archivo_ini está vacío o el token no es válido.");
    }
    return $token;
}

$AUTHORIZATION_BEARER = cargar_autorizacion();
$ARCHIVO_ORIGEN_O_URL = "Movistar_base.m3u";
$ARCHIVO_SALIDA = "Rikelme.m3u";
$APP_KEY_DROPBOX = "j4qzw5qznworln6";
$APP_SECRET_DROPBOX = "ku4dzr9sr2zwfjx";
$REFRESH_TOKEN_DROPBOX = "JTs2sCywwLwAAAAAAAAAASKos1CQAtZ9PdAI1p7UrubYMAvLLrzKgV_ztM8fEELx";
$DIAS_SIN_REFRESCO = 7;
$MENSAJE_TERMUX = "Algo fue mal en el proceso, sin plus en el salón";
$ARCHIVO_PROCESO_FINALIZADO = "resultado.txt";

// Guardar mensaje de proceso finalizado
file_put_contents($ARCHIVO_PROCESO_FINALIZADO, $MENSAJE_TERMUX);

// Decodificar el JWT para obtener datos del dispositivo
function decodificar_jwt($jwt_token) {
    $partes = explode('.', $jwt_token);
    if (count($partes) < 2) {
        die("Error: El token JWT no tiene el formato esperado.");
    }
    $payload_base64 = str_replace(['-', '_'], ['+', '/'], $partes[1]);
    $padding = strlen($payload_base64) % 4;
    if ($padding) {
        $payload_base64 .= str_repeat('=', 4 - $padding);
    }
    $payload = base64_decode($payload_base64);
    if (!$payload) {
        die("Error: No se pudo decodificar el token JWT.");
    }
    return json_decode($payload, true);
}

$jwt_data = decodificar_jwt($AUTHORIZATION_BEARER);

// Obtener los valores del token
$id_dispositivo = $jwt_data['deviceId'] ?? 'NO EXISTE';
$id_user = $jwt_data['accountNumber'] ?? 'NO EXISTE';
$segmento_cliente = $jwt_data['clientSegment'] ?? 'NO EXISTE';
$sptoken = $jwt_data['sptoken'] ?? 'NO EXISTE';
$validez_token = DateTime::createFromFormat('U', $jwt_data['exp']);
$fecha_formateada = $validez_token->format('d/m/Y-H:i:s');

// Función para imprimir la fecha de expiración del token
function verificar_expiracion_token($jwt_data) {
    $exp = $jwt_data['exp'] ?? null;
    if ($exp) {
        $fecha_expiracion = DateTime::createFromFormat('U', $exp);
        echo "El token actual expira el: " . $fecha_expiracion->format('d/m/Y H:i:s') . "\n";
    } else {
        echo "No se encontró la fecha de expiración en el token.\n";
    }
}

verificar_expiracion_token($jwt_data);

// Función para obtener el `device_id` (corregida para forzar HTTP/1.1)
function get_devices($id_user, $AUTHORIZATION_BEARER) {
    $url = "https://auth.dof6.com/movistarplus/webplayer/accounts/{$id_user}/devices/?qspVersion=ssp";

    $headers = [
        'Accept' => 'application/json, text/javascript, */*; q=0.01',
        'Accept-Language' => 'es-ES,es;q=0.9',
        'Origin' => 'https://ver.movistarplus.es',
        'Referer' => 'https://ver.movistarplus.es/',
        'User-Agent' => 'Mozilla/5.0 (X11; Ubuntu; Linux x86_64; rv:88.0) Gecko/20100101 Firefox/88.0',
        'Authorization' => "Bearer $AUTHORIZATION_BEARER"
    ];

    $curl = curl_init($url);
    curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($curl, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($curl, CURLOPT_HTTP_VERSION, CURL_HTTP_VERSION_1_1); // Forzar HTTP/1.1

    $response = curl_exec($curl);
    if (curl_errno($curl)) {
        die("Error en la solicitud GET para obtener el device_id: " . curl_error($curl));
    }
    curl_close($curl);

    return trim($response, '"');
}

// Función para obtener un nuevo access_token usando el refresh_token
function refrescar_access_token($refresh_token, $client_id, $client_secret) {
    $url = "https://api.oauth2server.com/token"; // URL del servidor OAuth2 para obtener un nuevo token

    $data = http_build_query([
        'grant_type' => 'refresh_token',
        'refresh_token' => $refresh_token,
        'client_id' => $client_id,
        'client_secret' => $client_secret
    ]);

    $headers = [
        'Content-Type: application/x-www-form-urlencoded'
    ];

    $curl = curl_init($url);
    curl_setopt($curl, CURLOPT_POST, true);
    curl_setopt($curl, CURLOPT_POSTFIELDS, $data);
    curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($curl, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, false);

    $response = curl_exec($curl);
    $http_status = curl_getinfo($curl, CURLINFO_HTTP_CODE);

    if (curl_errno($curl)) {
        die("Error en la solicitud POST para refrescar el token: " . curl_error($curl));
    }

    if ($http_status !== 200) {
        // Capturar el contenido de la respuesta del servidor en caso de error
        $error_response = $response ? json_decode($response, true) : "Sin respuesta del servidor";
        die("Error al intentar refrescar el token. Código de estado HTTP: $http_status\nRespuesta del servidor: " . json_encode($error_response));
    }

    curl_close($curl);

    $decoded_response = json_decode($response, true);
    if (!isset($decoded_response['access_token'])) {
        die("Error: No se pudo obtener el nuevo access_token: " . json_encode($decoded_response));
    }

    return $decoded_response;
}

// Llamar a la función para obtener un nuevo token si es necesario
$token_data = refrescar_access_token($REFRESH_TOKEN_DROPBOX, $APP_KEY_DROPBOX, $APP_SECRET_DROPBOX);

// Guardar el nuevo token en AUTHORIZATION_BEARER.ini
file_put_contents('AUTHORIZATION_BEARER.ini', $token_data['access_token']);
echo "Nuevo access_token guardado correctamente.\n";

// Función para obtener el token de 10 días
function get_ten_days_token($id_user, $AUTHORIZATION_BEARER, $device_id) {
    $url = "https://clientservices.dof6.com/movistarplus/webplayer/sdp/mediaPlayers/{$device_id}/initData?qspVersion=ssp&version=8&status=default";
    
    $data = [
        'accountNumber' => $id_user,
        'userProfile' => '0',
        'streamMiscellanea' => 'HTTPS',
        'deviceType' => 'WP_OTT',
        'deviceManufacturerProduct' => 'Firefox',
        'streamDRM' => 'Widevine',
        'streamFormat' => 'DASH'
    ];

    $headers = [
        'Accept' => 'application/json, text/javascript, */*; q=0.01',
        'Accept-Language' => 'es-ES,es;q=0.9',
        'Origin' => 'https://ver.movistarplus.es',
        'Referer' => 'https://ver.movistarplus.es/',
        'User-Agent' => 'Mozilla/5.0 (X11; Ubuntu; Linux x86_64; rv:88.0) Gecko/20100101 Firefox/88.0',
        'Content-Type' => 'application/json',
        'X-Movistarplus-Deviceid' => $device_id,
        'Authorization' => "Bearer $AUTHORIZATION_BEARER"
    ];

    $curl = curl_init($url);
    curl_setopt($curl, CURLOPT_POST, true);
    curl_setopt($curl, CURLOPT_POSTFIELDS, json_encode($data));
    curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($curl, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($curl, CURLOPT_HTTP_VERSION, CURL_HTTP_VERSION_1_1); // Forzar HTTP/1.1

    $response = curl_exec($curl);
    curl_close($curl);

    $decoded_response = json_decode($response, true);
    if (!isset($decoded_response['accessToken'])) {
        die("Error al obtener el token de 10 días: " . json_encode($decoded_response));
    }
    return $decoded_response['accessToken'];
}

// Función para refrescar el token de 24 horas
function refrescar_token_diario($id_user, $AUTHORIZATION_BEARER, $device_id) {
    $url = "https://idserver.dof6.com/{$id_user}/devices/webplayer/cdn/token/refresh";
    
    // Vamos a enviar un cuerpo vacío en formato JSON
    $data = json_encode(['device_id' => $device_id]);

    $headers = [
        'Authorization' => "Bearer $AUTHORIZATION_BEARER",
        'Content-Type' => 'application/json',  // Asegurarse de que el Content-Type sea JSON
        'Accept' => 'application/json'  // Aceptamos la respuesta como JSON también
    ];

    $curl = curl_init($url);
    curl_setopt($curl, CURLOPT_POST, true);
    curl_setopt($curl, CURLOPT_POSTFIELDS, $data);  // Pasar el cuerpo de la solicitud
    curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($curl, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($curl, CURLOPT_HTTP_VERSION, CURL_HTTP_VERSION_1_1); // Forzar HTTP/1.1

    $response = curl_exec($curl);
    $http_status = curl_getinfo($curl, CURLINFO_HTTP_CODE);

    if (curl_errno($curl)) {
        die("Error en la solicitud POST para obtener el token de 24 horas: " . curl_error($curl));
    }

    if ($http_status !== 200) {
        // Capturar el contenido de la respuesta del servidor en caso de error
        $error_response = $response ? json_decode($response, true) : "Sin respuesta del servidor";
        die("Error en la solicitud POST. Código de estado HTTP: $http_status\nRespuesta del servidor: " . json_encode($error_response));
    }

    curl_close($curl);

    $decoded_response = json_decode($response, true);
    if (!isset($decoded_response['accessToken'])) {
        die("Error al obtener el token de 24 horas: " . json_encode($decoded_response));
    }
    return $decoded_response['accessToken'];
}

// Proceso para obtener los tokens si es necesario
$fecha_actual = new DateTime();
if ($fecha_actual >= $validez_token->sub(new DateInterval("P" . (10 - $DIAS_SIN_REFRESCO) . "D"))) {
    $device_id = get_devices($id_user, $AUTHORIZATION_BEARER);
    $ten_days_token = get_ten_days_token($id_user, $AUTHORIZATION_BEARER, $device_id);
    echo "Nuevo token de 10 días obtenido: $ten_days_token\n";

    // Guardar el nuevo token en AUTHORIZATION_BEARER.ini
    file_put_contents('AUTHORIZATION_BEARER.ini', $ten_days_token);
} else {
    echo "No es necesario renovar el token de 10 días.\n";
}

// Procesar el archivo M3U añadiendo el token diario
function procesar_m3u($archivo_origen, $archivo_salida, $token_diario) {
    if (!file_exists($archivo_origen)) {
        die("Error: El archivo M3U de origen no existe.");
    }

    $lines = file($archivo_origen, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    $archivo = fopen($archivo_salida, 'w');

    if ($archivo === false) {
        die("Error: No se pudo abrir el archivo de salida.");
    }

    // Escribir el BOM de UTF-8 para asegurar que el archivo esté codificado en UTF-8
    fwrite($archivo, "\xEF\xBB\xBF");

    foreach ($lines as $line) {
        if (strpos($line, "http") === 0) {
            fwrite($archivo, "#KODIPROP:inputstream.adaptive.stream_headers=X-TCDN-token=$token_diario\n");
        }
        fwrite($archivo, $line . "\n");
    }

    fclose($archivo);
    echo "Archivo final '$archivo_salida' creado con éxito.\n";
}

// Obtener el token diario
$device_id = get_devices($id_user, $AUTHORIZATION_BEARER);
$token_diario = refrescar_token_diario($id_user, $AUTHORIZATION_BEARER, $device_id);

// Procesar el archivo M3U
procesar_m3u($ARCHIVO_ORIGEN_O_URL, $ARCHIVO_SALIDA, $token_diario);

// Función para subir archivo a Dropbox
function subir_archivo_dropbox($archivo_salida) {
    global $APP_KEY_DROPBOX, $APP_SECRET_DROPBOX, $REFRESH_TOKEN_DROPBOX;

    $url = "https://content.dropboxapi.com/2/files/upload";
    $headers = [
        "Authorization: Bearer $REFRESH_TOKEN_DROPBOX",
        "Content-Type: application/octet-stream",
        "Dropbox-API-Arg: " . json_encode([
            "path" => "/" . basename($archivo_salida),
            "mode" => "overwrite",
            "autorename" => true,
            "mute" => false
        ])
    ];

    $file_content = file_get_contents($archivo_salida);
    $curl = curl_init($url);
    curl_setopt($curl, CURLOPT_POST, true);
    curl_setopt($curl, CURLOPT_POSTFIELDS, $file_content);
    curl_setopt($curl, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);

    $response = curl_exec($curl);
    if (curl_errno($curl)) {
        die("Error en la subida a Dropbox: " . curl_error($curl));
    }
    curl_close($curl);

    return json_decode($response, true);
}

// Subir archivo a Dropbox si es necesario
if ($APP_KEY_DROPBOX && $APP_SECRET_DROPBOX && $REFRESH_TOKEN_DROPBOX) {
    $resultado_dropbox = subir_archivo_dropbox($ARCHIVO_SALIDA);
    if (isset($resultado_dropbox['path_lower'])) {
        echo "Archivo subido a Dropbox exitosamente: " . $resultado_dropbox['path_lower'] . "\n";
    } else {
        echo "Error al subir archivo a Dropbox.\n";
    }
} else {
    echo "--- PROCEDIMIENTO FINALIZADO CON ÉXITO. ¡PAZ Y AMOR, Y EL PLUS PAL SALÓN! ---\n";
}
?>
