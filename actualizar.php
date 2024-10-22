<?php

// Configuración inicial
$AUTHORIZATION_BEARER = file_get_contents('AUTHORIZATION_BEARER.ini');  // Cargar bearer token
$ARCHIVO_ORIGEN_O_URL = "Movistar_base.m3u";  // Archivo .m3u de origen
$ARCHIVO_SALIDA = "gifbtf.m3u";  // Archivo final
$APP_KEY_DROPBOX = "dlp5gp2wnpwpemw";  // API App key Dropbox
$APP_SECRET_DROPBOX = "629f1z2kzqmpqit";  // API App secret Dropbox
$REFRESH_TOKEN_DROPBOX = "2ZaA-C17U9cAAAAAAAAAATT4xMMzRLPty-8HNohgKI83BO7fYperM0Nn2HkHuPg9";  // Refresh token Dropbox
$DIAS_SIN_REFRESCO = 7;
$ARCHIVO_PROCESO_FINALIZADO = "resultado.txt";
$DROPBOX_FILE_PATH = "/gifbtf.m3u";  // Ruta del archivo en Dropbox

// Decodificar JWT
function decode_jwt($token) {
    $parts = explode(".", $token);
    if (count($parts) < 3) {
        echo "Error: Token JWT mal formado.";
        exit;
    }
    return json_decode(base64_decode($parts[1]), true);
}

// Obtener datos del token JWT
$jwt_data = decode_jwt($AUTHORIZATION_BEARER);
$id_user = $jwt_data['accountNumber'] ?? "NO EXISTE";
$segmento_cliente = $jwt_data['clientSegment'] ?? "NO EXISTE";
$validez_token = isset($jwt_data['exp']) ? DateTime::createFromFormat('U', $jwt_data['exp']) : null;

if (!$validez_token) {
    echo "Error: No se pudieron obtener datos válidos del token JWT.";
    exit;
}

echo "Authorization bearer de M+ válido hasta: " . $validez_token->format('d/m/Y-H:i:s') . ".";

// Validar renovación del token
$fecha_actual = new DateTime();
if ($fecha_actual >= $validez_token->modify('-' . (10 - $DIAS_SIN_REFRESCO) . ' days')) {
    echo "Token caducando. Renovando...";

    // Funciones para obtener el device ID y el token de 10 días
    function get_devices($id_user, $AUTHORIZATION_BEARER) {
        $headers = [
            'Authorization: Bearer ' . $AUTHORIZATION_BEARER,
            'Accept' => 'application/json, text/javascript, */*; q=0.01',
            'Accept-Language' => 'es-ES,es;q=0.9',
            'User-Agent' => 'Mozilla/5.0'
        ];

        $url = "https://auth.dof6.com/movistarplus/webplayer/accounts/{$id_user}/devices/?qspVersion=ssp";
        $opts = ['http' => ['method' => 'POST', 'header' => implode("\r\n", $headers)]];
        $context = stream_context_create($opts);

        $response = file_get_contents($url, false, $context);
        if ($response === false) {
            echo "Error: No se pudo obtener el device ID.";
            exit;
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
            'User-Agent' => 'Mozilla/5.0 (X11; Ubuntu; Linux x86_64; rv:88.0) Gecko/20100101 Firefox/88.0',
            'Origin' => 'https://ver.movistarplus.es',
            'Referer' => 'https://ver.movistarplus.es/'
        ];

        $url = "https://clientservices.dof6.com/movistarplus/webplayer/sdp/mediaPlayers/{$device_id}/initData?qspVersion=ssp";
        $opts = [
            'http' => [
                'method' => 'POST',
                'header' => implode("\r\n", $headers),
                'content' => json_encode($data)
            ]
        ];
        $context = stream_context_create($opts);

        $response = file_get_contents($url, false, $context);
        if ($response === false) {
            echo "Error: No se pudo obtener el token de 10 días.";
            exit;
        }

        $response_data = json_decode($response, true);
        return $response_data['accessToken'] ?? null;
    }

    $device_id = get_devices($id_user, $AUTHORIZATION_BEARER);
    $ten_days_token = get_ten_days_token($id_user, $AUTHORIZATION_BEARER, $device_id);

    if ($ten_days_token) {
        $AUTHORIZATION_BEARER = $ten_days_token;
        file_put_contents('AUTHORIZATION_BEARER.ini', $ten_days_token);
        echo "Nuevo token de 10 días obtenido.";
    } else {
        echo "Error: No se pudo renovar el token de 10 días.";
        exit;
    }
} else {
    echo "El token aún es válido.";
}

// Procesar el archivo .m3u
$lines = file($ARCHIVO_ORIGEN_O_URL, FILE_IGNORE_NEW_LINES);
$nuevas_lineas = [];
foreach ($lines as $line) {
    $nuevas_lineas[] = $line;
    if (strpos($line, 'http') === 0) {
        $nuevas_lineas[] = "#KODIPROP:inputstream.adaptive.stream_headers=X-TCDN-token={$AUTHORIZATION_BEARER}";
    }
}

file_put_contents($ARCHIVO_SALIDA, implode("\n", $nuevas_lineas));
echo "Archivo final '{$ARCHIVO_SALIDA}' generado correctamente.";

// Función para obtener un access token de Dropbox usando el refresh token
function obtener_access_token_dropbox($refresh_token, $app_key, $app_secret) {
    $url = "https://api.dropbox.com/oauth2/token";
    $data = [
        "grant_type" => "refresh_token",
        "refresh_token" => $refresh_token,
        "client_id" => $app_key,
        "client_secret" => $app_secret
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
        "path" => $dropbox_file_path,
        "mode" => "overwrite"
    ]);

    $fp = fopen($archivo_salida, 'rb');
    $size = filesize($archivo_salida);

    $headers = [
        'Authorization: Bearer ' . $access_token,
        'Content-Type: application/octet-stream',
        'Dropbox-API-Arg: ' . $dropbox_args,
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

// Función para crear o reutilizar un enlace compartido de Dropbox
function crear_enlace_dropbox($access_token, $dropbox_file_path) {
    $dropbox_share_url = 'https://api.dropboxapi.com/2/sharing/create_shared_link_with_settings';
    $data = json_encode([
        "path" => $dropbox_file_path,
        "settings" => ["requested_visibility" => "public"],
    ]);

    $headers = [
        'Authorization: Bearer ' . $access_token,
        'Content-Type: application/json',
    ];

    $ch = curl_init($dropbox_share_url);
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

// Subir archivo a Dropbox y crear enlace
if (subir_archivo_a_dropbox($ARCHIVO_SALIDA, $access_token, $DROPBOX_FILE_PATH)) {
    $url_publico = crear_enlace_dropbox($access_token, $DROPBOX_FILE_PATH);
    echo "Archivo subido a Dropbox. Enlace de descarga: {$url_publico}";
} else {
    echo "Error al subir el archivo a Dropbox.";
}

echo "PROCESO FINALIZADO CON ÉXITO.";

?>
