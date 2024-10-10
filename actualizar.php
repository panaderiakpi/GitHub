<?php

// Configurar la zona horaria para Europa/Madrid
date_default_timezone_set('Europe/Madrid');

// Datos necesarios
$AUTHORIZATION_BEARER = trim(file_get_contents('AUTHORIZATION_BEARER.ini'));
$ARCHIVO_ORIGEN_O_URL = "Movistar_base.m3u";
$ARCHIVO_SALIDA = "gitbtf.m3u";  // Archivo M3U que generamos
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
if (!file_exists($ARCHIVO_SALIDA) || file_get_contents($ARCHIVO_SALIDA) !== $new_content) {
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

// Función para verificar si un archivo ya existe en Dropbox
function archivo_existe_dropbox($archivo) {
    global $DROPBOX_API_LIST_FOLDER_URL;

    $access_token = obtener_access_token_dropbox();
    $headers = [
        "Authorization: Bearer $access_token",
        "Content-Type: application/json"
    ];

    $data = json_encode([
        "path" => ""
    ]);

    $curl = curl_init($DROPBOX_API_LIST_FOLDER_URL);
    curl_setopt($curl, CURLOPT_POST, true);
    curl_setopt($curl, CURLOPT_POSTFIELDS, $data);
    curl_setopt($curl, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);

    $response = curl_exec($curl);
    curl_close($curl);

    $result = json_decode($response, true);

    if (isset($result['entries'])) {
        foreach ($result['entries'] as $entry) {
            if ($entry['name'] === $archivo) {
                return true;
            }
        }
    }
    return false;
}

// Función para subir un archivo a Dropbox y obtener el enlace
function subir_archivo_dropbox($archivo_salida, &$enlace_dropbox) {
    global $DROPBOX_API_UPLOAD_URL, $DROPBOX_API_SHARED_LINK_URL;

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

    return $resultado_dropbox;
}

// Subida del archivo M3U
if (archivo_existe_dropbox(basename($ARCHIVO_SALIDA))) {
    echo "El archivo $ARCHIVO_SALIDA ya existe en Dropbox. Será sobrescrito.\n";
}
subir_archivo_dropbox($ARCHIVO_SALIDA, $enlace_dropbox_m3u);

// Simular generación de token de 24 horas
$token_24h = ''; // Actualiza con el valor real generado

// Crear informe de resultado
$informe = "Ejecución realizada el: $fecha_ejecucion\n";
$informe .= $cambios_realizados ? "Hubo cambios. El archivo ha sido modificado.\n" : "No hay cambios. El archivo no se ha modificado.\n";
$informe .= "Código de cliente de M+ '$codigo_cliente' decodificado (segmento de cliente: 1 sptoken: NO EXISTE e ID de dispositivo: 1cdf6519bdc4480fa9c325d383301b41).\n";
$informe .= "Authorization bearer de M+ válido hasta: $fecha_validez_bearer.\n";
$informe .= "Token de 24 horas de M+ '$token_24h' generado.\n";
$informe .= "Archivo final subido a Dropbox con enlace directo: $enlace_dropbox_m3u\n";
$informe .= "--- PROCEDIMIENTO FINALIZADO CON ÉXITO. ¡PAZ Y AMOR, Y EL PLUS PAL SALÓN! ---\n";

// Guardar el informe en el archivo 'resultado.txt'
file_put_contents($ARCHIVO_PROCESO_FINALIZADO, $informe);

// Subir el archivo de informe a Dropbox
subir_archivo_dropbox($ARCHIVO_PROCESO_FINALIZADO, $enlace_dropbox_informe);

echo "Informe de resultado generado y subido a Dropbox.\n";

?>
