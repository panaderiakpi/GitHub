<?php

// Cargar datos necesarios
$AUTHORIZATION_BEARER = file_get_contents('AUTHORIZATION_BEARER.ini'); // Authorization bearer de M+
$ARCHIVO_ORIGEN_O_URL = "Movistar_base.m3u";  // Archivo .m3u de origen
$ARCHIVO_SALIDA = "btf.m3u";  // Nombre del archivo .m3u final
$APP_KEY_DROPBOX = "dlp5gp2wnpwpemw";  // API App key Dropbox
$APP_SECRET_DROPBOX = "629f1z2kzqmpqit";  // API App secret Dropbox
$REFRESH_TOKEN_DROPBOX = "2ZaA-C17U9cAAAAAAAAAATT4xMMzRLPty-8HNohgKI83BO7fYperM0Nn2HkHuPg9";  // API refresh token Dropbox
$DIAS_SIN_REFRESCO = 7;
$MENSAJE_TERMUX = "Algo fue mal en el proceso, sin plus en el salón";
$ARCHIVO_PROCESO_FINALIZADO = "resultado.txt";

// Guardar el mensaje de error inicial en un archivo
file_put_contents($ARCHIVO_PROCESO_FINALIZADO, $MENSAJE_TERMUX);

// Cargar el archivo INI de Cas_Id_claves
$Cas_Id_claves = parse_ini_file('Cas_Id_claves.ini', true)['Cas_Id_claves'];

// Obtener datos del endpoint
$url = "https://ottcache.dof6.com/movistarplus/webplayer/DIFUSION/contents/epg";
$response = file_get_contents($url);

if ($response !== false) {
    $data = json_decode($response, true);
    $extracted_data = ['#EXTM3U url-tvg="https://raw.githubusercontent.com/dracohe/CARLOS/master/guide_IPTV.xml,https://raw.githubusercontent.com/davidmuma/EPG_dobleM/master/guiatv_sincolor.xml.gz"'];

    foreach ($data as $entry) {
        $nombre = isset($entry['Nombre']) ? $entry['Nombre'] : "";
        $Cas_Id = isset($entry['CasId']) ? $entry['CasId'] : "";
        $mpd = isset($entry['PuntoReproduccion']) ? $entry['PuntoReproduccion'] : "";
        $logo = isset($entry['Logo']) ? $entry['Logo'] : "";
        $clave = isset($Cas_Id_claves[$Cas_Id]) ? $Cas_Id_claves[$Cas_Id] : "";

        $line1 = '#EXTINF:-1 tvg-logo="' . $logo . '" group-logo="https://estatico.emisiondof6.com/recorte/m-DP/wpmos/MPLUS" group-title="MOVISTAR +",' . $nombre;
        $line2 = '#KODIPROP:inputstreamaddon=inputstream.adaptive';
        $line3 = '#KODIPROP:inputstream.adaptive.manifest_type=mpd';
        $line4 = '#KODIPROP:inputstream.adaptive.license_type=clearkey';
        $line5 = '#KODIPROP:inputstream.adaptive.license_key=' . $clave;
        $line6 = $mpd;

        $extracted_data[] = implode("\n", [$line1, $line2, $line3, $line4, $line5, $line6]);
    }

    if (file_exists($ARCHIVO_ORIGEN_O_URL)) {
        $existing_content = file_get_contents($ARCHIVO_ORIGEN_O_URL);
        $new_content = implode("\n\n", $extracted_data);

        if ($existing_content === $new_content) {
            echo "No hay cambios. El archivo no se ha modificado.";
        } else {
            file_put_contents($ARCHIVO_ORIGEN_O_URL, $new_content);
            echo "Información extraída y guardada en Movistar_base.m3u";
        }
    } else {
        file_put_contents($ARCHIVO_ORIGEN_O_URL, implode("\n\n", $extracted_data));
        echo "Información extraída y guardada en Movistar_base.m3u";
    }
} else {
    echo "Error al realizar la solicitud.";
}

// Decodificación del JWT del bearer token
function decode_jwt($token) {
    $parts = explode(".", $token);
    return json_decode(base64_decode($parts[1]), true);
}

$jwt_data = decode_jwt($AUTHORIZATION_BEARER);
$id_dispositivo = isset($jwt_data['deviceId']) ? $jwt_data['deviceId'] : "NO EXISTE";
$id_user = isset($jwt_data['accountNumber']) ? $jwt_data['accountNumber'] : "NO EXISTE";
$segmento_cliente = isset($jwt_data['clientSegment']) ? $jwt_data['clientSegment'] : "NO EXISTE";
$sptoken = isset($jwt_data['sptoken']) ? $jwt_data['sptoken'] : "NO EXISTE";
$validez_token = DateTime::createFromFormat('U', $jwt_data['exp']);

echo "Código de cliente de M+ '{$id_user}' decodificado (segmento de cliente: {$segmento_cliente} sptoken: {$sptoken} e ID de dispositivo: {$id_dispositivo}).";
echo "Authorization bearer de M+ válido hasta: " . $validez_token->format('d/m/Y-H:i:s') . ".";

$fecha_actual = new DateTime();

// Función para obtener el device ID
function get_devices($id_user, $AUTHORIZATION_BEARER) {
    $headers = [
        'Accept: application/json, text/javascript, */*; q=0.01',
        'Authorization: Bearer ' . $AUTHORIZATION_BEARER,
        'User-Agent: Mozilla/5.0'
    ];

    $url = "https://auth.dof6.com/movistarplus/webplayer/accounts/{$id_user}/devices/?qspVersion=ssp";
    $opts = ['http' => ['method' => 'POST', 'header' => implode("\r\n", $headers)]];
    $context = stream_context_create($opts);
    return trim(file_get_contents($url, false, $context), '"');
}

// Función para obtener el token de 10 días
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
        'Content-Type: application/json',
        'X-Movistarplus-Deviceid: ' . $device_id,
        'Authorization: Bearer ' . $AUTHORIZATION_BEARER,
    ];

    $url = "https://clientservices.dof6.com/movistarplus/webplayer/sdp/mediaPlayers/{$device_id}/initData?qspVersion=ssp&version=8&status=default";
    $opts = [
        'http' => [
            'method' => 'POST',
            'header' => implode("\r\n", $headers),
            'content' => json_encode($data)
        ]
    ];
    $context = stream_context_create($opts);
    return json_decode(file_get_contents($url, false, $context), true)['accessToken'];
}

if ($fecha_actual >= $validez_token->modify('-' . (10 - $DIAS_SIN_REFRESCO) . ' days')) {
    $device_id = get_devices($id_user, $AUTHORIZATION_BEARER);
    $ten_days_token = get_ten_days_token($id_user, $AUTHORIZATION_BEARER, $device_id);

    $AUTHORIZATION_BEARER = $ten_days_token;
    file_put_contents('AUTHORIZATION_BEARER.ini', $AUTHORIZATION_BEARER);

    echo "Nuevo authorization bearer de 10 días de M+: {$ten_days_token}";
    echo "ID de dispositivo parseado: {$device_id}";
} else {
    echo "No es necesario renovar el token";
}

// Petición para obtener el token de 24 horas
function hacer_peticion_post($url, $datos, $token) {
    $headers = [
        'Authorization: Bearer ' . $token,
        'Content-Type: application/json'
    ];

    $opts = [
        'http' => [
            'method' => 'POST',
            'header' => implode("\r\n", $headers),
            'content' => json_encode($datos)
        ]
    ];

    $context = stream_context_create($opts);
    $result = file_get_contents($url, false, $context);
    return json_decode($result, true);
}

$url_api = "https://idserver.dof6.com/{$id_user}/devices/webplayer/cdn/token/refresh";
$datos_a_enviar = ['clave1' => 'valor1', 'clave2' => 'valor2'];
$resultado = hacer_peticion_post($url_api, $datos_a_enviar, $AUTHORIZATION_BEARER);

if ($resultado) {
    $my_token = $resultado['access_token'];
    echo "Token de 24 horas de M+: '{$my_token}' generado.";
} else {
    echo "La solicitud del token de 24 horas de M+ falló.";
}

// Procesar archivo origen o URL
function descargar_archivo($url) {
    $nombre_archivo = basename(parse_url($url, PHP_URL_PATH));
    $response = file_get_contents($url);

    if ($response !== false) {
        file_put_contents($nombre_archivo, $response);
        echo "Detectado y descargado archivo de origen remoto '{$nombre_archivo}'.";
        return $nombre_archivo;
    } else {
        echo "Entrada inválida. No se ha podido procesar la URL de origen.";
        exit;
    }
}

$parsed_url = parse_url($ARCHIVO_ORIGEN_O_URL);

if (isset($parsed_url['scheme']) && isset($parsed_url['host'])) {
    $nombre_archivo_leer = descargar_archivo($ARCHIVO_ORIGEN_O_URL);
} else if (file_exists($ARCHIVO_ORIGEN_O_URL)) {
    echo "Detectado archivo de origen local '{$ARCHIVO_ORIGEN_O_URL}'.";
    $nombre_archivo_leer = $ARCHIVO_ORIGEN_O_URL;
} else {
    echo "Entrada inválida. No se ha podido procesar el archivo de origen.";
    exit;
}

// Procesar el contenido del archivo .m3u
$lines = file($nombre_archivo_leer, FILE_IGNORE_NEW_LINES);
$nueva_linea = "#KODIPROP:inputstream.adaptive.stream_headers=X-TCDN-token={$my_token}\n";
$nuevas_lineas = [];

foreach ($lines as $line) {
    if (strpos($line, 'http') === 0) {
        $nuevas_lineas[] = $nueva_linea;
    }
    $nuevas_lineas[] = $line;
}

// Guardar el archivo de salida
if (!empty($ARCHIVO_SALIDA)) {
    file_put_contents($ARCHIVO_SALIDA, implode("\n", $nuevas_lineas));
    echo "Creado archivo final '{$ARCHIVO_SALIDA}' con el token de 24 horas de M+ si corresponde.";
} else {
    echo "No se ha especificado un archivo final de salida.";
    exit;
}

// Subir el archivo a Dropbox usando la API de Dropbox directamente (sin SDK)
function subir_archivo_a_dropbox($archivo_salida, $access_token) {
    $dropbox_url = 'https://content.dropboxapi.com/2/files/upload';
    $dropbox_args = json_encode([
        "path" => "/" . $archivo_salida,
        "mode" => "overwrite",
        "autorename" => true
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
    curl_setopt($ch, CURLOPT_PUT, true);
    curl_setopt($ch, CURLOPT_INFILE, $fp);
    curl_setopt($ch, CURLOPT_INFILESIZE, $size);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    $response = curl_exec($ch);
    curl_close($ch);
    fclose($fp);

    if ($response === false) {
        echo "Error al subir el archivo a Dropbox.";
    } else {
        // Crear un enlace compartido
        $response_data = json_decode($response, true);
        return $response_data;
    }
}

$access_token = $REFRESH_TOKEN_DROPBOX; // Usa el refresh token para acceder a la API de Dropbox
$url_compartir = subir_archivo_a_dropbox($ARCHIVO_SALIDA, $access_token);

if ($url_compartir) {
    echo "Archivo final subido a Dropbox con enlace directo: {$url_compartir}";
}

// Mensaje de éxito
echo "--- PROCEDIMIENTO FINALIZADO CON ÉXITO. ¡PAZ Y AMOR, Y EL PLUS PAL SALÓN! ---";
file_put_contents($ARCHIVO_PROCESO_FINALIZADO, "PROCEDIMIENTO FINALIZADO CON ÉXITO. ¡PAZ Y AMOR, Y EL PLUS PAL SALÓN!");

?>
