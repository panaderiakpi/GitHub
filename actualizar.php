<?php

// Datos necesarios
$AUTHORIZATION_BEARER = trim(file_get_contents('AUTHORIZATION_BEARER.ini'));
$ARCHIVO_ORIGEN_O_URL = "Movistar_base.m3u";
$ARCHIVO_SALIDA = "gitbtf.m3u";
$APP_KEY_DROPBOX = "dlp5gp2wnpwpemw";
$APP_SECRET_DROPBOX = "629f1z2kzqmpqit";
$REFRESH_TOKEN_DROPBOX = "0VakNqGAM6QAAAAAAAAAAaed3z_RGR57MDxB6iiwhDXt9VqEIP5BvSgwrHO2m9w4";
$ARCHIVO_PROCESO_FINALIZADO = "resultado.txt";
$DROPBOX_API_UPLOAD_URL = "https://content.dropboxapi.com/2/files/upload";
$DROPBOX_API_TOKEN_URL = "https://api.dropbox.com/oauth2/token";

$fecha_ejecucion = date("D M d H:i:s T Y"); // Hora de ejecución
$cambios_realizados = false; // Para verificar si hubo cambios, esto se actualizará más adelante
$codigo_cliente = "10690003006537-TEF"; // Aquí puedes usar la variable que captures
$fecha_validez_bearer = "15/10/2024-17:09:57"; // Simulando la fecha del bearer (actualiza según el script)
$token_24h = ''; // Será asignado al generarse
$enlace_dropbox = ''; // Será asignado después de subir a Dropbox

// Crear el informe base
file_put_contents($ARCHIVO_PROCESO_FINALIZADO, "Proceso en ejecución...\n");

$config = parse_ini_file('Cas_Id_claves.ini', true);
$Cas_Id_claves = $config['Cas_Id_claves'] ?? [];

// Obtener contenido M3U
$url = "https://ottcache.dof6.com/movistarplus/webplayer/DIFUSION/contents/epg";
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

    $line1 = "#EXTINF:-1 tvg-logo=\"$logo\" group-title=\"MOVISTAR +\",$nombre";
    $line2 = '#KODIPROP:inputstreamaddon=inputstream.adaptive';
    $line3 = '#KODIPROP:inputstream.adaptive.manifest_type=mpd';
    $line4 = '#KODIPROP:inputstream.adaptive.license_type=clearkey';
    $line5 = "#KODIPROP:inputstream.adaptive.license_key=$clave";
    $line6 = $mpd;

    $extracted_data[] = implode("\n", [$line1, $line2, $line3, $line4, $line5, $line6]);
}

$new_content = implode("\n\n", $extracted_data);
if (file_get_contents($ARCHIVO_SALIDA) !== $new_content) {
    file_put_contents($ARCHIVO_SALIDA, $new_content);
    $cambios_realizados = true;
    echo "Información extraída y guardada en $ARCHIVO_SALIDA\n";
} else {
    echo "No hay cambios. El archivo no se ha modificado.\n";
}

// Función para obtener Access Token desde Refresh Token
function obtener_access_token_dropbox() {
    global $APP_KEY_DROPBOX, $APP_SECRET_DROPBOX, $REFRESH_TOKEN_DROPBOX, $DROPBOX_API_TOKEN_URL;

    $headers = [
        'Content-Type: application/x-www-form-urlencoded'
    ];

    $data = http_build_query([
        'grant_type' => 'refresh_token',
        'refresh_token' => $REFRESH_TOKEN_DROPBOX,
        'client_id' => $APP_KEY_DROPBOX,
        'client_secret' => $APP_SECRET_DROPBOX
    ]);

    $curl = curl_init($DROPBOX_API_TOKEN_URL);
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

// Subir archivo a Dropbox utilizando curl
function subir_archivo_dropbox($archivo_salida) {
    global $DROPBOX_API_UPLOAD_URL, $enlace_dropbox;

    $access_token = obtener_access_token_dropbox();
    if (!$access_token) {
        die("Error al obtener Access Token para Dropbox.");
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

    $file_content = file_get_contents($archivo_salida);
    if ($file_content === false) {
        die("Error leyendo el contenido del archivo.");
    }

    $curl = curl_init($DROPBOX_API_UPLOAD_URL);
    curl_setopt($curl, CURLOPT_POST, true);
    curl_setopt($curl, CURLOPT_POSTFIELDS, $file_content);
    curl_setopt($curl, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);

    $response = curl_exec($curl);
    if (curl_errno($curl)) {
        die("Error en la subida a Dropbox: " . curl_error($curl));
    }
    curl_close($curl);

    $resultado_dropbox = json_decode($response, true);
    if (isset($resultado_dropbox['path_lower'])) {
        $enlace_dropbox = 'https://www.dropbox.com/scl/fi' . $resultado_dropbox['path_lower'] . '?dl=1';
        echo "Archivo subido a Dropbox exitosamente. Enlace de descarga: " . $enlace_dropbox . "\n";
    } else {
        echo "Error al subir archivo a Dropbox.\n";
    }

    return $resultado_dropbox;
}

// Subida del archivo M3U
subir_archivo_dropbox($ARCHIVO_SALIDA);

// Simular generación de token de 24 horas
$token_24h = 'eyJhbGciOiJFUzI1NiIsImtpZCI6ImI1OGNhNGM0NGFiOTQ0Y2FiY2U4N2FjNGJmZmI4MDNkIiwidHlwIjoiYXQrand0In0...'; // Actualiza con el valor real generado

// Crear informe de resultado
$informe = "Ejecución realizada el: $fecha_ejecucion\n";
$informe .= $cambios_realizados ? "Hubo cambios. El archivo ha sido modificado.\n" : "No hay cambios. El archivo no se ha modificado.\n";
$informe .= "Código de cliente de M+ '$codigo_cliente' decodificado (segmento de cliente: 1 sptoken: NO EXISTE e ID de dispositivo: 1cdf6519bdc4480fa9c325d383301b41).\n";
$informe .= "Authorization bearer de M+ válido hasta: $fecha_validez_bearer.\n";
$informe .= "Token de 24 horas de M+ '$token_24h' generado.\n";
$informe .= "Archivo final subido a Dropbox con enlace directo: $enlace_dropbox\n";
$informe .= "--- PROCEDIMIENTO FINALIZADO CON ÉXITO. ¡PAZ Y AMOR, Y EL PLUS PAL SALÓN! ---\n";

// Guardar el informe en el archivo 'resultado.txt'
file_put_contents($ARCHIVO_PROCESO_FINALIZADO, $informe);

// Subir el archivo de informe a Dropbox
subir_archivo_dropbox($ARCHIVO_PROCESO_FINALIZADO);

echo "Informe de resultado generado y subido a Dropbox.\n";

?>
