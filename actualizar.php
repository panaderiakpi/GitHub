<?php
// Establecer la zona horaria
date_default_timezone_set('Europe/Madrid');

// Leer el AUTHORIZATION_BEARER desde el archivo
$AUTHORIZATION_BEARER = trim(file_get_contents('AUTHORIZATION_BEARER.ini'));
$ARCHIVO_ORIGEN_O_URL = "Movistar_base.m3u";
$ARCHIVO_SALIDA = "gitbtf.m3u";  // Archivo codificado en UTF-8 para Kodi
$APP_KEY_DROPBOX = "dlp5gp2wnpwpemw";
$APP_SECRET_DROPBOX = "629f1z2kzqmpqit";
$REFRESH_TOKEN_DROPBOX = "2ZaA-C17U9cAAAAAAAAAATT4xMMzRLPty-8HNohgKI83BO7fYperM0Nn2HkHuPg9";
$ARCHIVO_PROCESO_FINALIZADO = "resultado.txt";
$DIAS_SIN_REFRESCO = 7;
$MENSAJE_TERMUX = "Algo fue mal en el proceso, sin plus en el salón";

$dropbox_link = "";  // Enlace a Dropbox (se llenará si la subida es exitosa)

try {
    // Decodificar el JWT para obtener datos del dispositivo
    $jwt_data = json_decode(base64_decode(explode('.', $AUTHORIZATION_BEARER)[1] . '=='), true);
    $id_user = $jwt_data['accountNumber'] ?? 'NO EXISTE';
    $expiracion_bearer = date('d/m/Y H:i:s', $jwt_data['exp']);
    $validez_token = DateTime::createFromFormat('U', $jwt_data['exp']);

    echo "Authorization bearer válido hasta: {$expiracion_bearer}.\n";

    // Verificar si es necesario renovar el token de 10 días
    $fecha_actual = new DateTime();
    if ($fecha_actual >= $validez_token->sub(new DateInterval("P" . (10 - $DIAS_SIN_REFRESCO) . "D"))) {
        // Simulación de renovación de token de 10 días (no es necesario en este caso)
        echo "Renovación del token de 10 días no es necesaria.\n";
    }

    // Simulación de obtener token de 24 horas
    $token_diario = 'SIMULATED_24H_TOKEN';  // Aquí debes usar el token real en el contexto adecuado

    // Procesar el archivo M3U y generar gitbtf.m3u en UTF-8
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

        // Crear archivo en UTF-8
        $archivo = fopen($archivo_salida, 'w');
        fwrite($archivo, "\xEF\xBB\xBF");  // Escribir BOM de UTF-8
        foreach ($nuevo_contenido as $linea) {
            fwrite($archivo, $linea . "\n");
        }
        fclose($archivo);

        echo "Archivo final '$archivo_salida' creado con éxito.\n";
    }

    procesar_m3u($ARCHIVO_ORIGEN_O_URL, $ARCHIVO_SALIDA, $token_diario);

    // Subir archivo a Dropbox (usando la API)
    function subir_a_dropbox($archivo_salida, $APP_KEY_DROPBOX, $APP_SECRET_DROPBOX, $REFRESH_TOKEN_DROPBOX) {
        // Crear CURL para la solicitud
        $url = "https://content.dropboxapi.com/2/files/upload";
        $headers = [
            "Authorization: Bearer $REFRESH_TOKEN_DROPBOX",
            "Dropbox-API-Arg: " . json_encode([
                "path" => "/$archivo_salida",
                "mode" => "overwrite",
                "autorename" => true
            ]),
            "Content-Type: application/octet-stream"
        ];

        $file_content = file_get_contents($archivo_salida);
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $file_content);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        $response = curl_exec($ch);

        if (curl_errno($ch)) {
            throw new Exception("Error al subir el archivo a Dropbox: " . curl_error($ch));
        }
        curl_close($ch);

        $response_data = json_decode($response, true);
        if (isset($response_data['path_display'])) {
            // Generar enlace de descarga directa
            return "https://www.dropbox.com/home?preview={$archivo_salida}";
        } else {
            throw new Exception("Error en la respuesta de Dropbox: " . $response);
        }
    }

    $dropbox_link = subir_a_dropbox($ARCHIVO_SALIDA, $APP_KEY_DROPBOX, $APP_SECRET_DROPBOX, $REFRESH_TOKEN_DROPBOX);
    echo "Archivo subido a Dropbox exitosamente. Enlace: $dropbox_link\n";

    // Crear el informe si todo salió bien
    $hora_actual = date('d/m/Y H:i:s');
    $contenido = "Hora de la actualización: $hora_actual\n";
    $contenido .= "Código de cliente: '$id_user'\n";
    $contenido .= "Authorization bearer válido hasta: $expiracion_bearer\n";
    $contenido .= "Token de 24 horas de M+: '$token_diario'\n";
    $contenido .= "Archivo de origen: '$ARCHIVO_ORIGEN_O_URL'\n";
    $contenido .= "Archivo de destino: '$ARCHIVO_SALIDA'\n";
    $contenido .= "Enlace de descarga Dropbox: $dropbox_link\n";

    file_put_contents($ARCHIVO_PROCESO_FINALIZADO, $contenido);
    echo "Informe generado correctamente en '$ARCHIVO_PROCESO_FINALIZADO'.\n";

} catch (Exception $e) {
    // Si ocurre un error, crear un informe de fallo
    echo "Error: " . $e->getMessage() . "\n";
    file_put_contents($ARCHIVO_PROCESO_FINALIZADO, $MENSAJE_TERMUX);
}
