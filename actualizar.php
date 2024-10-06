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

// Función para realizar una solicitud POST cURL
function hacer_peticion_post($url, $headers, $data, $isFile = false) {
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

    if ($isFile) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
    } else {
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($data));
    }

    $response = curl_exec($ch);
    
    if (curl_errno($ch)) {
        echo "Error en la solicitud: " . curl_error($ch) . "\n";
        curl_close($ch);
        return null;
    }
    
    $http_status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    if ($http_status != 200) {
        echo "Error en la solicitud: Código de estado HTTP $http_status\n";
        echo "Respuesta del servidor: $response\n";
        curl_close($ch);
        return null;
    }
    
    curl_close($ch);
    return json_decode($response, true);
}

// Función para subir un archivo a Dropbox
function subir_a_dropbox($archivo_salida, $dropbox_token) {
    $url_upload = "https://content.dropboxapi.com/2/files/upload";
    $path = '/' . basename($archivo_salida); // Nombre del archivo en Dropbox
    
    $headers = [
        "Authorization: Bearer $dropbox_token",
        "Content-Type: application/octet-stream",
        "Dropbox-API-Arg: " . json_encode([
            "path" => $path,
            "mode" => "overwrite",
            "autorename" => true,
            "mute" => false
        ])
    ];

    // Leer el contenido del archivo
    $file_content = file_get_contents($archivo_salida);
    if ($file_content === false) {
        die("Error: No se pudo leer el archivo para subirlo.");
    }
    
    $response = hacer_peticion_post($url_upload, $headers, $file_content, true);
    
    if ($response && isset($response['path_lower'])) {
        return $response['path_lower'];
    } else {
        echo "Error: No se pudo subir el archivo a Dropbox.\n";
        return null;
    }
}

// Función para generar un enlace compartido de Dropbox
function crear_enlace_dropbox($archivo_path, $dropbox_token) {
    $url_share = "https://api.dropboxapi.com/2/sharing/create_shared_link_with_settings";
    
    $headers = [
        "Authorization: Bearer $dropbox_token",
        "Content-Type: application/json"
    ];
    
    $data = [
        "path" => $archivo_path,
        "settings" => [
            "requested_visibility" => "public"
        ]
    ];

    $response = hacer_peticion_post($url_share, $headers, $data);
    
    if ($response && isset($response['url'])) {
        // Modificar el enlace para que sea de descarga directa
        $shared_link_url = str_replace('dl=0', 'dl=1', $response['url']);
        return $shared_link_url;
    } else {
        echo "Error al crear el enlace compartido en Dropbox.\n";
        return null;
    }
}

// Función principal para procesar el archivo y generar el enlace de Dropbox
function procesar_archivo_y_generar_enlace($archivo_salida, $dropbox_token) {
    // Subir el archivo a Dropbox
    $archivo_path = subir_a_dropbox($archivo_salida, $dropbox_token);
    
    if ($archivo_path) {
        // Crear enlace compartido
        $dropbox_link = crear_enlace_dropbox($archivo_path, $dropbox_token);
        
        if ($dropbox_link) {
            echo "Enlace de descarga de Dropbox: $dropbox_link\n";
            return $dropbox_link;
        } else {
            echo "Error: No se pudo generar el enlace de descarga de Dropbox.\n";
        }
    } else {
        echo "Error: No se pudo subir el archivo a Dropbox.\n";
    }
    return null;
}

// Función para obtener access token de Dropbox
function obtener_access_token_dropbox($app_key, $app_secret, $refresh_token) {
    $url = "https://api.dropboxapi.com/oauth2/token";
    
    $headers = [
        "Authorization: Basic " . base64_encode("$app_key:$app_secret"),
        "Content-Type: application/x-www-form-urlencoded"
    ];
    
    $data = [
        "grant_type" => "refresh_token",
        "refresh_token" => $refresh_token
    ];
    
    $response = hacer_peticion_post($url, $headers, $data);
    
    if ($response && isset($response['access_token'])) {
        return $response['access_token'];
    } else {
        echo "Error: No se pudo obtener el token de acceso de Dropbox.\n";
        return null;
    }
}

// Datos de autenticación de Dropbox
$APP_KEY_DROPBOX = "dlp5gp2wnpwpemw";  // Tu App Key de Dropbox
$APP_SECRET_DROPBOX = "629f1z2kzqmpqit";  // Tu App Secret de Dropbox
$REFRESH_TOKEN_DROPBOX = "2ZaA-C17U9cAAAAAAAAAATT4xMMzRLPty-8HNohgKI83BO7fYperM0Nn2HkHuPg9";  // Tu Refresh Token de Dropbox

// Obtener un nuevo access token de Dropbox
$dropbox_access_token = obtener_access_token_dropbox($APP_KEY_DROPBOX, $APP_SECRET_DROPBOX, $REFRESH_TOKEN_DROPBOX);

if ($dropbox_access_token) {
    // Procesar el archivo y generar el enlace de descarga
    $archivo_salida = "gitBtf.m3u";  // Archivo a subir
    procesar_archivo_y_generar_enlace($archivo_salida, $dropbox_access_token);
} else {
    echo "Error: No se pudo obtener el token de acceso para Dropbox.\n";
}

?>
