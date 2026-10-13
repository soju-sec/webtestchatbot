#!/usr/bin/env bash
# ==============================================================================
# Universal AI Chatbot - Capability Auto-Registration Script
# ==============================================================================
# Script ini digunakan untuk mendaftarkan endpoint API baru ke config/ai_registry.php
# agar AI Chatbot (Gemini / n8n) dapat langsung membaca dan memahami data baru.
#
# Penggunaan Interaktif:
#   ./register-ai-capability.sh
#
# Penggunaan via Parameter:
#   ./register-ai-capability.sh \
#       --action="get_reviews" \
#       --method="GET" \
#       --path="/api/v1/reviews" \
#       --desc="Melihat rating dan ulasan pelanggan" \
#       --data='{"rating": 4.9, "total_reviews": 1200}'
# ==============================================================================

set -e

# Warna output
GREEN='\033[0;32m'
BLUE='\033[0;34m'
YELLOW='\033[1;33m'
RED='\033[0;31m'
NC='\033[0m'

# Deteksi lokasi config/ai_registry.php
CONFIG_FILE=""
if [ -f "config/ai_registry.php" ]; then
    CONFIG_FILE="config/ai_registry.php"
elif [ -f "../config/ai_registry.php" ]; then
    CONFIG_FILE="../config/ai_registry.php"
else
    # Jika belum ada, buat di config/ai_registry.php
    mkdir -p config
    CONFIG_FILE="config/ai_registry.php"
    cat << 'INIT_EOF' > "$CONFIG_FILE"
<?php

return [
    'endpoints' => [
    ],
];
INIT_EOF
fi

# Parsing argumen CLI
ACTION=""
METHOD="GET"
PATH_URL=""
DESC=""
DATA_JSON=""
PARAMS_STR=""

while [[ "$#" -gt 0 ]]; do
    case $1 in
        --action=*) ACTION="${1#*=}" ;;
        --action) ACTION="$2"; shift ;;
        --method=*) METHOD="${1#*=}" ;;
        --method) METHOD="$2"; shift ;;
        --path=*) PATH_URL="${1#*=}" ;;
        --path) PATH_URL="$2"; shift ;;
        --desc=*) DESC="${1#*=}" ;;
        --desc) DESC="$2"; shift ;;
        --data=*) DATA_JSON="${1#*=}" ;;
        --data) DATA_JSON="$2"; shift ;;
        --params=*) PARAMS_STR="${1#*=}" ;;
        --params) PARAMS_STR="$2"; shift ;;
        --help|-h)
            echo "Penggunaan: $0 [--action=NAME] [--method=GET|POST] [--path=/api/...] [--desc='Deskripsi'] [--data='{...}']"
            exit 0
            ;;
        *) echo -e "${RED}Argumen tidak dikenal: $1${NC}"; exit 1 ;;
    esac
    shift
done

# Mode Interaktif jika parameter belum lengkap
if [ -z "$ACTION" ]; then
    echo -e "${BLUE}======================================================${NC}"
    echo -e "${BLUE}    Registrasi Endpoint Baru untuk AI Chatbot        ${NC}"
    echo -e "${BLUE}======================================================${NC}"
    echo ""

    read -p "1. Nama Action unik (contoh: get_product_stock): " ACTION
    while [ -z "$ACTION" ]; do
        echo -e "${RED}Nama action wajib diisi!${NC}"
        read -p "1. Nama Action unik: " ACTION
    done

    read -p "2. HTTP Method [GET/POST] (default: GET): " INPUT_METHOD
    if [ -n "$INPUT_METHOD" ]; then
        METHOD=$(echo "$INPUT_METHOD" | tr '[:lower:]' '[:upper:]')
    fi

    read -p "3. Endpoint Path (contoh: /api/v1/products/stock): " PATH_URL
    while [ -z "$PATH_URL" ]; do
        echo -e "${RED}Endpoint path wajib diisi!${NC}"
        read -p "3. Endpoint Path: " PATH_URL
    done

    read -p "4. Deskripsi fungsi data (contoh: Melihat stok barang tersedia): " DESC
    while [ -z "$DESC" ]; do
        echo -e "${RED}Deskripsi wajib diisi!${NC}"
        read -p "4. Deskripsi fungsi data: " DESC
    done

    echo "5. Contoh data snapshot / JSON (opsional, tekan ENTER jika tidak ada):"
    read -p "   Data JSON: " DATA_JSON
fi

# Pastikan PHP CLI tersedia
PHP_BIN=""
if command -v php &> /dev/null; then
    PHP_BIN="php"
elif [ -f "/home/soju/.local/bin/php" ]; then
    PHP_BIN="/home/soju/.local/bin/php"
else
    echo -e "${RED}Error: PHP CLI tidak ditemukan di sistem!${NC}"
    exit 1
fi

# Jalankan script PHP inline untuk memanipulasi config/ai_registry.php dengan aman
CONFIG_FILE="$CONFIG_FILE" \
ACTION="$ACTION" \
METHOD="$METHOD" \
PATH_URL="$PATH_URL" \
DESC="$DESC" \
DATA_JSON="$DATA_JSON" \
PARAMS_STR="$PARAMS_STR" \
$PHP_BIN << 'PHP_RUNNER'
<?php

$configFile = getenv('CONFIG_FILE');
$action     = getenv('ACTION');
$method     = getenv('METHOD') ?: 'GET';
$path       = getenv('PATH_URL');
$desc       = getenv('DESC');
$dataJson   = getenv('DATA_JSON');
$paramsStr  = getenv('PARAMS_STR');

if (!file_exists($configFile)) {
    echo "Config file not found: $configFile\n";
    exit(1);
}

$config = include $configFile;
if (!is_array($config) || !isset($config['endpoints'])) {
    $config = ['endpoints' => []];
}

$parsedData = null;
if (!empty($dataJson)) {
    $decoded = json_decode($dataJson, true);
    if (json_last_error() === JSON_ERROR_NONE) {
        $parsedData = $decoded;
    } else {
        $parsedData = $dataJson;
    }
}

$parsedParams = [];
if (!empty($paramsStr)) {
    $parsedParams = array_map('trim', explode(',', $paramsStr));
}

$newEntry = [
    'action'      => $action,
    'method'      => $method,
    'path'        => $path,
    'description' => $desc,
];

if (!empty($parsedParams)) {
    $newEntry['params'] = $parsedParams;
}

if ($parsedData !== null) {
    $newEntry['current_data'] = $parsedData;
}

// Cek apakah action sudah ada sebelumnya
$updated = false;
foreach ($config['endpoints'] as $idx => $ep) {
    if (isset($ep['action']) && $ep['action'] === $action) {
        $config['endpoints'][$idx] = $newEntry;
        $updated = true;
        break;
    }
}

if (!$updated) {
    $config['endpoints'][] = $newEntry;
}

function exportPhpArray(array $arr, int $indent = 1): string {
    $spaces = str_repeat('    ', $indent);
    $lines = ["[\n"];
    $isAssoc = array_keys($arr) !== range(0, count($arr) - 1);

    foreach ($arr as $key => $val) {
        $line = $spaces . '    ';
        if ($isAssoc) {
            $line .= var_export($key, true) . ' => ';
        }

        if (is_array($val)) {
            $line .= exportPhpArray($val, $indent + 1);
        } else {
            $line .= var_export($val, true);
        }
        $line .= ",\n";
        $lines[] = $line;
    }
    $lines[] = $spaces . ']';
    return implode('', $lines);
}

$code = "<?php\n\nreturn [\n    /*\n    |--------------------------------------------------------------------------\n    | Daftarkan Endpoint API yang Boleh Diakses AI di Proyek Ini\n    |--------------------------------------------------------------------------\n    | n8n akan membaca manifest ini untuk membuat prompt Gemini secara dinamis.\n    */\n    'endpoints' => " . exportPhpArray($config['endpoints'], 1) . ",\n];\n";

file_put_contents($configFile, $code);

echo ($updated ? "UPDATED" : "ADDED") . "\n";
PHP_RUNNER

# Validasi sintaks PHP
$PHP_BIN -l "$CONFIG_FILE" > /dev/null

echo ""
echo -e "${GREEN}✓ Sukses mendaftarkan endpoint ke ${CONFIG_FILE}!${NC}"
echo -e "  - Action      : ${BLUE}$ACTION${NC}"
echo -e "  - Method      : ${BLUE}$METHOD${NC}"
echo -e "  - Path        : ${BLUE}$PATH_URL${NC}"
echo -e "  - Deskripsi   : $DESC"
if [ -n "$DATA_JSON" ]; then
    echo -e "  - Data Snapshot: $DATA_JSON"
fi
echo ""
echo -e "${YELLOW}ℹ AI Chatbot (Gemini via n8n) akan langsung mendeteksi kapabilitas ini secara otomatis.${NC}"
