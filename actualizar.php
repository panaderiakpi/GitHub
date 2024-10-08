<?php
use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;

// Datos necesarios
$AUTHORIZATION_BEARER = trim(file_get_contents('AUTHORIZATION_BEARER.ini')); // Authorization bearer leído de archivo
$ARCHIVO_ORIGEN_O_URL = "Movistar_base.m3u"; // Archivo de origen
$ARCHIVO_SALIDA = "btf.m3u"; // Archivo de salida
$APP_KEY_DROPBOX = "dlp5gp2wnpwpemw"; // API App key Dropbox
$APP_SECRET_DROPBOX = "629f1z2kzqmpqit"; // API App secret Dropbox
$REFRESH_TOKEN_DROPBOX = "2ZaA-C17U9cAAAAAAAAAATT4xMMzRLPty-8HNohgKI83BO7fYperM0Nn2HkHuPg9"; // API refresh token Dropbox
$ARCHIVO_PROCESO_FINALIZADO = "resultado.txt"; // Archivo de informe
$DROPBOX_API_URL = "https://content.dropboxapi.com/2/files/upload";
$DROPBOX_AUTH_URL = "https://api.dropboxapi.com/oauth2/token";

// Crear el informe base
file_put_contents($ARCHIVO_PROCESO_FINALIZADO, "Proceso en ejecución...\n");

$config = parse_ini_file('Cas_Id_claves.ini', true);
$Cas_Id_claves = $config['Cas_Id_claves'] ?? [];

// Obtener contenido M3U
$url = "https://ottcache.dof6.com/movistarplus/webplayer/DIFUSION/contents/epg";
$output_file_path = "Movistar_base.m3u";

$response = file_get_contents($url);
if ($response === false) {
    die("Error al realizar la solicitud.");
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

    $line1 = "#EXTINF:-1 tvg-logo=\"$logo\" group-logo=\"https://estatico.emisiondof6.com/recorte/m-DP/wpmos/MPLUS\" group-title=\"MOVISTAR +\",$nombre";
    $line2 = '#KODIPROP:inputstreamaddon=inputstream.adaptive';
    $line3 = '#KODIPROP:inputstream.adaptive.manifest_type=mpd';
    $line4 = '#KODIPROP:inputstream.adaptive.license_type=clearkey';
    $line5 = "#KODIPROP:inputstream.adaptive.license_key=$clave";
    $line6 = $mpd;

    $extracted_data[] = implode("\n", [$line1, $line2, $line3, $line4, $line5, $line6]);
}

// Verificar si hay cambios en el archivo
$new_content = implode("\n\n", $extracted_data);
if (file_exists($output_file_path)) {
    $existing_content = file_get_contents($output_file_path);
    if ($existing_content === $new_content) {
        echo "No hay cambios. El archivo no se ha modificado.\n";
    } else {
        file_put_contents($output_file_path, $new_content);
        echo "Información extraída y guardada en $output_file_path\n";
    }
} else {
    file_put_contents($output_file_path, $new_content);
    echo "Información extraída y guardada en $output_file_path\n";
}

// Validar y decodificar el token JWT
try {
    $jwt_data = json_decode(base64_decode(explode('.', $AUTHORIZATION_BEARER)[1] . '=='), true);
    $id_user = $jwt_data['accountNumber'] ?? 'NO EXISTE';
    $validez_token = (new DateTime())->setTimestamp($jwt_data['exp']);
} catch (Exception $e) {
    echo 'No se pudo decodificar el JSON o las claves: ', $e->getMessage();
    exit;
}

// Refrescar el token de 24 horas y procesar el archivo M3U
function procesar_m3u($archivo_origen, $archivo_salida, $token_diario) {
    if (!file_exists($archivo_origen)) {
        throw new Exception("Error: El archivo M3U de origen no existe.");
    }

    $lines = file($archivo_origen, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    $nuevo_contenido = [];

    foreach ($lines as $line) {
        if (strpos($line, "http") === 0) {
            $nuevo_contenido[] = "#KODIPROP:inputstream.adaptive.stream_headers=X-TCDN-token={$token_diario}";
        }
        $nuevo_contenido[] = $line;
    }

    // Sobrescribir el archivo en UTF-8
    file_put_contents($archivo_salida, "\xEF\xBB\xBF" . implode("\n", $nuevo_contenido));
    echo "Archivo final '$archivo_salida' creado con éxito.\n";
}

$token_diario = 'SIMULATED_24H_TOKEN'; // Simulación del token de 24 horas
procesar_m3u($ARCHIVO_ORIGEN_O_URL, $ARCHIVO_SALIDA, $token_diario);

// Obtener token de acceso de Dropbox utilizando el refresh token
function obtener_token_dropbox($app_key, $app_secret, $refresh_token) {
    global $DROPBOX_AUTH_URL;

    $client = new Client();
    try {
        $response = $client->post($DROPBOX_AUTH_URL, [
            'form_params' => [
                'grant_type' => 'refresh_token',
                'refresh_token' => $refresh_token,
                'client_id' => $app_key,
                'client_secret' => $app_secret,
            ]
        ]);
        $body = json_decode($response->getBody(), true);
        return $body['access_token'] ?? null;
    } catch (RequestException $e) {
        echo "Error al obtener el token de Dropbox: " . $e->getMessage();
        return null;
    }
}

// Subir archivo a Dropbox
function subir_a_dropbox($archivo_salida, $dropbox_token) {
    global $DROPBOX_API_URL;

    if (!$dropbox_token) {
        echo "Error: No se pudo obtener el token de Dropbox.\n";
        return false;
    }

    $client = new Client();
    try {
        $response = $client->post($DROPBOX_API_URL, [
            'headers' => [
                'Authorization' => "Bearer $dropbox_token",
                'Dropbox-API-Arg' => json_encode([
                    "path" => "/$archivo_salida",
                    "mode" => "overwrite",
                    "autorename" => true
                ]),
                'Content-Type' => 'application/octet-stream',
            ],
            'body' => fopen($archivo_salida, 'rb')
        ]);
        echo "Archivo subido a Dropbox correctamente.\n";
        return true;
    } catch (RequestException $e) {
        echo "Error al subir el archivo a Dropbox: " . $e->getMessage() . "\n";
        return false;
    }
}

// Obtener el token de acceso de Dropbox usando el refresh token
$dropbox_token = obtener_token_dropbox($APP_KEY_DROPBOX, $APP_SECRET_DROPBOX, $REFRESH_TOKEN_DROPBOX);

// Subir archivo a Dropbox
if (subir_a_dropbox($ARCHIVO_SALIDA, $dropbox_token)) {
    echo "Subida a Dropbox realizada con éxito.\n";
}

// Crear el informe final
$hora_actual = date('d/m/Y H:i:s');
$contenido = "Hora de la actualización: $hora_actual\n";
$contenido .= "Código de cliente: '$id_user'\n";
$contenido .= "Authorization bearer válido hasta: " . $validez_token->format('d/m/Y H:i:s') . "\n";
$contenido .= "Token de 24 horas de M+: '$token_diario'\n";
$contenido .= "Archivo de origen: '$ARCHIVO_ORIGEN_O_URL'\n";
$contenido .= "Archivo de destino: '$ARCHIVO_SALIDA'\n";
$contenido .= "Subida a Dropbox: " . ($dropbox_token ? "Sí" : "No") . "\n";

file_put_contents($ARCHIVO_PROCESO_FINALIZADO, $contenido);
echo "Informe generado correctamente en '$ARCHIVO_PROCESO_FINALIZADO'.\n";

?>
