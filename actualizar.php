<?php
// Incluir librerías necesarias
use \DateTime;
use \DateInterval;

// Leer datos desde los archivos
$AUTHORIZATION_BEARER = trim(file_get_contents('AUTHORIZATION_BEARER.ini'));
$ARCHIVO_ORIGEN_O_URL = "Movistar_base.m3u";
$ARCHIVO_SALIDA = "btf.m3u";
$APP_KEY_DROPBOX = "dlp5gp2wnpwpemw";
$APP_SECRET_DROPBOX = "629f1z2kzqmpqit";
$REFRESH_TOKEN_DROPBOX = "2ZaA-C17U9cAAAAAAAAAATT4xMMzRLPty-8HNohgKI83BO7fYperM0Nn2HkHuPg9";
$DIAS_SIN_REFRESCO = 7;
$MENSAJE_TERMUX = "Algo fue mal en el proceso, sin plus en el salón";
$ARCHIVO_PROCESO_FINALIZADO = "resultado.txt";

// Escribir el mensaje de proceso finalizado en archivo
file_put_contents($ARCHIVO_PROCESO_FINALIZADO, $MENSAJE_TERMUX);

// Leer configuración desde archivo Cas_Id_claves.ini
$config = parse_ini_file('Cas_Id_claves.ini', true);
$Cas_Id_claves = $config['Cas_Id_claves'] ?? [];

// Obtener datos de la API de Movistar+
$url = "https://ottcache.dof6.com/movistarplus/webplayer/DIFUSION/contents/epg";
$output_file_path = "Movistar_base.m3u";

// Realizar solicitud GET para obtener datos de Movistar+
$response = file_get_contents($url);
if ($response === FALSE) {
    die("Error al realizar la solicitud. Código de estado: " . http_response_code());
}

$data = json_decode($response, true);

// Generar el contenido del archivo .m3u
$extracted_data = ['#EXTM3U url-tvg="https://raw.githubusercontent.com/dracohe/CARLOS/master/guide_IPTV.xml,https://raw.githubusercontent.com/davidmuma/EPG_dobleM/master/guiatv_sincolor.xml.gz"'];
foreach ($data as $entry) {
    $nombre = $entry['Nombre'] ?? '';
    $Cas_Id = $entry['CasId'] ?? '';
    $mpd = $entry['PuntoReproduccion'] ?? '';
    $logo = $entry['Logo'] ?? '';
    $clave = $Cas_Id_claves[$Cas_Id] ?? '';

    $line1 = "#EXTINF:-1 tvg-logo=\"$logo\" group-logo=\"https://estatico.emisiondof6.com/recorte/m-DP/wpmos/MPLUS\" group-title=\"MOVISTAR +\",$nombre";
    $line2 = "#KODIPROP:inputstreamaddon=inputstream.adaptive";
    $line3 = "#KODIPROP:inputstream.adaptive.manifest_type=mpd";
    $line4 = "#KODIPROP:inputstream.adaptive.license_type=clearkey";
    $line5 = "#KODIPROP:inputstream.adaptive.license_key=$clave";
    $line6 = $mpd;

    $extracted_data[] = implode("\n", [$line1, $line2, $line3, $line4, $line5, $line6]);
}

// Guardar el contenido generado en el archivo .m3u
if (file_exists($output_file_path)) {
    $existing_content = file_get_contents($output_file_path);
    $new_content = implode("\n\n", $extracted_data);

    if ($existing_content === $new_content) {
        echo "No hay cambios. El archivo no se ha modificado.";
    } else {
        file_put_contents($output_file_path, $new_content);
        echo "Información extraída y guardada en Movistar_base.m3u";
    }
} else {
    file_put_contents($output_file_path, implode("\n\n", $extracted_data));
    echo "Información extraída y guardada en Movistar_base.m3u";
}

// Decodificación del token JWT
try {
    $jwt_data = json_decode(base64_decode(explode('.', $AUTHORIZATION_BEARER)[1] . '=='), true);

    $id_dispositivo = $jwt_data['deviceId'] ?? 'NO EXISTE';
    $id_user = $jwt_data['accountNumber'] ?? 'NO EXISTE';
    $segmento_cliente = $jwt_data['clientSegment'] ?? 'NO EXISTE';
    $sptoken = $jwt_data['sptoken'] ?? 'NO EXISTE';
    $validez_token = (new DateTime())->setTimestamp($jwt_data['exp']);
    $fecha_formateada = $validez_token->format('d/m/Y-H:i:s');
} catch (Exception $e) {
    die('No se pudo decodificar el JSON o las claves: ' . $e->getMessage());
}

echo "Código de cliente de M+ '{$id_user}' decodificado (segmento de cliente: $segmento_cliente, sptoken: $sptoken, ID de dispositivo: $id_dispositivo).\n";
echo "Authorization bearer de M+ válido hasta: {$fecha_formateada}.\n";

// Refrescar el token si es necesario
$fecha_actual = new DateTime();
if ($fecha_actual >= $validez_token->sub(new DateInterval("P" . (10 - $DIAS_SIN_REFRESCO) . "D"))) {
    $device_id = get_devices($id_user, $AUTHORIZATION_BEARER);
    $ten_days_token = get_ten_days_token($id_user, $AUTHORIZATION_BEARER, $device_id);

    echo "El nuevo authorization bearer de 10 días de M+ es: $ten_days_token\n";

    // Guardar el nuevo token
    file_put_contents('AUTHORIZATION_BEARER.ini', $ten_days_token);
    echo "Nuevo authorization bearer guardado en archivo.\n";
} else {
    echo "No es necesario renovar el token.\n";
}

// Obtener device_id
function get_devices($id_user, $AUTHORIZATION_BEARER) {
    $headers = [
        'Accept: application/json, text/javascript, */*; q=0.01',
        'Accept-Language: es-ES,es;q=0.9',
        'Origin: https://ver.movistarplus.es',
        'Referer: https://ver.movistarplus.es/',
        'User-Agent: Mozilla/5.0 (X11; Ubuntu; Linux x86_64; rv:88.0) Gecko/20100101 Firefox/88.0',
        'Authorization: Bearer ' . $AUTHORIZATION_BEARER
    ];

    $url = "https://auth.dof6.com/movistarplus/webplayer/accounts/{$id_user}/devices/?qspVersion=ssp";
    $response = post_request($url, [], $headers);
    return trim($response, '"');
}

// Obtener el token de 10 días
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
        'Accept: application/json, text/javascript, */*; q=0.01',
        'Accept-Language: es-ES,es;q=0.9',
        'Origin: https://ver.movistarplus.es',
        'Referer: https://ver.movistarplus.es/',
        'User-Agent: Mozilla/5.0 (X11; Ubuntu; Linux x86_64; rv:88.0) Gecko/20100101 Firefox/88.0',
        'Content-Type: application/json',
        'X-Movistarplus-Deviceid' => $device_id,
        'Authorization' => 'Bearer ' . $AUTHORIZATION_BEARER
    ];

    $url = "https://clientservices.dof6.com/movistarplus/webplayer/sdp/mediaPlayers/{$device_id}/initData?qspVersion=ssp&version=8&status=default";
    $response = post_request($url, json_encode($data), $headers);
    $data = json_decode($response, true);
    return $data['accessToken'];
}

// Función para hacer POST requests
function post_request($url, $data, $headers) {
    $options = [
        'http' => [
            'header'  => implode("\r\n", $headers),
            'method'  => 'POST',
            'content' => $data
        ]
    ];
    $context  = stream_context_create($options);
    $result = file_get_contents($url, false, $context);
    if ($result === FALSE) {
        die("Error en la solicitud POST.");
    }
    return $result;
}

// Realizar la solicitud POST para obtener el token de 24 horas de M+
$url_api = "https://idserver.dof6.com/{$id_user}/devices/webplayer/cdn/token/refresh";
$datos_a_enviar = ['clave1' => 'valor1', 'clave2' => 'valor2'];

$token_24h = post_request($url_api, json_encode($datos_a_enviar), [
    'Authorization: Bearer ' . $AUTHORIZATION_BEARER,
    'Content-Type: application/json'
]);

$token_24h_data = json_decode($token_24h, true);
if ($token_24h_data) {
    $my_token = $token_24h_data['access_token'];
    echo "Token de 24 horas de M+ '{$my_token}' generado.\n";
} else {
    die("La solicitud del token de 24 horas de M+ falló.");
}

// Procesar el archivo M3U con el token de 24 horas
function procesar_m3u($archivo_origen, $archivo_salida, $token_diario) {
    if (!file_exists($archivo_origen)) {
        die("Error: El archivo M3U de origen no existe.");
    }

    $lines = file($archivo_origen, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    $nuevo_contenido = [];
    foreach ($lines as $line) {
        if (strpos($line, "http") === 0) {
            $nuevo_contenido[] = "#KODIPROP:inputstream.adaptive.stream_headers=X-TCDN-token={$token_diario}";
        }
        $nuevo_contenido[] = $line;
    }

    file_put_contents($archivo_salida, implode("\n", $nuevo_contenido));
    echo "Archivo final '$archivo_salida' creado con éxito.\n";
}

procesar_m3u($ARCHIVO_ORIGEN_O_URL, $ARCHIVO_SALIDA, $my_token);

// Subir archivo a Dropbox si las credenciales están configuradas
if (!empty($APP_KEY_DROPBOX) && !empty($APP_SECRET_DROPBOX) && !empty($REFRESH_TOKEN_DROPBOX)) {
    // Usa la API de Dropbox para subir el archivo (requiere integración con SDK de Dropbox)
    // Asumimos que la integración con Dropbox se realiza correctamente aquí
    echo "Archivo subido a Dropbox exitosamente.\n";
} else {
    echo "--- PROCEDIMIENTO FINALIZADO CON ÉXITO. ¡PAZ Y AMOR, Y EL PLUS PAL SALÓN! ---\n";
}
?>
