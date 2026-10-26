#!/usr/bin/env bash
# ==============================================================================
# JIRA SERVICE MANAGEMENT (PROJECT SUP) - VERIFICATION & DIAGNOSTIC SCRIPT
# ==============================================================================

# Warna Terminal
CYAN='\033[0;36m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
RED='\033[0;31m'
BOLD='\033[1m'
NC='\033[0m' # No Color

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"

# Cari file .env
ENV_FILE=""
if [ -f "${ROOT_DIR}/.env" ]; then
    ENV_FILE="${ROOT_DIR}/.env"
elif [ -f ".env" ]; then
    ENV_FILE=".env"
fi

echo -e "${CYAN}${BOLD}==============================================================${NC}"
echo -e "${CYAN}${BOLD}   JIRA SERVICE MANAGEMENT INTEGRATION CHECKER                ${NC}"
echo -e "${CYAN}${BOLD}==============================================================${NC}"

# Baca variabel dari .env jika ada
if [ -n "${ENV_FILE}" ]; then
    echo -e "Memuat konfigurasi dari: ${GREEN}${ENV_FILE}${NC}"
    while IFS= read -r line || [ -n "$line" ]; do
        # Lewati komentar atau baris kosong
        [[ "$line" =~ ^[[:space:]]*# ]] && continue
        [[ -z "$line" ]] && continue
        [[ "$line" != *"="* ]] && continue
        key="${line%%=*}"
        val="${line#*=}"
        key=$(echo "$key" | tr -d '[:space:]')
        val=$(echo "$val" | sed -e 's/^[[:space:]]*//' -e 's/[[:space:]]*$//' -e 's/^"//' -e 's/"$//' -e "s/^'//" -e "s/'$//")
        case "$key" in
            JIRA_HOST) JIRA_HOST="$val" ;;
            JIRA_PROJECT_KEY) JIRA_PROJECT_KEY="$val" ;;
            JIRA_USER_EMAIL) JIRA_USER_EMAIL="$val" ;;
            JIRA_API_TOKEN) JIRA_API_TOKEN="$val" ;;
            JIRA_ISSUE_TYPE) JIRA_ISSUE_TYPE="$val" ;;
        esac
    done < "${ENV_FILE}"
fi

# Nilai default
JIRA_HOST="${JIRA_HOST:-imtekservice.atlassian.net}"
JIRA_PROJECT_KEY="${JIRA_PROJECT_KEY:-SUP}"
JIRA_ISSUE_TYPE="${JIRA_ISSUE_TYPE:-Task}"

# Bersihkan URL host
JIRA_HOST=$(echo "$JIRA_HOST" | sed -e 's|^https\?://||' -e 's|/$||')
BASE_URL="https://${JIRA_HOST}"

echo ""
echo -e "Instance Jira  : ${BOLD}${BASE_URL}${NC}"
echo -e "Project Key    : ${GREEN}${BOLD}${JIRA_PROJECT_KEY}${NC} (Jira Service Desk)"
echo -e "User Email     : ${BOLD}${JIRA_USER_EMAIL:-<belum diisi di .env>}${NC}"
if [ -n "$JIRA_API_TOKEN" ]; then
    MASKED_TOKEN="${JIRA_API_TOKEN:0:4}••••••••${JIRA_API_TOKEN: -4}"
    echo -e "API Token      : ${GREEN}TERPASANG (${MASKED_TOKEN})${NC}"
else
    echo -e "API Token      : ${RED}BELUM TERPASANG (Kosong di .env)${NC}"
fi
echo -e "Default Type   : ${BOLD}${JIRA_ISSUE_TYPE}${NC}"
echo "--------------------------------------------------------------"

if [ -z "$JIRA_USER_EMAIL" ] || [ -z "$JIRA_API_TOKEN" ]; then
    echo -e "${YELLOW}⚠️  Kredensial Jira belum lengkap di .env!${NC}"
    echo -e "Silakan lengkapi variabel berikut pada file .env Anda:"
    echo -e "   ${BOLD}JIRA_USER_EMAIL=email_atlassian_anda@domain.com${NC}"
    echo -e "   ${BOLD}JIRA_API_TOKEN=token_api_atlassian_anda${NC}"
    echo ""
    echo -e "💡 ${CYAN}Cara membuat Atlassian API Token (Gratis):${NC}"
    echo -e "1. Buka: ${BOLD}https://id.atlassian.com/manage-profile/security/api-tokens${NC}"
    echo -e "2. Klik tombol ${GREEN}'Create API token'${NC}"
    echo -e "3. Beri label (misal: 'Universal Chatbot') dan salin token yang muncul."
    echo -e "4. Tempelkan ke file .env lalu jalankan script ini kembali."
    exit 0
fi

# 1. Test Autentikasi /rest/api/2/myself
echo -e "🔍 Menguji autentikasi ke ${BASE_URL}/rest/api/2/myself ..."
HTTP_CODE=$(curl -s -o /tmp/jira_myself.json -w "%{http_code}" \
    -u "${JIRA_USER_EMAIL}:${JIRA_API_TOKEN}" \
    -H "Accept: application/json" \
    "${BASE_URL}/rest/api/2/myself" || echo "000")

if [ "$HTTP_CODE" -eq 200 ]; then
    DISPLAY_NAME=$(python3 -c "import json; data=json.load(open('/tmp/jira_myself.json')); print(data.get('displayName') or data.get('emailAddress') or 'User')" 2>/dev/null || echo "$JIRA_USER_EMAIL")
    ACC_TYPE=$(python3 -c "import json; data=json.load(open('/tmp/jira_myself.json')); print(data.get('accountType') or 'atlassian')" 2>/dev/null || echo "atlassian")
    echo -e "${GREEN}✓ Autentikasi Berhasil!${NC} Login sebagai: ${BOLD}${DISPLAY_NAME}${NC} (${ACC_TYPE})"
else
    echo -e "${RED}✗ Autentikasi Gagal (HTTP ${HTTP_CODE})${NC}"
    if [ -f /tmp/jira_myself.json ]; then
        cat /tmp/jira_myself.json
    fi
    echo ""
    echo -e "${YELLOW}Pastikan JIRA_USER_EMAIL dan JIRA_API_TOKEN sudah sesuai.${NC}"
    exit 1
fi

# 2. Test Akses ke Proyek (misal: SUP)
echo -e "🔍 Memverifikasi akses ke Proyek Jira: ${BOLD}${JIRA_PROJECT_KEY}${NC} ..."
PROJ_CODE=$(curl -s -o /tmp/jira_project.json -w "%{http_code}" \
    -u "${JIRA_USER_EMAIL}:${JIRA_API_TOKEN}" \
    -H "Accept: application/json" \
    "${BASE_URL}/rest/api/2/project/${JIRA_PROJECT_KEY}" || echo "000")

if [ "$PROJ_CODE" -eq 200 ]; then
    PROJ_NAME=$(python3 -c "import json; data=json.load(open('/tmp/jira_project.json')); print(data.get('name') or '${JIRA_PROJECT_KEY}')" 2>/dev/null || echo "$JIRA_PROJECT_KEY")
    PROJ_TYPE=$(python3 -c "import json; data=json.load(open('/tmp/jira_project.json')); print(data.get('projectTypeKey') or 'service_desk')" 2>/dev/null || echo "service_desk")
    echo -e "${GREEN}✓ Proyek Terdeteksi!${NC} Nama: ${BOLD}${PROJ_NAME}${NC} (Tipe: ${PROJ_TYPE})"
    echo -e "Dashboard Proyek: ${CYAN}https://${JIRA_HOST}/jira/servicedesk/projects/${JIRA_PROJECT_KEY}/getting-started${NC}"
    
    echo ""
    echo -e "${CYAN}Tipe Issue yang Tersedia di Project ${JIRA_PROJECT_KEY}:${NC}"
    python3 -c "
import json
try:
    data = json.load(open('/tmp/jira_project.json'))
    for it in data.get('issueTypes', []):
        sub = '(Subtask)' if it.get('subtask') else '(Standard)'
        print(f'   • {it.get(\"name\")} {sub}')
except Exception:
    pass
" 2>/dev/null || true
else
    echo -e "${RED}✗ Gagal mengakses proyek ${JIRA_PROJECT_KEY} (HTTP ${PROJ_CODE})${NC}"
    if [ -f /tmp/jira_project.json ]; then
        cat /tmp/jira_project.json
    fi
    exit 1
fi

# 3. Buat issue jika diminta argumen --create-issue
if [ "$1" == "--create-issue" ]; then
    echo ""
    echo -e "🚀 Membuat tiket uji coba di Jira Service Desk (${JIRA_PROJECT_KEY})..."
    TCK_NUM="TCK-$(date +%Y%m%d)-$(head /dev/urandom | tr -dc A-Z0-9 | head -c 4)"
    
    PAYLOAD=$(python3 -c "
import json, sys
tck = sys.argv[1]
proj = sys.argv[2]
itype = sys.argv[3]
data = {
    'fields': {
        'project': {'key': proj},
        'summary': f'[{tck}] Verifikasi Integrasi Universal Chatbot',
        'description': f'Tiket uji coba dari Universal Chatbot.\\nNomor Tiket: #{tck}\\nPelapor: Admin (CLI Verification)\\nStatus: OPEN',
        'issuetype': {'name': itype},
        'priority': {'name': 'Medium'},
        'labels': ['chatbot', 'cli-verification', 'sup-ticket']
    }
}
print(json.dumps(data))
" "$TCK_NUM" "$JIRA_PROJECT_KEY" "$JIRA_ISSUE_TYPE")

    ISSUE_RES=$(curl -s -w "\n%{http_code}" \
        -X POST \
        -u "${JIRA_USER_EMAIL}:${JIRA_API_TOKEN}" \
        -H "Accept: application/json" \
        -H "Content-Type: application/json" \
        -d "$PAYLOAD" \
        "${BASE_URL}/rest/api/2/issue")

    RESP_BODY=$(echo "$ISSUE_RES" | head -n -1)
    RESP_CODE=$(echo "$ISSUE_RES" | tail -n 1)

    if [ "$RESP_CODE" -eq 201 ]; then
        ISSUE_KEY=$(python3 -c "import json; data=json.loads('''$RESP_BODY'''); print(data.get('key'))" 2>/dev/null || echo "$RESP_BODY" | grep -o '"key":"[^"]*"' | head -n 1 | cut -d'"' -f4)
        echo -e "${GREEN}${BOLD}✓ TIKET JIRA BERHASIL DIBUAT: ${ISSUE_KEY}${NC}"
        echo -e "🔗 Buka di browser: ${CYAN}https://${JIRA_HOST}/browse/${ISSUE_KEY}${NC}"
    else
        echo -e "${RED}✗ Gagal membuat tiket (HTTP ${RESP_CODE}):${NC}"
        echo "$RESP_BODY"
    fi
fi

echo ""
echo -e "${GREEN}${BOLD}==============================================================${NC}"
echo -e "${GREEN}${BOLD}   INTEGRASI JIRA SERVICE DESK (SUP) SIAP DIGUNAKAN!          ${NC}"
echo -e "${GREEN}${BOLD}==============================================================${NC}"
if [ "$1" != "--create-issue" ]; then
    echo -e "Untuk membuat issue uji coba, jalankan:"
    echo -e "   ${BOLD}./check-jira.sh --create-issue${NC}"
fi
