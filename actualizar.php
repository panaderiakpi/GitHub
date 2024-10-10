<?php

// Configurar la zona horaria para Europa/Madrid
date_default_timezone_set('Europe/Madrid');

// Cargar datos necesarios desde archivos de configuración
$AUTHORIZATION_BEARER = trim(file_get_contents('AUTHORIZATION_BEARER.ini'));
$ARCHIVO_ORIGEN_O_URL = "Movistar_base.m3u";
$ARCHIVO_SALIDA = "btf.m3u";
$APP_KEY_DROPBOX = "dlp5gp2wnpwpemw";
$APP_SECRET_DROPBOX = "629f1z2kzqmpqit";
$REFRESH_TOKEN_DROPBOX = "2ZaA-C17U9cAAAAAAAAAATT4xMMzRLPty-8HNohgKI83BO7fYperM0Nn2HkHuPg9";
$DIAS_SIN_REFRESCO = 7;
$ARCHIVO_PROCESO_FINALIZADO = "resultado.txt";
$MENSAJE_TERMUX = "Algo fue mal en el proceso, sin plus en el salón";

// Inicializar el archivo de proceso finalizado
file_put_contents($ARCHIVO_PROCESO_FINALIZADO, $MENSAJE_TERMUX);

// Leer claves desde archivo de configuración INI
$config = parse_ini_file('Cas_Id_claves.ini', true);
$Cas_Id_claves = $config['Cas_Id_claves'] ?? [];

// Endpoint de Movistar para obtener la lista EPG
$url_epg = "https://ottcache.dof6.com/movistarplus/webplayer/DIFUSION/contents/epg";
$response = file_get_contents($url_epg);

if ($response === false) {
    die("Error al realizar la solicitud EPG.");
}

$data = json_decode($response, true);
if (json_last_error() !== JSON_ERROR_NONE) {
    die("Error al decodificar JSON.");
}

// Extraer y construir el nuevo contenido M3U
$extracted_data = ['#EXTM3U url-tvg="https://raw.githubusercontent.com/dracohe/CARLOS/master/guide_IPTV.xml,https://raw.githubusercontent.com/davidmuma/EPG_dobleM/master/guiatv_sincolor.xml.gz"'];
foreach ($data as $entry) {
    $nombre = $entry['Nombre'] ?? '';
    $Cas_Id = $entry['CasId'] ?? '';
    $mpd = $entry['PuntoReproduccion'] ?? '';
    $logo = $entry['Logo'] ?? '';
    $clave = $Cas_Id_claves[$Cas_Id] ?? '';

    $line1 = "#EXTINF:-1 tvg-logo=\"$logo\" group-title=\"MOVISTAR +\",$nombre";
    $line2 = '#KODIPROP:inputstreamaddon=inputstream.adaptive';
    $line3 = '#KODIPROP:inputstream.adaptive.manifest_type=mpd';
    $line4 = '#KODIPROP:inputstream.adaptive.license_type=clearkey';
    $line5 = "#KODIPROP:inputstream.adaptive.license_key=$clave";
    $line6 = $mpd;

    $extracted_data[] = implode("\n", [$line1, $line2, $line3, $line4, $line5, $line6]);
}

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

echo "Código de cliente de M+ '$id_user' decodificado (segmento de cliente: $segmento_cliente, ID de dispositivo: $id_dispositivo).\n";
echo "Authorization bearer de M+ válido hasta: $validez_token\n";

// Obtener el device_id necesario
function get_devices($id_user, $AUTHORIZATION_BEARER) {
    $url = "https://auth.dof6.com/movistarplus/webplayer/accounts/$id_user/devices/?qspVersion=ssp";
    $headers = [
        'Authorization: Bearer ' . $AUTHORIZATION_BEARER,
        'Accept: application/json, text/javascript, */*; q=0.01',
        'User-Agent: Mozilla/5.0 (X11; Ubuntu; Linux x86_64)',
    ];

    $options = [
        'http' => [
            'header' => implode("\r\n", $headers),
            'method' => 'POST',
        ]
    ];

    $context = stream_context_create($options);
    $response = file_get_contents($url, false, $context);

    if ($response === false) {
        die("Error al obtener device_id.");
    }

    return trim($response, '"'); // Limpiar comillas en la respuesta
}

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

    $headers = [
        'Authorization: Bearer ' . $AUTHORIZATION_BEARER,
        'Content-Type: application/json',
        'X-Movistarplus-Deviceid: ' . $device_id,
    ];

    $options = [
        'http' => [
            'header' => implode("\r\n", $headers),
            'method' => 'POST',
            'content' => $data,
        ]
    ];

    $context = stream_context_create($options);
    $response = file_get_contents($url, false, $context);

    if ($response === false) {
        die("Error al obtener el token de 10 días.");
    }

    $response_data = json_decode($response, true);
    return $response_data['accessToken'] ?? null;
}

// Obtener el device_id y el nuevo token
$device_id = get_devices($id_user, $AUTHORIZATION_BEARER);
$ten_days_token = get_ten_days_token($id_user, $AUTHORIZATION_BEARER, $device_id);

if ($ten_days_token) {
    echo "Nuevo authorization bearer de 10 días: $ten_days_token\n";
    file_put_contents('AUTHORIZATION_BEARER.ini', $ten_days_token);
}

// Procesar la línea para OTT Navigator con el token de 24 horas
$parsed_url = parse_url($ARCHIVO_ORIGEN_O_URL);
$my_token = ''; // Asume que ya tienes el token de 24 horas obtenido

if ($parsed_url['scheme'] ?? false) {
    $nombre_archivo_leer = basename($parsed_url['path']);
} else {
    $nombre_archivo_leer = $ARCHIVO_ORIGEN_O_URL;
}

$lines = file($nombre_archivo_leer, FILE_IGNORE_NEW_LINES);
$nuevas_lineas = [];

$nueva_linea = "#KODIPROP:inputstream.adaptive.stream_headers=X-TCDN-token=$my_token\n";

foreach ($lines as $line) {
    if (strpos($line, 'http') === 0) {
        $nuevas_lineas[] = $nueva_linea;
    }
    $nuevas_lineas[] = $line;
}

file_put_contents($ARCHIVO_SALIDA, implode("\n", $nuevas_lineas));
echo "Creado archivo final '$ARCHIVO_SALIDA' con el token de 24 horas.\n";

// Subida del archivo resultante a Dropbox
require 'vendor/autoload.php';  // Cargar el SDK de Dropbox

function subir_archivo_dropbox($archivo_salida) {
    global $APP_KEY_DROPBOX, $APP_SECRET_DROPBOX, $REFRESH_TOKEN_DROPBOX;

    $dbx = new \Dropbox\Client([
        'app_key' => $APP_KEY_DROPBOX,
        'app_secret' => $APP_SECRET_DROPBOX,
        'refresh_token' => $REFRESH_TOKEN_DROPBOX,
    ]);

    $file = fopen($archivo_salida, 'rb');
    $result = $dbx->files()->upload("/$archivo_salida", \Dropbox\WriteMode::overwrite(), $file);
    fclose($file);

    $shared_link = $dbx->sharing()->create_shared_link_with_settings("/$archivo_salida");
    $shared_link_url = str_replace('dl=0', 'dl=1', $shared_link->url);

    return $shared_link_url;
}

// Subir los archivos a Dropbox
$url_compartir_m3u = subir_archivo_dropbox($ARCHIVO_SALIDA);
$url_compartir_resultado = subir_archivo_dropbox($ARCHIVO_PROCESO_FINALIZADO);

echo "Archivo final subido a Dropbox: $url_compartir_m3u\n";
echo "Archivo resultado subido a Dropbox: $url_compartir_resultado\n";

?>
