#!/usr/bin/env bash
# ==============================================================================
# Universal AI Chatbot - Universal API Auto-Discovery & Registration Script
# ==============================================================================
# Mendukung 4 Cara Registrasi:
# 1. Kasih Link Endpoint API Langsung:
#    ./register-ai-capability.sh --url="http://localhost:8088/api/v1/store/metrics"
#
# 2. Kasih Link / File Swagger / OpenAPI Spec (Universal Semua Bahasa):
#    ./register-ai-capability.sh --spec="http://example.com/openapi.json"
#    ./register-ai-capability.sh --spec="./swagger.json"
#
# 3. Auto-Scan Semua Route dari Project Backend:
#    ./register-ai-capability.sh --scan
#
# 4. Mode Interaktif atau Manual Flag:
#    ./register-ai-capability.sh --action="get_users" --path="/api/v1/users" --desc="..."
# ==============================================================================

set -e

GREEN='\033[0;32m'
BLUE='\033[0;34m'
YELLOW='\033[1;33m'
CYAN='\033[0;36m'
RED='\033[0;31m'
NC='\033[0m'

# Deteksi lokasi config/ai_registry.php
CONFIG_FILE=""
if [ -f "config/ai_registry.php" ]; then
    CONFIG_FILE="config/ai_registry.php"
elif [ -f "../config/ai_registry.php" ]; then
    CONFIG_FILE="../config/ai_registry.php"
else
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

# Deteksi PHP CLI
PHP_BIN=""
if command -v php &> /dev/null; then
    PHP_BIN="php"
elif [ -f "/home/soju/.local/bin/php" ]; then
    PHP_BIN="/home/soju/.local/bin/php"
else
    echo -e "${RED}Error: PHP CLI tidak ditemukan di sistem!${NC}"
    exit 1
fi

# Baca INTERNAL_API_KEY dari .env jika ada
INTERNAL_KEY=""
if [ -f ".env" ]; then
    INTERNAL_KEY=$(grep -E "^INTERNAL_API_KEY=" .env | cut -d '=' -f2- | tr -d '"' | tr -d "'" || true)
fi

# Parsing Argumen CLI
URL_ENDPOINT=""
SPEC_INPUT=""
DO_SCAN=false
ACTION=""
METHOD="GET"
PATH_URL=""
DESC=""
DATA_JSON=""
PARAMS_STR=""

while [[ "$#" -gt 0 ]]; do
    case $1 in
        --url=*) URL_ENDPOINT="${1#*=}" ;;
        --url) URL_ENDPOINT="$2"; shift ;;
        --spec=*) SPEC_INPUT="${1#*=}" ;;
        --spec) SPEC_INPUT="$2"; shift ;;
        --scan) DO_SCAN=true ;;
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
            echo "Universal AI Capability Registrar"
            echo "Penggunaan:"
            echo "  $0 --url='http://localhost:8088/api/v1/metrics'    (Cukup kasih link endpoint)"
            echo "  $0 --spec='http://example.com/openapi.json'        (Impor semua endpoint dari Swagger/OpenAPI)"
            echo "  $0 --scan                                         (Auto-scan semua routes di project)"
            echo "  $0 --action=NAME --path=/api/... --desc='...'      (Registrasi manual via CLI)"
            echo "  $0                                                (Mode Interaktif)"
            exit 0
            ;;
        *) echo -e "${RED}Argumen tidak dikenal: $1${NC}"; exit 1 ;;
    esac
    shift
done

# ==============================================================================
# FITUR 1: Cukup Kasih Link Endpoint API (--url)
# ==============================================================================
if [ -n "$URL_ENDPOINT" ]; then
    echo -e "${CYAN}Mengambil data dari URL: ${URL_ENDPOINT}...${NC}"
    
    HEADER_ARGS=()
    if [ -n "$INTERNAL_KEY" ]; then
        HEADER_ARGS=(-H "X-Internal-API-Key: $INTERNAL_KEY")
    fi

    HTTP_RES=$(curl -s "${HEADER_ARGS[@]}" "$URL_ENDPOINT" || true)
    
    # Ambil PATH dari URL (contoh: http://localhost:8088/api/v1/metrics -> /api/v1/metrics)
    PARSED_PATH=$(echo "$URL_ENDPOINT" | sed -E 's|^https?://[^/]+||')
    if [ -z "$PARSED_PATH" ]; then
        PARSED_PATH="/"
    fi
    PATH_URL="$PARSED_PATH"

    # Auto-generate action name jika belum ada
    if [ -z "$ACTION" ]; then
        CLEAN_NAME=$(echo "$PARSED_PATH" | sed -E 's|^/api/(v[0-9]+/)?||' | tr '/-' '_' | sed -E 's/[^a-zA-Z0-9_]//g')
        ACTION="get_${CLEAN_NAME}"
    fi

    if [ -z "$DESC" ]; then
        DESC="Melihat data dari endpoint $PARSED_PATH"
    fi

    DATA_JSON="$HTTP_RES"
fi

# ==============================================================================
# FITUR 2: Kasih Link / File Swagger / OpenAPI Spec (--spec)
# ==============================================================================
if [ -n "$SPEC_INPUT" ]; then
    echo -e "${CYAN}Membaca Swagger / OpenAPI Spec dari: ${SPEC_INPUT}...${NC}"
    
    SPEC_CONTENT=""
    if [[ "$SPEC_INPUT" =~ ^https?:// ]]; then
        SPEC_CONTENT=$(curl -s "$SPEC_INPUT")
    elif [ -f "$SPEC_INPUT" ]; then
        SPEC_CONTENT=$(cat "$SPEC_INPUT")
    else
        echo -e "${RED}File atau URL OpenAPI tidak ditemukan: $SPEC_INPUT${NC}"
        exit 1
    fi

    CONFIG_FILE="$CONFIG_FILE" SPEC_CONTENT="$SPEC_CONTENT" $PHP_BIN << 'SPEC_PARSER'
<?php
$configFile = getenv('CONFIG_FILE');
$specJson   = getenv('SPEC_CONTENT');
$spec       = json_decode($specJson, true);

if (!is_array($spec) || !isset($spec['paths'])) {
    echo "ERROR: Format OpenAPI / Swagger JSON tidak valid (paths tidak ditemukan).\n";
    exit(1);
}

$config = include $configFile;
if (!is_array($config) || !isset($config['endpoints'])) {
    $config = ['endpoints' => []];
}

$existingActions = [];
foreach ($config['endpoints'] as $ep) {
    if (isset($ep['action'])) {
        $existingActions[$ep['action']] = true;
    }
}

$importedCount = 0;
foreach ($spec['paths'] as $path => $methods) {
    foreach ($methods as $httpMethod => $details) {
        $httpMethodUpper = strtoupper($httpMethod);
        if (!in_array($httpMethodUpper, ['GET', 'POST', 'PUT', 'DELETE'])) {
            continue;
        }

        $summary = $details['summary'] ?? ($details['description'] ?? "Endpoint $path");
        $actionName = $details['operationId'] ?? '';
        if (empty($actionName)) {
            $cleanPath = preg_replace('#^/api/(v[0-9]+/)?#', '', $path);
            $cleanPath = preg_replace('/[^a-zA-Z0-9_]/', '_', $cleanPath);
            $actionName = strtolower($httpMethod) . '_' . trim($cleanPath, '_');
        }

        $params = [];
        if (isset($details['parameters']) && is_array($details['parameters'])) {
            foreach ($details['parameters'] as $p) {
                if (isset($p['name'])) $params[] = $p['name'];
            }
        }

        $newEntry = [
            'action'      => $actionName,
            'method'      => $httpMethodUpper,
            'path'        => $path,
            'description' => $summary,
        ];
        if (!empty($params)) {
            $newEntry['params'] = $params;
        }

        $foundIndex = -1;
        foreach ($config['endpoints'] as $idx => $ep) {
            if (isset($ep['action']) && $ep['action'] === $actionName) {
                $foundIndex = $idx;
                break;
            }
        }

        if ($foundIndex >= 0) {
            $config['endpoints'][$foundIndex] = array_merge($config['endpoints'][$foundIndex], $newEntry);
        } else {
            $config['endpoints'][] = $newEntry;
        }
        $importedCount++;
    }
}

function exportPhpArray(array $arr, int $indent = 1): string {
    $spaces = str_repeat('    ', $indent);
    $lines = ["[\n"];
    $isAssoc = array_keys($arr) !== range(0, count($arr) - 1);
    foreach ($arr as $key => $val) {
        $line = $spaces . '    ';
        if ($isAssoc) $line .= var_export($key, true) . ' => ';
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

$code = "<?php\n\nreturn [\n    'endpoints' => " . exportPhpArray($config['endpoints'], 1) . ",\n];\n";
file_put_contents($configFile, $code);
echo "SUCCESS:$importedCount\n";
SPEC_PARSER

    echo -e "${GREEN}✓ Berhasil mengimpor seluruh endpoint dari OpenAPI / Swagger spec ke $CONFIG_FILE!${NC}"
    exit 0
fi

# ==============================================================================
# FITUR 3: Auto-Scan Project Routes (--scan)
# ==============================================================================
if [ "$DO_SCAN" = true ]; then
    echo -e "${CYAN}Memindai route API dari project...${NC}"

    ROUTES_JSON=""
    # Cek jika project Laravel (artisan)
    if [ -f "artisan" ]; then
        ROUTES_JSON=$($PHP_BIN artisan route:list --path=api --json 2>/dev/null || true)
    fi

    # Cek jika ada swagger.json atau openapi.json di root
    if [ -z "$ROUTES_JSON" ] || [ "$ROUTES_JSON" = "[]" ]; then
        for candidate in "swagger.json" "openapi.json" "api-docs.json" "docs/swagger.json"; do
            if [ -f "$candidate" ]; then
                echo -e "${BLUE}Menemukan file spec: $candidate${NC}"
                exec "$0" --spec="$candidate"
            fi
        done
    fi

    if [ -z "$ROUTES_JSON" ] || [ "$ROUTES_JSON" = "[]" ]; then
        echo -e "${YELLOW}Tidak dapat mendeteksi routes otomatis via artisan/swagger.${NC}"
        echo -e "Silakan gunakan ${BLUE}--url='http://.../api/endpoint'${NC} atau ${BLUE}--spec='openapi.json'${NC}."
        exit 1
    fi

    CONFIG_FILE="$CONFIG_FILE" ROUTES_JSON="$ROUTES_JSON" $PHP_BIN << 'SCAN_PARSER'
<?php
$configFile = getenv('CONFIG_FILE');
$routesJson = getenv('ROUTES_JSON');
$routes     = json_decode($routesJson, true);

if (!is_array($routes)) {
    echo "ERROR: Output routes tidak valid.\n";
    exit(1);
}

$config = include $configFile;
if (!is_array($config) || !isset($config['endpoints'])) {
    $config = ['endpoints' => []];
}

$ignoreUris = [
    'api/v1/ai/manifest',
    'api/v1/conversations/{conversationId}/messages',
    'api/webhook/chat-entry',
    'api/user',
];

$addedCount = 0;
foreach ($routes as $route) {
    $uri = $route['uri'] ?? '';
    if (empty($uri)) continue;

    // Normalisasi uri diawali slash
    $path = '/' . ltrim($uri, '/');

    // Abaikan route internal sistem chatbot
    if (in_array(trim($uri, '/'), $ignoreUris)) {
        continue;
    }

    $methods = explode('|', $route['method'] ?? 'GET');
    $primaryMethod = $methods[0] === 'HEAD' ? 'GET' : $methods[0];

    // Buat action name dari URI
    $cleanUri = preg_replace('#^api/(v[0-9]+/)?#', '', trim($uri, '/'));
    $cleanUri = preg_replace('/\{[^}]+\}/', '', $cleanUri); // hilangkan {id}
    $cleanUri = preg_replace('/[^a-zA-Z0-9_]/', '_', $cleanUri);
    $cleanUri = trim(preg_replace('/_+/', '_', $cleanUri), '_');

    $actionName = strtolower($primaryMethod) . '_' . ($cleanUri ?: 'index');

    // Cek apakah action sudah ada
    $exists = false;
    foreach ($config['endpoints'] as $ep) {
        if (isset($ep['path']) && $ep['path'] === $path) {
            $exists = true;
            break;
        }
    }

    if (!$exists) {
        $descWords = str_replace('_', ' ', $cleanUri);
        $config['endpoints'][] = [
            'action'      => $actionName,
            'method'      => $primaryMethod,
            'path'        => $path,
            'description' => "Melihat data " . ($descWords ?: $path),
        ];
        $addedCount++;
        echo "  [+] Terdaftar: $primaryMethod $path ($actionName)\n";
    }
}

function exportPhpArray(array $arr, int $indent = 1): string {
    $spaces = str_repeat('    ', $indent);
    $lines = ["[\n"];
    $isAssoc = array_keys($arr) !== range(0, count($arr) - 1);
    foreach ($arr as $key => $val) {
        $line = $spaces . '    ';
        if ($isAssoc) $line .= var_export($key, true) . ' => ';
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

$code = "<?php\n\nreturn [\n    /*\n    |--------------------------------------------------------------------------\n    | Daftarkan Endpoint API yang Boleh Diakses AI di Proyek Ini\n    |--------------------------------------------------------------------------\n    */\n    'endpoints' => " . exportPhpArray($config['endpoints'], 1) . ",\n];\n";
file_put_contents($configFile, $code);

echo "DONE:$addedCount\n";
SCAN_PARSER

    echo -e "${GREEN}✓ Selesai memindai routes! Seluruh endpoint API project telah didaftarkan ke $CONFIG_FILE.${NC}"
    exit 0
fi

# ==============================================================================
# FITUR 4: Registrasi Tunggal (Interaktif atau Manual via Flags)
# ==============================================================================
if [ -z "$ACTION" ]; then
    echo -e "${BLUE}======================================================${NC}"
    echo -e "${BLUE}    Registrasi Endpoint Baru untuk AI Chatbot        ${NC}"
    echo -e "${BLUE}======================================================${NC}"
    echo ""
    echo "💡 Tips: Anda juga bisa cukup memasukkan URL endpoint atau OpenAPI spec:"
    echo "   $0 --url='http://localhost:8088/api/v1/store/metrics'"
    echo "   $0 --scan"
    echo ""

    read -p "1. Masukkan URL endpoint API atau nama Action (contoh: http://... atau get_orders): " INPUT_INIT
    if [[ "$INPUT_INIT" =~ ^https?:// ]]; then
        exec "$0" --url="$INPUT_INIT"
    fi

    ACTION="$INPUT_INIT"
    while [ -z "$ACTION" ]; do
        echo -e "${RED}Nama action wajib diisi!${NC}"
        read -p "1. Nama Action unik: " ACTION
    done

    read -p "2. HTTP Method [GET/POST] (default: GET): " INPUT_METHOD
    if [ -n "$INPUT_METHOD" ]; then
        METHOD=$(echo "$INPUT_METHOD" | tr '[:lower:]' '[:upper:]')
    fi

    read -p "3. Endpoint Path (contoh: /api/v1/orders): " PATH_URL
    while [ -z "$PATH_URL" ]; do
        echo -e "${RED}Endpoint path wajib diisi!${NC}"
        read -p "3. Endpoint Path: " PATH_URL
    done

    read -p "4. Deskripsi fungsi data: " DESC
    while [ -z "$DESC" ]; do
        echo -e "${RED}Deskripsi wajib diisi!${NC}"
        read -p "4. Deskripsi fungsi data: " DESC
    done

    echo "5. Contoh data snapshot / JSON (opsional, tekan ENTER jika tidak ada):"
    read -p "   Data JSON: " DATA_JSON
fi

# Eksekusi penyimpanan ke file config
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

$PHP_BIN -l "$CONFIG_FILE" > /dev/null

echo ""
echo -e "${GREEN}✓ Sukses mendaftarkan endpoint ke ${CONFIG_FILE}!${NC}"
echo -e "  - Action      : ${BLUE}$ACTION${NC}"
echo -e "  - Method      : ${BLUE}$METHOD${NC}"
echo -e "  - Path        : ${BLUE}$PATH_URL${NC}"
echo -e "  - Deskripsi   : $DESC"
if [ -n "$DATA_JSON" ]; then
    echo -e "  - Data Snapshot: Terisi"
fi
echo ""
echo -e "${YELLOW}ℹ AI Chatbot (Gemini via n8n) akan langsung mendeteksi kapabilitas ini secara otomatis.${NC}"
