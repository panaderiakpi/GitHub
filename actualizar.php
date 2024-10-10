<?php

// Datos necesarios
$AUTHORIZATION_BEARER = trim(file_get_contents('AUTHORIZATION_BEARER.ini'));
$ARCHIVO_ORIGEN_O_URL = "Movistar_base.m3u";
$ARCHIVO_SALIDA = "gitbtf.m3u";
$APP_KEY_DROPBOX = "dlp5gp2wnpwpemw"
$APP_SECRET_DROPBOX = "629f1z2kzqmpqit"
$REFRESH_TOKEN_DROPBOX = "0VakNqGAM6QAAAAAAAAAAaed3z_RGR57MDxB6iiwhDXt9VqEIP5BvSgwrHO2m9w4";
$ARCHIVO_PROCESO_FINALIZADO = "resultado.txt";
$DROPBOX_API_URL = "https://content.dropboxapi.com/2/files/upload";

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
file_put_contents($ARCHIVO_SALIDA, $new_content);
echo "Información extraída y guardada en $ARCHIVO_SALIDA\n";

// Subir archivo a Dropbox utilizando curl
function subir_archivo_dropbox($archivo_salida) {
    global $REFRESH_TOKEN_DROPBOX, $DROPBOX_API_URL;

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
    if ($file_content === false) {
        die("Error leyendo el contenido del archivo.");
    }

    $curl = curl_init($DROPBOX_API_URL);
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

$resultado_dropbox = subir_archivo_dropbox($ARCHIVO_SALIDA);
if (isset($resultado_dropbox['path_lower'])) {
    $url_dropbox = 'https://www.dropbox.com/scl/fi' . $resultado_dropbox['path_lower'] . '?dl=1';
    echo "Archivo subido a Dropbox exitosamente. Enlace de descarga: " . $url_dropbox . "\n";
} else {
    echo "Error al subir archivo a Dropbox.\n";
}

file_put_contents($ARCHIVO_PROCESO_FINALIZADO, "Proceso finalizado correctamente.\n", FILE_APPEND);
?>
