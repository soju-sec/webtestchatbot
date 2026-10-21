#!/usr/bin/env bash
# ==============================================================================
# Script Terpisah untuk Cek Sisa Token & Pemakaian Gemini AI
# Berjalan secara native maupun di dalam container Docker tanpa merusak sistem.
# ==============================================================================

set -e

# Warna Terminal
CYAN='\033[0;36m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
RED='\033[0;31m'
NC='\033[0m' # No Color
BOLD='\033[1m'

# Load .env jika ada
if [ -f .env ]; then
  export $(grep -v '^#' .env | xargs -d '\n' 2>/dev/null || true)
elif [ -f ../.env ]; then
  export $(grep -v '^#' ../.env | xargs -d '\n' 2>/dev/null || true)
fi

BACKEND_URL="${BACKEND_BASE_URL:-http://127.0.0.1:8000}"
API_KEY="${INTERNAL_API_KEY:-your_secure_internal_api_key_here}"
ENDPOINT="${BACKEND_URL}/api/v1/ai/tokens/summary"

echo -e "${CYAN}${BOLD}======================================================================${NC}"
echo -e "${CYAN}${BOLD}     GEMINI AI TOKEN USAGE & REMAINING QUOTA CHECKER (TERPISAH)       ${NC}"
echo -e "${CYAN}${BOLD}======================================================================${NC}"
echo -e "Menghubungi endpoint: ${ENDPOINT}..."

RESPONSE=$(curl -s -w "\n%{http_code}" -X GET "${ENDPOINT}" \
  -H "X-Internal-API-Key: ${API_KEY}" \
  -H "Accept: application/json" || true)

HTTP_BODY=$(echo "$RESPONSE" | sed '$d')
HTTP_CODE=$(echo "$RESPONSE" | tail -n 1)

if [ "$HTTP_CODE" != "200" ]; then
  echo -e "${YELLOW}⚠️  Gagal mendapatkan respon 200 OK dari endpoint backend (HTTP ${HTTP_CODE}).${NC}"
  echo -e "Respon mentah: ${HTTP_BODY}"
  echo ""
  echo -e "${CYAN}💡 Tips:${NC}"
  echo -e "1. Pastikan server backend Anda sedang berjalan (contoh: port 8000 / 8088)."
  echo -e "2. Pastikan INTERNAL_API_KEY di file .env sesuai."
  echo -e "3. Jika menggunakan Docker, periksa BACKEND_BASE_URL (misal http://host.docker.internal:8000)."
  exit 1
fi

if command -v jq &> /dev/null; then
  MODEL=$(echo "$HTTP_BODY" | jq -r '.model // "gemini-2.5-flash"')
  LIMIT=$(echo "$HTTP_BODY" | jq -r '.quota.monthly_limit // 0')
  USED_MONTH=$(echo "$HTTP_BODY" | jq -r '.quota.used_this_month // 0')
  REMAINING=$(echo "$HTTP_BODY" | jq -r '.quota.remaining_tokens // 0')
  PERCENT=$(echo "$HTTP_BODY" | jq -r '.quota.used_percentage // 0')
  STATUS_LABEL=$(echo "$HTTP_BODY" | jq -r '.quota.status_label // "Normal"')
  STATUS=$(echo "$HTTP_BODY" | jq -r '.quota.status // "safe"')
  TODAY_TOTAL=$(echo "$HTTP_BODY" | jq -r '.usage.today.total // 0')
  TODAY_REQS=$(echo "$HTTP_BODY" | jq -r '.usage.today.requests // 0')
  ALL_TOTAL=$(echo "$HTTP_BODY" | jq -r '.usage.all_time.total // 0')
  ALL_REQS=$(echo "$HTTP_BODY" | jq -r '.usage.all_time.requests // 0')

  STATUS_COLOR=$GREEN
  if [ "$STATUS" = "warning" ]; then
    STATUS_COLOR=$YELLOW
  elif [ "$STATUS" = "exceeded" ]; then
    STATUS_COLOR=$RED
  fi

  KEY_COUNT=$(echo "$HTTP_BODY" | jq -r '.key_pool.total_keys // 1')
  KEY_STRATEGY=$(echo "$HTTP_BODY" | jq -r '.key_pool.rotation_strategy // "Single Key"')
  COMBINED_RPM=$(echo "$HTTP_BODY" | jq -r '.model_limits.combined_rpm // "15 RPM"')
  COMBINED_RPD=$(echo "$HTTP_BODY" | jq -r '.model_limits.combined_rpd // "1,500 RPD"')

  echo ""
  echo -e "🤖 Model AI       : ${BOLD}${MODEL}${NC}"
  echo -e "🔑 API Key Pool   : ${CYAN}${BOLD}${KEY_COUNT} Key Aktif${NC} (${KEY_STRATEGY})"
  echo -e "⚡ Kapasitas Gab  : ${BOLD}${COMBINED_RPM} | ${COMBINED_RPD}${NC}"
  echo -e "🎯 Status Kuota   : ${STATUS_COLOR}${BOLD}${STATUS_LABEL} (${PERCENT}% terpakai)${NC}"
  echo -e "----------------------------------------------------------------------"
  echo -e "💰 Batas Kuota Bulanan (Pool): ${BOLD}$(printf "%'d" "$LIMIT" 2>/dev/null || echo "$LIMIT") token${NC}"
  echo -e "📈 Total Terpakai Bulan Ini  : ${BOLD}$(printf "%'d" "$USED_MONTH" 2>/dev/null || echo "$USED_MONTH") token${NC}"
  echo -e "⏳ Estimasi SISA TOKEN       : ${STATUS_COLOR}${BOLD}$(printf "%'d" "$REMAINING" 2>/dev/null || echo "$REMAINING") token${NC}"
  echo -e "----------------------------------------------------------------------"
  echo -e "📅 Pemakaian Hari Ini        : $(printf "%'d" "$TODAY_TOTAL" 2>/dev/null || echo "$TODAY_TOTAL") token (${TODAY_REQS} requests)"
  echo -e "🌐 Pemakaian Total All-Time  : $(printf "%'d" "$ALL_TOTAL" 2>/dev/null || echo "$ALL_TOTAL") token (${ALL_REQS} requests)"
  echo -e "----------------------------------------------------------------------"
else
  # Tampilkan JSON jika jq tidak tersedia
  echo ""
  echo -e "${GREEN}✓ Respon Berhasil Diterima:${NC}"
  echo "$HTTP_BODY"
fi

echo ""
echo -e "${CYAN}✓ Pengecekan token selesai tanpa mengganggu sistem produksi.${NC}"
exit 0
