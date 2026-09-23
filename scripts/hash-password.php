<?php
declare(strict_types=1);

// Genera ADMIN_PASSWORD_HASH sin que la contraseña quede en ningún archivo.
// Uso (desde PowerShell o CMD): php scripts/hash-password.php
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

function leer_oculto(string $mensaje): string
{
    fwrite(STDOUT, $mensaje);
    if (DIRECTORY_SEPARATOR === '\\') {
        $cmd = 'powershell -NoProfile -Command "$p = Read-Host -AsSecureString; '
            . '[Runtime.InteropServices.Marshal]::PtrToStringAuto([Runtime.InteropServices.Marshal]::SecureStringToBSTR($p))"';
        $valor = rtrim((string)shell_exec($cmd), "\r\n");
    } else {
        system('stty -echo');
        $valor = rtrim((string)fgets(STDIN), "\r\n");
        system('stty echo');
        fwrite(STDOUT, PHP_EOL);
    }
    return $valor;
}

$clave = leer_oculto('Contraseña nueva: ');
$repetida = leer_oculto('Repetila: ');

if ($clave === '' || $clave !== $repetida) {
    fwrite(STDERR, "Las contraseñas no coinciden o están vacías.\n");
    exit(1);
}
if (strlen($clave) < 12) {
    fwrite(STDERR, "Usá al menos 12 caracteres.\n");
    exit(1);
}

echo PHP_EOL . 'ADMIN_PASSWORD_HASH=' . password_hash($clave, PASSWORD_BCRYPT, ['cost' => 12]) . PHP_EOL;
