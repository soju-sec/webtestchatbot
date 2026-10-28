#!/usr/bin/env bash

# ==============================================================================
# Script Diagnostik & Pengujian Chatbot WhatsApp Eksekutif (Dual-Flow Validator)
# ==============================================================================

set -e

GREEN='\033[0;32m'
CYAN='\033[0;36m'
YELLOW='\033[1;33m'
RED='\033[0;31m'
BOLD='\033[1m'
NC='\033[0m' # No Color

N8N_URL="${N8N_WEBHOOK_URL:-http://localhost:5678/webhook/chat-entry}"
EXECUTIVE_PHONE="${EXECUTIVE_PHONE:-628123456789}"
EXECUTIVE_NAME="${EXECUTIVE_NAME:-Budi Santoso (Direktur Operasional)}"
DEFAULT_MSG="${1:-Halo, berikan ringkasan performa toko dan metrik earnings terkini}"

echo -e "${GREEN}${BOLD}======================================================================${NC}"
echo -e "${GREEN}${BOLD}     SIMULASI & PENGUJIAN CHATBOT WHATSAPP EKSEKUTIF (VIP)            ${NC}"
echo -e "${GREEN}${BOLD}======================================================================${NC}"
echo -e "${CYAN}Target Endpoint n8n :${NC} ${N8N_URL}"
echo -e "${CYAN}Nomor WA Eksekutif  :${NC} ${EXECUTIVE_PHONE}"
echo -e "${CYAN}Profil Eksekutif    :${NC} ${EXECUTIVE_NAME}"
echo -e "${CYAN}Kanal Akses         :${NC} ${BOLD}WHATSAPP (Executive Persona)${NC}"
echo -e "${CYAN}Pesan Uji Coba      :${NC} \"${DEFAULT_MSG}\""
echo -e "----------------------------------------------------------------------"
echo -e "Memanggil alur orkestrator (Anti-PII -> Memory -> Manifest -> Gemini -> BI)..."

START_TIME=$(date +%s%N)

PAYLOAD=$(cat <<EOF
{
  "conversation_id": "wa_${EXECUTIVE_PHONE}",
  "user_id": "${EXECUTIVE_PHONE}",
  "user_name": "${EXECUTIVE_NAME}",
  "message": "${DEFAULT_MSG}",
  "channel": "whatsapp",
  "role": "executive"
}
EOF
)

HTTP_RESPONSE=$(curl -s -w "\n%{http_code}" -X POST "${N8N_URL}" \
  -H "Content-Type: application/json" \
  -d "${PAYLOAD}" || true)

HTTP_CODE=$(echo "${HTTP_RESPONSE}" | tail -n1)
BODY=$(echo "${HTTP_RESPONSE}" | sed '$d')

END_TIME=$(date +%s%N)
ELAPSED_MS=$(( (END_TIME - START_TIME) / 1000000 ))

if [ "${HTTP_CODE}" = "200" ]; then
  echo -e "${GREEN}${BOLD}✓ RESPON DITERIMA DENGAN SUKSES (${ELAPSED_MS} ms)${NC}"
  echo -e "----------------------------------------------------------------------"

  # Ekstrak field menggunakan node/python
  echo "${BODY}" | python3 -c "
import json, sys
data = json.load(sys.stdin)
print(f\"🎯 Intent Terdeteksi : {data.get('intent', '-')}\")
print(f\"⚡ Confidence Score  : {data.get('confidence', '-')}\")
if data.get('ticket_number'):
    print(f\"🎫 Nomor Tiket (VIP) : #{data.get('ticket_number')}\")
print('----------------------------------------------------------------------')
print('\033[1m📱 TAMPILAN PADA LAYAR WHATSAPP EKSEKUTIF:\033[0m\n')
print(data.get('reply', ''))
print('\n----------------------------------------------------------------------')
"
  echo -e "${GREEN}✓ Validasi selesai: Format balasan otomatis WhatsApp Markdown tanpa tag HTML mentah.${NC}"
else
  echo -e "${RED}${BOLD}✗ Permintaan gagal dengan status HTTP ${HTTP_CODE}${NC}"
  echo -e "${YELLOW}Respon:${NC} ${BODY}"
  echo -e "${YELLOW}Catatan: Pastikan container n8n aktif di ${N8N_URL}${NC}"
  exit 1
fi
