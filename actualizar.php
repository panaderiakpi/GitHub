<?php

require 'vendor/autoload.php';  // Asegúrate de tener el SDK de Dropbox instalado

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

// Realizar una petición POST utilizando cURL con los headers y datos proporcionados
function hacer_peticion_post($url, $datos, $headers) {
    $curl = curl_init($url);

    curl_setopt($curl, CURLOPT_POST, true);
    curl_setopt($curl, CURLOPT_POSTFIELDS, json_encode($datos)); // Enviar los datos como JSON
    curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($curl, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($curl, CURLOPT_HTTP_VERSION, CURL_HTTP_VERSION_1_1); // Forzar HTTP/1.1
    curl_setopt($curl, CURLOPT_ENCODING, '');  // Desactivar chunked encoding
    curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, false); // Ignorar la verificación SSL si causa problemas

    $respuesta = curl_exec($curl);

    if (curl_errno($curl)) {
        echo "Error en la solicitud POST: " . curl_error($curl) . "\n";
        curl_close($curl);
        return null;
    }

    $http_status = curl_getinfo($curl, CURLINFO_HTTP_CODE);
    if ($http_status != 200) {
        echo "Error en la solicitud POST. Código de estado HTTP: $http_status\n";
        echo "Respuesta del servidor: " . $respuesta . "\n";  // Imprimir respuesta completa para más detalles
        curl_close($curl);
        return null;
    }

    curl_close($curl);
    return json_decode($respuesta, true);
}

// Obtener el token diario desde la API
function obtener_token_diario($authorization_bearer) {
    $url_token = "https://idserver.dof6.com/00QSp000006KYmsMAG-L/devices/webplayer/cdn/token/refresh";
    
    $headers = [
        'Authorization: Bearer ' . $authorization_bearer,
        'Accept: */*',
        'Accept-Encoding: gzip, deflate, br',
        'Accept-Language: es-ES,es;q=0.9',
        'Origin: https://ver.movistarplus.es',
        'Referer: https://ver.movistarplus.es/canales?id_perfil=SUSCRI2&suscripcion=UT-DO0003,UT-MPARFU&ui=MPLUS&demarcation=0',
        'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/127.0.0.0 Safari/537.36 OPR/113.0.0.0',
        'Content-Type: application/json'
    ];

    $datos_a_enviar = (object)[]; // Cuerpo vacío en formato JSON

    $respuesta_token = hacer_peticion_post($url_token, $datos_a_enviar, $headers);

    if ($respuesta_token && isset($respuesta_token['access_token'])) {
        return $respuesta_token['access_token'];
    } else {
        die("Error: No se pudo obtener el token diario.");
    }
}

// Procesar el archivo M3U añadiendo el token diario y forzando la codificación UTF-8
function procesar_m3u($archivo_origen, $archivo_salida, $token_diario) {
    if (!file_exists($archivo_origen)) {
        die("Error: El archivo M3U de origen no existe.");
    }

    $lines = file($archivo_origen, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    $archivo = fopen($archivo_salida, 'w');

    if ($archivo === false) {
        die("Error: No se pudo abrir el archivo de salida.");
    }

    // Escribir el BOM de UTF-8 para asegurar que el archivo esté codificado en UTF-8
    fwrite($archivo, "\xEF\xBB\xBF");

    foreach ($lines as $line) {
        if (strpos($line, "http") === 0) {
            fwrite($archivo, "#KODIPROP:inputstream.adaptive.stream_headers=X-TCDN-token=$token_diario\n");
        }
        fwrite($archivo, $line . "\n");
    }

    fclose($archivo);
    echo "Archivo final '$archivo_salida' creado con éxito.\n";
}

// Generar el archivo de informe con la información solicitada
function generar_informe($id_user, $expiracion_bearer, $token_diario, $archivo_origen, $archivo_salida) {
    $informe = "resultado.txt";
    $hora_actual = date('d/m/Y H:i:s');
    
    // Crear contenido del informe
    $contenido = "Hora de la actualización: $hora_actual\n";
    $contenido .= "Código de cliente: '$id_user'\n";
    $contenido .= "Authorization bearer válido hasta: $expiracion_bearer\n";
    $contenido .= "Token de 24 horas de M+: '$token_diario'\n";
    $contenido .= "Archivo de origen: '$archivo_origen'\n";
    $contenido .= "Archivo de destino: '$archivo_salida'\n";
    $contenido .= "--- PROCEDIMIENTO FINALIZADO CON ÉXITO. ¡PAZ Y AMOR, Y EL PLUS PAL SALÓN! ---\n";

    // Sobrescribir el contenido en el archivo resultado.txt
    file_put_contents($informe, $contenido);
    echo "Informe generado: $informe\n";
}

// Subir archivos a Dropbox
function subir_a_dropbox($archivo_local, $nombre_remoto, $dropbox_token) {
    $dropbox_url = 'https://content.dropboxapi.com/2/files/upload';
    $headers = [
        "Authorization: Bearer $dropbox_token",
        "Content-Type: application/octet-stream",
        "Dropbox-API-Arg: " . json_encode([
            "path" => "/$nombre_remoto",
            "mode" => "overwrite",
            "autorename" => false
        ])
    ];

    $file_content = file_get_contents($archivo_local);

    $ch = curl_init($dropbox_url);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $file_content);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

    $response = curl_exec($ch);
    if (curl_errno($ch)) {
        die("Error al subir el archivo a Dropbox: " . curl_error($ch));
    }

    $http_status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    if ($http_status != 200) {
        die("Error en la subida a Dropbox. Código HTTP: $http_status\nRespuesta: $response");
    }

    curl_close($ch);
    echo "Archivo '$nombre_remoto' subido correctamente a Dropbox.\n";
}

// Iniciar el proceso completo
$AUTHORIZATION_BEARER = cargar_autorizacion('AUTHORIZATION_BEARER.ini'); // Leer el token desde el archivo .ini

// Decodificar el token JWT para obtener información del cliente
$jwt_data = decodificar_jwt($AUTHORIZATION_BEARER);
$id_user = $jwt_data['accountNumber'] ?? 'Desconocido';
$expiracion_bearer = date('d/m/Y H:i:s', $jwt_data['exp']);

// Obtener el token diario
$token_diario = obtener_token_diario($AUTHORIZATION_BEARER);

// Procesar el archivo M3U y asegurarse que se guarde en UTF-8
$archivo_origen = "Movistar_base.m3u";
$archivo_salida = "gifbtf.m3u";
procesar_m3u($archivo_origen, $archivo_salida, $token_diario);

// Generar informe
generar_informe($id_user, $expiracion_bearer, $token_diario, $archivo_origen, $archivo_salida);

// Subir archivos a Dropbox
$APP_KEY_DROPBOX = "dlp5gp2wnpwpemw"; # API App key Dropbox (OPCIONAL)
$APP_SECRET_DROPBOX = "629f1z2kzqmpqit"; # API App secret Dropbox (OPCIONAL)
$REFRESH_TOKEN_DROPBOX = "2ZaA-C17U9cAAAAAAAAAATT4xMMzRLPty-8HNohgKI83BO7fYperM0Nn2HkHuPg9";  # API refresh token Dropbox (OPCIONAL)
$dropbox_token = 'YOUR_DROPBOX_ACCESS_TOKEN'; // Reemplaza esto con tu token de acceso de Dropbox

subir_a_dropbox($archivo_salida, "gifbtf.m3u", $dropbox_token);
subir_a_dropbox("resultado.txt", "resultado.txt", $dropbox_token);

?>
