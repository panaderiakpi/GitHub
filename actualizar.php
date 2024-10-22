<?php

// Configuración inicial
$AUTHORIZATION_BEARER = file_get_contents('AUTHORIZATION_BEARER.ini');  // Leer Authorization Bearer de archivo
$ARCHIVO_ORIGEN_O_URL = "Movistar_base.m3u";  // Archivo .m3u de origen
$ARCHIVO_SALIDA = "gitbtf.m3u";  // Archivo final
$APP_KEY_DROPBOX = "dlp5gp2wnpwpemw";  // API App key de Dropbox
$APP_SECRET_DROPBOX = "629f1z2kzqmpqit";  // API App secret de Dropbox
$REFRESH_TOKEN_DROPBOX = "2ZaA-C17U9cAAAAAAAAAATT4xMMzRLPty-8HNohgKI83BO7fYperM0Nn2HkHuPg9";  // Refresh token de Dropbox
$DIAS_SIN_REFRESCO = 7;
$ARCHIVO_PROCESO_FINALIZADO = "resultado.txt";

// Verificar si el bearer token es válido
function decode_jwt($token) {
    $parts = explode(".", $token);
    if (count($parts) < 3) {
        die("Error: Token JWT mal formado.");
    }
    return json_decode(base64_decode($parts[1]), true);
}

// Obtener datos del token JWT
$jwt_data = decode_jwt($AUTHORIZATION_BEARER);
$id_user = $jwt_data['accountNumber'] ?? "NO EXISTE";
$segmento_cliente = $jwt_data['clientSegment'] ?? "NO EXISTE";
$validez_token = isset($jwt_data['exp']) ? DateTime::createFromFormat('U', $jwt_data['exp']) : null;

if (!$validez_token) {
    die("Error: No se pudieron obtener datos válidos del token JWT.");
}

echo "Authorization bearer de M+ válido hasta: " . $validez_token->format('d/m/Y-H:i:s') . ".";

// Comprobar si el token necesita ser renovado
$fecha_actual = new DateTime();
if ($fecha_actual >= $validez_token->modify('-' . (10 - $DIAS_SIN_REFRESCO) . ' days')) {
    echo "Token caducando. Renovando...";

    // Funciones para obtener el device ID y el token de 10 días
    function get_devices($id_user, $AUTHORIZATION_BEARER) {
        $headers = [
            'Authorization: Bearer ' . $AUTHORIZATION_BEARER,
            'Accept' => 'application/json',
            'User-Agent' => 'Mozilla/5.0'
        ];
        $url = "https://auth.dof6.com/movistarplus/webplayer/accounts/{$id_user}/devices/?qspVersion=ssp";
        $opts = ['http' => ['method' => 'POST', 'header' => implode("\r\n", $headers)]];
        $context = stream_context_create($opts);

        $response = file_get_contents($url, false, $context);
        if ($response === false) {
            die("Error: No se pudo obtener el device ID.");
        }
        return trim($response, '"');
    }

    function get_ten_days_token($id_user, $AUTHORIZATION_BEARER, $device_id) {
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
            'Authorization: Bearer ' . $AUTHORIZATION_BEARER,
            'Content-Type' => 'application/json',
            'User-Agent' => 'Mozilla/5.0',
            'Origin' => 'https://ver.movistarplus.es',
            'Referer' => 'https://ver.movistarplus.es/'
        ];

        $url = "https://clientservices.dof6.com/movistarplus/webplayer/sdp/mediaPlayers/{$device_id}/initData?qspVersion=ssp";
        $opts = ['http' => ['method' => 'POST', 'header' => implode("\r\n", $headers), 'content' => json_encode($data)]];
        $context = stream_context_create($opts);

        $response = file_get_contents($url, false, $context);
        if ($response === false) {
            die("Error: No se pudo obtener el token de 10 días.");
        }

        $response_data = json_decode($response, true);
        return $response_data['accessToken'] ?? null;
    }

    // Obtener el device ID y el nuevo token de 10 días
    $device_id = get_devices($id_user, $AUTHORIZATION_BEARER);
    $ten_days_token = get_ten_days_token($id_user, $AUTHORIZATION_BEARER, $device_id);

    if ($ten_days_token) {
        $AUTHORIZATION_BEARER = $ten_days_token;
        file_put_contents('AUTHORIZATION_BEARER.ini', $ten_days_token);
        echo "Nuevo token de 10 días obtenido.";
    } else {
        die("Error: No se pudo renovar el token de 10 días.");
    }
} else {
    echo "El token aún es válido.";
}

// Petición POST para obtener el token de 24 horas
function obtener_token_24h($id_user, $AUTHORIZATION_BEARER) {
    $headers = [
        'Authorization: Bearer ' . $AUTHORIZATION_BEARER,
        'Content-Type' => 'application/json'
    ];

    $url = "https://idserver.dof6.com/{$id_user}/devices/webplayer/cdn/token/refresh";
    $data = ['clave1' => 'valor1', 'clave2' => 'valor2'];  // Datos ejemplo para la solicitud

    $response = requests_post($url, $data, $headers);
    $response_data = json_decode($response, true);

    return $response_data['access_token'] ?? null;
}

// Obtener el token de 24 horas
$token_24h = obtener_token_24h($id_user, $AUTHORIZATION_BEARER);
if (!$token_24h) {
    die("Error: No se pudo obtener el token de 24 horas.");
}

// Procesar el archivo .m3u y añadir el token de 24 horas
$lines = file($ARCHIVO_ORIGEN_O_URL, FILE_IGNORE_NEW_LINES);
$nuevas_lineas = [];
foreach ($lines as $line) {
    if (strpos($line, 'http') === 0) {
        $nuevas_lineas[] = "#KODIPROP:inputstream.adaptive.stream_headers=X-TCDN-token={$token_24h}";
    }
    $nuevas_lineas[] = $line;
}

file_put_contents($ARCHIVO_SALIDA, implode("\n", $nuevas_lineas));
echo "Archivo final '{$ARCHIVO_SALIDA}' generado correctamente.";

// Función para obtener el access token de Dropbox
function obtener_access_token_dropbox($refresh_token, $app_key, $app_secret) {
    $url = "https://api.dropbox.com/oauth2/token";
    $data = [
        'grant_type' => 'refresh_token',
        'refresh_token' => $refresh_token,
        'client_id' => $app_key,
        'client_secret' => $app_secret
    ];

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($data));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    $response = curl_exec($ch);
    curl_close($ch);

    if ($response === false) {
        die("Error al obtener el access token de Dropbox.");
    }

    $json = json_decode($response, true);
    return $json['access_token'] ?? null;
}

// Función para subir el archivo a Dropbox
function subir_archivo_a_dropbox($archivo_salida, $access_token, $dropbox_file_path) {
    $dropbox_url = 'https://content.dropboxapi.com/2/files/upload';
    $dropbox_args = json_encode([
        'path' => $dropbox_file_path,
        'mode' => 'overwrite'
    ]);

    $fp = fopen($archivo_salida, 'rb');
    $size = filesize($archivo_salida);

    $headers = [
        'Authorization: Bearer ' . $access_token,
        'Content-Type: application/octet-stream',
        'Dropbox-API-Arg: ' . $dropbox_args
    ];

    $ch = curl_init($dropbox_url);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_INFILE, $fp);
    curl_setopt($ch, CURLOPT_INFILESIZE, $size);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    $response = curl_exec($ch);
    curl_close($ch);
    fclose($fp);

    return $response !== false;
}

// Función para crear enlace compartido en Dropbox
function crear_enlace_dropbox($access_token, $dropbox_file_path) {
    $url = 'https://api.dropboxapi.com/2/sharing/create_shared_link_with_settings';
    $data = json_encode([
        'path' => $dropbox_file_path,
        'settings' => ['requested_visibility' => 'public']
    ]);

    $headers = [
        'Authorization: Bearer ' . $access_token,
        'Content-Type: application/json'
    ];

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    $response = curl_exec($ch);
    curl_close($ch);

    $response_data = json_decode($response, true);
    return str_replace("dl=0", "dl=1", $response_data['url'] ?? "");
}

// Obtener access token de Dropbox
$access_token = obtener_access_token_dropbox($REFRESH_TOKEN_DROPBOX, $APP_KEY_DROPBOX, $APP_SECRET_DROPBOX);

// Subir archivo a Dropbox y obtener enlace de descarga
if (subir_archivo_a_dropbox($ARCHIVO_SALIDA, $access_token, "/gitbtf.m3u")) {
    $url_publico = crear_enlace_dropbox($access_token, "/gitbtf.m3u");
    echo "Enlace de descarga: {$url_publico}\nPROCESO FINALIZADO CON ÉXITO.";
} else {
    echo "Error al subir el archivo a Dropbox.";
}

?>
