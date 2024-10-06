<?php
// Establecer la zona horaria a Europa/Madrid
date_default_timezone_set('Europe/Madrid');

// Función para cargar el token desde AUTHORIZATION_BEARER.ini
function cargar_autorizacion($archivo_ini) {
    if (!file_exists($archivo_ini)) {
        die("Error: El archivo $archivo_ini no existe.");
    }

    $token = trim(file_get_contents($archivo_ini));
    if (empty($token)) {
        die("Error: El archivo $archivo_ini está vacío o el token no es válido.");
    }

    return $token;
}

// Decodificar el token JWT y extraer el código de cliente, fecha de expiración, etc.
function decodificar_jwt($jwt_token) {
    $partes = explode('.', $jwt_token);
    if (count($partes) < 2) {
        die("Error: El token JWT no tiene el formato esperado.");
    }

    $payload_base64 = str_replace(['-', '_'], ['+', '/'], $partes[1]);
    $padding = strlen($payload_base64) % 4;
    if ($padding) {
        $payload_base64 .= str_repeat('=', 4 - $padding);
    }

    $payload = base64_decode($payload_base64);
    if (!$payload) {
        die("Error: No se pudo decodificar el token JWT.");
    }

    $datos = json_decode($payload, true);
    return $datos;
}

// Función para verificar si el token expira en 2 días o menos
function token_caducado_pronto($jwt_data) {
    $expiracion = $jwt_data['exp'];
    $time_restante = $expiracion - time(); // Tiempo restante en segundos
    return $time_restante < (2 * 24 * 60 * 60); // 2 días en segundos
}

// Función para subir un archivo a Dropbox y generar un enlace compartido con "dl=1"
function subir_a_dropbox_y_generar_enlace($archivo_salida, $dropbox_token) {
    try {
        // Subir el archivo a Dropbox
        $url_upload = "https://content.dropboxapi.com/2/files/upload";
        $path = '/' . basename($archivo_salida);

        $headers_upload = [
            "Authorization: Bearer $dropbox_token",
            "Content-Type: application/octet-stream",
            "Dropbox-API-Arg: " . json_encode(["path" => $path, "mode" => "overwrite"])
        ];

        $archivo_contenido = file_get_contents($archivo_salida);
        $ch_upload = curl_init($url_upload);

        curl_setopt($ch_upload, CURLOPT_POST, true);
        curl_setopt($ch_upload, CURLOPT_POSTFIELDS, $archivo_contenido);
        curl_setopt($ch_upload, CURLOPT_HTTPHEADER, $headers_upload);
        curl_setopt($ch_upload, CURLOPT_RETURNTRANSFER, true);

        $response_upload = curl_exec($ch_upload);
        curl_close($ch_upload);

        if (!$response_upload) {
            echo "Error al subir el archivo a Dropbox.\n";
            return null;
        }

        // Crear el enlace compartido
        $url_share = "https://api.dropboxapi.com/2/sharing/create_shared_link_with_settings";
        $headers_share = [
            "Authorization: Bearer $dropbox_token",
            "Content-Type: application/json"
        ];
        $data_share = ["path" => $path];

        $ch_share = curl_init($url_share);
        curl_setopt($ch_share, CURLOPT_POST, true);
        curl_setopt($ch_share, CURLOPT_POSTFIELDS, json_encode($data_share));
        curl_setopt($ch_share, CURLOPT_HTTPHEADER, $headers_share);
        curl_setopt($ch_share, CURLOPT_RETURNTRANSFER, true);

        $response_share = curl_exec($ch_share);
        curl_close($ch_share);

        $share_data = json_decode($response_share, true);
        if (!isset($share_data['url'])) {
            echo "Error al obtener el enlace compartido de Dropbox.\n";
            return null;
        }

        // Forzar el enlace a descarga directa con el formato específico
        $shared_link_url = $share_data['url'];
        $shared_link_url = str_replace('dl=0', 'dl=1', $shared_link_url);
        $shared_link_url = str_replace('?dl=1', '', $shared_link_url) . "?rlkey=n3kzof4a8exgoilhuq2i4gtgh&st=mhdbl9q3&dl=1";
        return $shared_link_url;

    } catch (Exception $e) {
        echo "Error al subir el archivo a Dropbox: " . $e->getMessage() . "\n";
        return null;
    }
}

// Función para generar el informe
function generar_informe($id_user, $expiracion_bearer, $token_diario, $archivo_origen, $archivo_salida, $dropbox_link, $token_actualizado) {
    $informe = "resultado.txt";
    $hora_actual = date('d/m/Y H:i:s');  // Obtener la hora actual en formato d/m/Y H:i:s

    // Crear contenido del informe
    $contenido = "Hora de la actualización: $hora_actual\n";
    $contenido .= "Código de cliente: '$id_user'\n";
    $contenido .= "Authorization bearer válido hasta: $expiracion_bearer\n";
    $contenido .= "Token de 24 horas de M+: '$token_diario'\n";
    $contenido .= "Archivo de origen: '$archivo_origen'\n";
    $contenido .= "Archivo de destino: '$archivo_salida'\n";
    $contenido .= "Enlace de descarga Dropbox: $dropbox_link\n";
    if ($token_actualizado) {
        $contenido .= "El token de 10 días fue actualizado.\n";
    } else {
        $contenido .= "No fue necesario actualizar el token.\n";
    }
    $contenido .= "--- PROCEDIMIENTO FINALIZADO CON ÉXITO ---\n";

    // Sobrescribir el contenido en el archivo resultado.txt
    file_put_contents($informe, $contenido);
    echo "Informe generado: $informe\n";
}

// Iniciar el proceso completo
$AUTHORIZATION_BEARER = cargar_autorizacion('AUTHORIZATION_BEARER.ini'); // Leer el token desde el archivo .ini

// Decodificar el token JWT para obtener información del cliente
$jwt_data = decodificar_jwt($AUTHORIZATION_BEARER);
$id_user = $jwt_data['accountNumber'] ?? 'Desconocido';
$expiracion_bearer = date('d/m/Y H:i:s', $jwt_data['exp']);

// Verificar si el token está cerca de caducar y refrescarlo si es necesario
$token_actualizado = false;
if (token_caducado_pronto($jwt_data)) {
    echo "El token está próximo a caducar, refrescando el token...\n";
    $AUTHORIZATION_BEARER = obtener_token_diario($AUTHORIZATION_BEARER);
    $token_actualizado = true;
}

// Obtener el token diario
$token_diario = obtener_token_diario($AUTHORIZATION_BEARER);

// Procesar el archivo M3U y asegurarse que se guarde en UTF-8
$archivo_origen = "Movistar_base.m3u";
$archivo_salida = "gitBtf.m3u";

// Obtener un nuevo access token de Dropbox usando el refresh token
$APP_KEY_DROPBOX = "dlp5gp2wnpwpemw";
$APP_SECRET_DROPBOX = "629f1z2kzqmpqit";
$REFRESH_TOKEN_DROPBOX = "2ZaA-C17U9cAAAAAAAAAATT4xMMzRLPty-8HNohgKI83BO7fYperM0Nn2HkHuPg9";

$dropbox_access_token = obtener_access_token_dropbox($APP_KEY_DROPBOX, $APP_SECRET_DROPBOX, $REFRESH_TOKEN_DROPBOX);

// Subir el archivo generado a Dropbox y generar el enlace
$dropbox_link = "";
if ($dropbox_access_token) {
    $dropbox_link = subir_a_dropbox_y_generar_enlace($archivo_salida, $dropbox_access_token);
    if (!$dropbox_link) {
        echo "Error: No se pudo generar el enlace de Dropbox.\n";
    }
} else {
    echo "Error: No se pudo obtener el token de acceso para Dropbox.\n";
}

// Generar informe con el enlace y estado del token
generar_informe($id_user, $expiracion_bearer, $token_diario, $archivo_origen, $archivo_salida, $dropbox_link, $token_actualizado);

?>
