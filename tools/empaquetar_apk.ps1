# Empaqueta ADARA como APK firmado (esquemas V1 y V2) con Bubblewrap.
# El archivo que hoy se descarga y Android no instala suele ser un APK sin
# firma V1, un ZIP renombrado o una página HTML guardada con extensión .apk.
#
# Requisitos en esta PC (una sola vez):
#   1. JDK 17 (java -version).
#   2. Node.js 18 o superior.
#   3. npm install -g @bubblewrap/cli
#   4. Android SDK. La primera vez Bubblewrap pregunta y lo descarga.
#   5. La PWA publicada en HTTPS. Un teléfono no instala una TWA apuntando a http://localhost.
#
# Uso:
#   powershell -ExecutionPolicy Bypass -File tools\empaquetar_apk.ps1 -ManifestUrl "https://tudominio.com/manifest.webmanifest"
#
# El APK queda en tools\bubblewrap\app-release-signed.apk (o el nombre que imprima Bubblewrap).
# Cópialo a assets\descargas\adara.apk y súbelo. assets\descargas\.htaccess ya manda
# Content-Type application/vnd.android.package-archive.
#
# Comprobar la firma antes de subir:
#   apksigner verify --verbose tools\bubblewrap\app-release-signed.apk
#   Tiene que decir "Verified using v1 scheme: true" y "Verified using v2 scheme: true".
param(
    [Parameter(Mandatory = $true)]
    [string] $ManifestUrl,
    [string] $Paquete = 'com.adara.erp'
)

$ErrorActionPreference = 'Stop'
$raiz = Split-Path -Parent $PSScriptRoot
$destino = Join-Path $raiz 'tools\bubblewrap'
New-Item -ItemType Directory -Force -Path $destino | Out-Null
Set-Location $destino

if (-not (Get-Command bubblewrap -ErrorAction SilentlyContinue)) {
    Write-Host 'Instala Bubblewrap: npm install -g @bubblewrap/cli'
    exit 1
}

if (-not (Test-Path (Join-Path $destino 'twa-manifest.json'))) {
    bubblewrap init --manifest $ManifestUrl --directory $destino --packageId $Paquete
}

bubblewrap update --manifest $ManifestUrl
bubblewrap build

$apk = Get-ChildItem -Path $destino -Filter '*.apk' | Sort-Object LastWriteTime -Descending | Select-Object -First 1
if (-not $apk) {
    Write-Host 'Bubblewrap no dejó un APK. Revisa el log de arriba.'
    exit 1
}

$sdk = Join-Path $env:LOCALAPPDATA 'Android\Sdk\build-tools'
$apksigner = Get-ChildItem -Path $sdk -Filter 'apksigner.bat' -Recurse -ErrorAction SilentlyContinue |
    Sort-Object FullName -Descending | Select-Object -First 1
if ($apksigner) {
    & $apksigner.FullName verify --verbose $apk.FullName
} else {
    Write-Host "APK generado: $($apk.FullName)"
    Write-Host 'No encontré apksigner. Instala Android SDK Build-Tools y ejecuta: apksigner verify --verbose "' $apk.FullName '"'
}

Write-Host ""
Write-Host "Copia el APK firmado a assets\descargas\adara.apk"
Write-Host "En el teléfono: Ajustes, Seguridad, permitir orígenes desconocidos, y abre el archivo desde la notificación de descarga."
