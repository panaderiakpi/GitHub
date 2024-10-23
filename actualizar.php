<?php

// Configuración inicial
$AUTHORIZATION_BEARER = file_get_contents('AUTHORIZATION_BEARER.ini');
$ARCHIVO_ORIGEN_O_URL = "Movistar_base.m3u";
$ARCHIVO_SALIDA = "gitbtf.m3u";
$APP_KEY_DROPBOX = "dlp5gp2wnpwpemw";
$APP_SECRET_DROPBOX = "629f1z2kzqmpqit";
$REFRESH_TOKEN_DROPBOX = "2ZaA-C17U9cAAAAAAAAAATT4xMMzRLPty-8HNohgKI83BO7fYperM0Nn2HkHuPg9";
$DIAS_SIN_REFRESCO = 7;
$ARCHIVO_PROCESO_FINALIZADO = "resultado.txt";

// Función para obtener el token de 24 horas usando cURL
function obtener_token_24h($id_user, $AUTHORIZATION_BEARER) {
    $headers = [
        'Authorization: Bearer ' . $AUTHORIZATION_BEARER,
        'Content-Type' => 'application/json'
    ];

    // Asegúrate de que esta URL sea correcta para el servicio del token de 24 horas
    $url = "https://idserver.dof6.com/{$id_user}/devices/webplayer/cdn/token/refresh";
    
    // Ajusta los datos necesarios para la solicitud
    $data = json_encode(['clave1' => 'valor1', 'clave2' => 'valor2']);  

    // Inicializar cURL
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

    // Ejecutar la solicitud
    $response = curl_exec($ch);

    // Manejo de errores en la solicitud cURL
    if (curl_errno($ch)) {
        $error_msg = curl_error($ch);
        curl_close($ch);
        die("Error en la solicitud del token de 24 horas: $error_msg");
    }

    curl_close($ch);

    // Decodificar la respuesta
    $response_data = json_decode($response, true);

    if (isset($response_data['access_token'])) {
        return $response_data['access_token'];
    } else {
        die("Error: No se pudo obtener el token de 24 horas. Respuesta: " . json_encode($response_data));
    }
}

// Obtener el token de 24 horas
$token_24h = obtener_token_24h($id_user, $AUTHORIZATION_BEARER);
if (!$token_24h) {
    die("Error: No se pudo obtener el token de 24 horas.");
}

// Procesar el archivo .m3u y añadir el token de 24 horas
$lines = file($ARCHIVO_ORIGEN_O_URL, FILE_IGNORE_NEW_LINES);
$nuevas_lineas = [];
foreach ($lines as $line) {
    if (strpos($line, 'http') === 0) {
        $nuevas_lineas[] = "#KODIPROP:inputstream.adaptive.stream_headers=X-TCDN-token={$token_24h}";
    }
    $nuevas_lineas[] = $line;
}

file_put_contents($ARCHIVO_SALIDA, implode("\n", $nuevas_lineas));
echo "Archivo final '{$ARCHIVO_SALIDA}' generado correctamente.";

// Funciones para Dropbox (ya están correctas en tu script, no las modifico)

?>
