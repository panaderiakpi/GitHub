<?php
use Dropbox\Client;
use Dropbox\WriteMode;
use Dropbox\Exception as DropboxException;

// Datos necesarios
$AUTHORIZATION_BEARER = trim(file_get_contents('AUTHORIZATION_BEARER.ini')); // Authorization bearer leído de archivo
$ARCHIVO_ORIGEN_O_URL = "Movistar_base.m3u"; // Archivo de origen
$ARCHIVO_SALIDA = "btf.m3u"; // Archivo de salida
$APP_KEY_DROPBOX = "dlp5gp2wnpwpemw"; // API App key Dropbox
$APP_SECRET_DROPBOX = "629f1z2kzqmpqit"; // API App secret Dropbox
$REFRESH_TOKEN_DROPBOX = "2ZaA-C17U9cAAAAAAAAAATT4xMMzRLPty-8HNohgKI83BO7fYperM0Nn2HkHuPg9"; // API refresh token Dropbox
$ARCHIVO_PROCESO_FINALIZADO = "resultado.txt"; // Archivo de informe

// Generar informe base
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

// Subir a Dropbox
function subir_a_dropbox($archivo_salida, $APP_KEY_DROPBOX, $APP_SECRET_DROPBOX, $REFRESH_TOKEN_DROPBOX) {
    try {
        $dbx = new Dropbox\Client($REFRESH_TOKEN_DROPBOX, $APP_KEY_DROPBOX);
        $file = fopen($archivo_salida, 'rb');
        $size = filesize($archivo_salida);
        $dbx->filesUpload(fread($file, $size), "/$archivo_salida", ['mode' => 'overwrite']);
        fclose($file);

        $shared_link = $dbx->createSharedLinkWithSettings("/$archivo_salida");
        return str_replace("?dl=0", "?dl=1", $shared_link['url']);
    } catch (DropboxException $e) {
        echo "Error al subir el archivo a Dropbox: " . $e->getMessage();
        return null;
    }
}

$dropbox_link = subir_a_dropbox($ARCHIVO_SALIDA, $APP_KEY_DROPBOX, $APP_SECRET_DROPBOX, $REFRESH_TOKEN_DROPBOX);
if ($dropbox_link) {
    echo "Archivo subido a Dropbox exitosamente. Enlace: $dropbox_link\n";
}

?>
