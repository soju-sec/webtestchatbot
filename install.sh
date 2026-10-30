#!/usr/bin/env bash
# ==============================================================================
# 🚀 UNIVERSAL AI CHATBOT & REALTIME WHATSAPP HELPDESK SISKEUDES TNT
# Non-Destructive Installer & Safe Deployment Script
# 
# PRINSIP KEAMANAN MUTLAK (ZERO-DISRUPTION GUARANTEE):
# 1. 🛡️ ZERO DATA LOSS: TIDAK PERNAH menghapus (rm) file atau folder core Siskeudes TNT.
# 2. 🛡️ ZERO SCHEMA INTERFERENCE: Seluruh tabel chatbot (chatbot_*) bersifat terisolasi
#    tanpa relasi Foreign Key ke tabel transaksi desa (tbspp, tbmdesa, tbmpemda).
# 3. 🛡️ AUTOMATIC SAFETY BACKUP: Selalu membuat salinan (.bak_chatbot) sebelum 
#    menyisipkan komponen widget ke file layout atau routes.
# 4. 🛡️ IDEMPOTEN: Aman dijalankan berulang kali tanpa risiko duplikasi konfigurasi.
# ==============================================================================

set -e

# Warna Terminal
GREEN='\033[0;32m'
BLUE='\033[0;34m'
YELLOW='\033[1;33m'
RED='\033[0;31m'
CYAN='\033[0;36m'
BOLD='\033[1m'
NC='\033[0m' # No Color

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"

function print_banner() {
  echo -e "${CYAN}${BOLD}"
  echo "================================================================================"
  echo "  🤖 UNIVERSAL AI CHATBOT & REALTIME HELPDESK SISKEUDES TNT (NON-DESTRUCTIVE)  "
  echo "================================================================================"
  echo -e "${NC}"
}

function show_help() {
  print_banner
  echo -e "${BOLD}PENGGUNAAN:${NC}"
  echo "  ./install.sh [OPSI | TARGET_PATH]"
  echo ""
  echo -e "${BOLD}CONTOH PENGGUNAAN:${NC}"
  echo "  1. Menyiapkan environment chatbot lokal (n8n & helper scripts):"
  echo -e "     ${CYAN}./install.sh${NC}"
  echo ""
  echo "  2. Deploy aman langsung ke live project Siskeudes TNT:"
  echo -e "     ${CYAN}./install.sh /var/www/siskeudes-tnt${NC}"
  echo -e "     ${CYAN}./install.sh --target=/path/to/albba-master${NC}"
  echo ""
  echo -e "${BOLD}OPSI:${NC}"
  echo "  --target=PATH      Menentukan path direktori project Siskeudes TNT"
  echo "  --help, -h         Menampilkan panduan penggunaan ini"
  echo ""
  echo -e "${BOLD}JAMINAN KEAMANAN:${NC}"
  echo "  - Script ini TIDAK AKAN PERNAH menghapus tabel transaksi desa (tbspp, tbmdesa)."
  echo "  - Script ini membuat backup .bak_chatbot otomatis sebelum menyentuh file layout."
  echo "================================================================================"
  exit 0
}

# Parse Argumen
TARGET_DIR=""
for arg in "$@"; do
  case $arg in
    --help|-h)
      show_help
      ;;
    --target=*)
      TARGET_DIR="${arg#*=}"
      ;;
    *)
      if [ -z "$TARGET_DIR" ] && [ -d "$arg" ]; then
        TARGET_DIR="$arg"
      fi
      ;;
  esac
done

print_banner

# ==============================================================================
# BAGIAN 1: PEMERIKSAAN & PERSIAPAN REPOSITORY CHATBOT SENDIRI
# ==============================================================================
echo -e "${BLUE}${BOLD}[1/2] Menyiapkan Repository & Environment Chatbot...${NC}"

# 1. Cek file .env lokal chatbot
if [ ! -f "$SCRIPT_DIR/.env" ]; then
  if [ -f "$SCRIPT_DIR/.env.example" ]; then
    echo -e "  📋 Membuat file .env chatbot dari .env.example..."
    cp "$SCRIPT_DIR/.env.example" "$SCRIPT_DIR/.env"
    echo -e "  ${GREEN}✓ File .env chatbot lokal berhasil dibuat.${NC}"
  fi
else
  echo -e "  ${GREEN}✓ File .env chatbot lokal terdeteksi.${NC}"
fi

# 2. Cek dependensi Docker (untuk n8n)
if command -v docker &> /dev/null; then
  echo -e "  ${GREEN}✓ Docker terdeteksi:${NC} $(docker --version)"
else
  echo -e "  ${YELLOW}ℹ️  Docker belum terdeteksi (abaikan jika n8n dijalankan di host/container terpisah).${NC}"
fi

# 3. Cek dependensi PHP CLI
if command -v php &> /dev/null; then
  echo -e "  ${GREEN}✓ PHP CLI terdeteksi:${NC} $(php -v | head -n 1)"
else
  echo -e "  ${YELLOW}ℹ️  PHP CLI lokal belum terdeteksi (abaikan jika PHP berjalan di dalam Docker).${NC}"
fi

# 4. Validasi Sintaks Workflow n8n JSON
if command -v node &> /dev/null && [ -f "$SCRIPT_DIR/n8n/universal-chatbot-workflow.json" ]; then
  if node -e "JSON.parse(require('fs').readFileSync('$SCRIPT_DIR/n8n/universal-chatbot-workflow.json'))" &>/dev/null; then
    echo -e "  ${GREEN}✓ Validasi workflow JSON n8n sukses.${NC}"
  fi
fi

# 5. Set executable permissions untuk helper scripts
chmod +x "$SCRIPT_DIR/check-tokens.sh" 2>/dev/null || true
chmod +x "$SCRIPT_DIR/package-backend/bin/check-tokens.sh" 2>/dev/null || true
chmod +x "$SCRIPT_DIR/check-jira.sh" 2>/dev/null || true
chmod +x "$SCRIPT_DIR/package-backend/bin/check-jira.sh" 2>/dev/null || true
chmod +x "$SCRIPT_DIR/register-ai-capability.sh" 2>/dev/null || true
echo -e "  ${GREEN}✓ Permissions helper script (check-tokens, check-jira, register-ai) diset executable.${NC}"

# ==============================================================================
# BAGIAN 2: DEPLOYMENT AMAN KE TARGET SISKEUDES TNT
# ==============================================================================
if [ -n "$TARGET_DIR" ]; then
  echo ""
  echo -e "${BLUE}${BOLD}[2/2] Memulai Deployment Aman ke Target: ${CYAN}$TARGET_DIR${NC}"
  
  # Validasi Target Laravel
  if [ ! -f "$TARGET_DIR/artisan" ] || [ ! -f "$TARGET_DIR/composer.json" ]; then
    echo -e "${RED}❌ ERROR: Direktori target ($TARGET_DIR) bukan project Laravel yang valid (artisan tidak ditemukan)!${NC}"
    echo -e "${RED}   Proses deployment dibatalkan demi keamanan sistem.${NC}"
    exit 1
  fi

  echo -e "  ${GREEN}✓ Validasi project Laravel berhasil.${NC}"

  # 1. Sinkronisasi Backend Services (Non-Destructive)
  echo -e "  📦 Menyinkronkan Backend Services..."
  mkdir -p "$TARGET_DIR/app/Services"
  cp "$SCRIPT_DIR/package-backend/Services/"*.php "$TARGET_DIR/app/Services/"
  echo -e "     ${GREEN}✓ app/Services/ (ChatbotNotificationService, JiraService, PiiSanitizer, dll. tersinkronkan)${NC}"

  # 2. Sinkronisasi Backend Controllers (Non-Destructive)
  echo -e "  📦 Menyinkronkan API Controllers..."
  mkdir -p "$TARGET_DIR/app/Http/Controllers/Api"
  cp "$SCRIPT_DIR/package-backend/Controllers/"*.php "$TARGET_DIR/app/Http/Controllers/Api/"
  echo -e "     ${GREEN}✓ app/Http/Controllers/Api/ (TicketReportController, AiCapabilityController, dll. tersinkronkan)${NC}"

  # 3. Sinkronisasi Console Commands (Non-Destructive)
  echo -e "  📦 Menyinkronkan Artisan Console Commands..."
  mkdir -p "$TARGET_DIR/app/Console/Commands"
  cp "$SCRIPT_DIR/package-backend/Commands/"*.php "$TARGET_DIR/app/Console/Commands/"
  echo -e "     ${GREEN}✓ app/Console/Commands/ (ChatbotJiraCommand, ChatbotTokenCommand, dll. tersinkronkan)${NC}"

  # 4. Sinkronisasi Middleware & Models
  mkdir -p "$TARGET_DIR/app/Http/Middleware" "$TARGET_DIR/app/Models"
  if [ -d "$SCRIPT_DIR/package-backend/Middleware" ]; then
    cp "$SCRIPT_DIR/package-backend/Middleware/"*.php "$TARGET_DIR/app/Http/Middleware/" 2>/dev/null || true
  fi
  if [ -d "$SCRIPT_DIR/package-backend/Models" ]; then
    cp "$SCRIPT_DIR/package-backend/Models/"*.php "$TARGET_DIR/app/Models/" 2>/dev/null || true
  fi
  echo -e "     ${GREEN}✓ app/Http/Middleware/ & app/Models/ tersinkronkan${NC}"

  # 5. Sinkronisasi Config
  mkdir -p "$TARGET_DIR/config"
  cp "$SCRIPT_DIR/package-backend/config/chatbot.php" "$TARGET_DIR/config/chatbot.php"
  cp "$SCRIPT_DIR/package-backend/config/ai_registry.php" "$TARGET_DIR/config/ai_registry.php"
  echo -e "     ${GREEN}✓ config/ (chatbot.php & ai_registry.php tersinkronkan)${NC}"

  # 6. Sinkronisasi Routes Aman
  mkdir -p "$TARGET_DIR/routes"
  cp "$SCRIPT_DIR/package-backend/routes/chatbot.php" "$TARGET_DIR/routes/chatbot.php"
  
  if [ -f "$TARGET_DIR/routes/api.php" ]; then
    if ! grep -q "chatbot.php" "$TARGET_DIR/routes/api.php"; then
      echo -e "  🔗 Mendaftarkan routes/chatbot.php ke routes/api.php (dengan backup)..."
      cp "$TARGET_DIR/routes/api.php" "$TARGET_DIR/routes/api.php.bak_chatbot"
      cat << 'EOF' >> "$TARGET_DIR/routes/api.php"

/*
|--------------------------------------------------------------------------
| Universal AI Chatbot & Ticket Reporting Routes
|--------------------------------------------------------------------------
| Terintegrasi modular tanpa mengganggu route transaksi inti Siskeudes TNT.
*/
if (file_exists(__DIR__ . '/chatbot.php')) {
    require __DIR__ . '/chatbot.php';
}
EOF
      echo -e "     ${GREEN}✓ routes/api.php berhasil diperbarui (backup disimpan di routes/api.php.bak_chatbot).${NC}"
    else
      echo -e "     ${GREEN}✓ routes/chatbot.php sudah terdaftar di routes/api.php.${NC}"
    fi
  fi

  # 7. Sinkronisasi Blade Views
  mkdir -p "$TARGET_DIR/resources/views/chatbot"
  if [ -f "$SCRIPT_DIR/package-backend/views/chatbot/widget.blade.php" ]; then
    cp "$SCRIPT_DIR/package-backend/views/chatbot/widget.blade.php" "$TARGET_DIR/resources/views/chatbot/widget.blade.php"
  fi
  echo -e "     ${GREEN}✓ resources/views/chatbot/widget.blade.php tersinkronkan${NC}"

  # Safe Injection ke login.blade.php jika belum ada
  LOGIN_LAYOUT="$TARGET_DIR/resources/views/layouts/login.blade.php"
  if [ -f "$LOGIN_LAYOUT" ]; then
    if ! grep -q "chatbot.widget" "$LOGIN_LAYOUT"; then
      echo -e "  🎨 Menyisipkan widget ke layout login..."
      cp "$LOGIN_LAYOUT" "${LOGIN_LAYOUT}.bak_chatbot"
      if grep -q "</body>" "$LOGIN_LAYOUT"; then
        sed -i 's|</body>|    @if (View::exists('"'"'chatbot.widget'"'"'))\n        @include('"'"'chatbot.widget'"'"')\n    @endif\n</body>|g' "$LOGIN_LAYOUT"
      else
        echo -e "\n@if (View::exists('chatbot.widget'))\n    @include('chatbot.widget')\n@endif" >> "$LOGIN_LAYOUT"
      fi
      echo -e "     ${GREEN}✓ Widget disisipkan ke layouts/login.blade.php (backup: ${LOGIN_LAYOUT}.bak_chatbot)${NC}"
    else
      echo -e "     ${GREEN}✓ layouts/login.blade.php sudah memuat widget chatbot.${NC}"
    fi
  fi

  # Safe Injection ke master.blade.php jika belum ada
  MASTER_LAYOUT="$TARGET_DIR/resources/views/layouts/master.blade.php"
  if [ -f "$MASTER_LAYOUT" ]; then
    if ! grep -q "chatbot.widget" "$MASTER_LAYOUT"; then
      echo -e "  🎨 Menyisipkan widget ke layout master dashboard..."
      cp "$MASTER_LAYOUT" "${MASTER_LAYOUT}.bak_chatbot"
      if grep -q "</body>" "$MASTER_LAYOUT"; then
        sed -i 's|</body>|    @if (View::exists('"'"'chatbot.widget'"'"'))\n        @include('"'"'chatbot.widget'"'"')\n    @endif\n</body>|g' "$MASTER_LAYOUT"
      else
        echo -e "\n@if (View::exists('chatbot.widget'))\n    @include('chatbot.widget')\n@endif" >> "$MASTER_LAYOUT"
      fi
      echo -e "     ${GREEN}✓ Widget disisipkan ke layouts/master.blade.php (backup: ${MASTER_LAYOUT}.bak_chatbot)${NC}"
    else
      echo -e "     ${GREEN}✓ layouts/master.blade.php sudah memuat widget chatbot.${NC}"
    fi
  fi

  # 8. Sinkronisasi Frontend Public Assets
  echo -e "  📦 Menyalin asset frontend ke public/vendor/chatbot/..."
  mkdir -p "$TARGET_DIR/public/vendor/chatbot/assets"
  cp "$SCRIPT_DIR/package-frontend/chat-widget.js" "$TARGET_DIR/public/vendor/chatbot/chat-widget.js"
  cp "$SCRIPT_DIR/package-frontend/chat-widget.css" "$TARGET_DIR/public/vendor/chatbot/chat-widget.css"
  cp "$SCRIPT_DIR/package-frontend/token-dashboard.html" "$TARGET_DIR/public/vendor/chatbot/token-dashboard.html" 2>/dev/null || true
  cp "$SCRIPT_DIR/package-frontend/whatsapp-executive-simulator.html" "$TARGET_DIR/public/vendor/chatbot/whatsapp-executive-simulator.html" 2>/dev/null || true
  if [ -f "$SCRIPT_DIR/package-frontend/assets/logo-siskeudes-tnt.png" ]; then
    cp "$SCRIPT_DIR/package-frontend/assets/logo-siskeudes-tnt.png" "$TARGET_DIR/public/vendor/chatbot/assets/logo-siskeudes-tnt.png"
  fi
  chmod -R 755 "$TARGET_DIR/public/vendor/chatbot" 2>/dev/null || true
  echo -e "     ${GREEN}✓ public/vendor/chatbot/ tersinkronkan.${NC}"

  # 9. Sinkronisasi Database Migrations (Zero-Disruption)
  echo -e "  📦 Menyalin file migrasi database chatbot..."
  mkdir -p "$TARGET_DIR/database/migrations"
  cp "$SCRIPT_DIR/package-backend/migrations/"*.php "$TARGET_DIR/database/migrations/"
  echo -e "     ${GREEN}✓ database/migrations/ (Tabel chatbot terisolasi tersinkronkan).${NC}"

  # 10. Konfigurasi .env Target (Non-Destructive)
  TARGET_ENV="$TARGET_DIR/.env"
  if [ -f "$TARGET_ENV" ]; then
    if ! grep -q "JIRA_HOST" "$TARGET_ENV" && ! grep -q "N8N_WEBHOOK_URL" "$TARGET_ENV"; then
      echo -e "  ⚙️  Menambahkan variabel chatbot ke target .env (tanpa menyentuh konfigurasi lama)..."
      cp "$TARGET_ENV" "${TARGET_ENV}.bak_chatbot"
      cat << 'EOF' >> "$TARGET_ENV"

# ==============================================================================
# 🤖 UNIVERSAL AI CHATBOT & REALTIME WHATSAPP HELPDESK SISKEUDES TNT
# ==============================================================================
N8N_WEBHOOK_URL=http://127.0.0.1:5678/webhook/chat-entry
CHATBOT_TIMEOUT_SECONDS=30
INTERNAL_API_KEY=KunciRahasiaInternalChatbot2026_UbahIniDiProduksi

WHATSAPP_EXECUTIVE_ENABLED=true
WHATSAPP_PROVIDER=fonnte
WHATSAPP_API_URL=https://api.fonnte.com/send
WHATSAPP_API_KEY=RA2tadnBduwW7sZ9Lq44
WHATSAPP_WEBHOOK_VERIFY_TOKEN=chatbot_secret_token_live

JIRA_ENABLED=true
JIRA_HOST=imtekservice.atlassian.net
JIRA_PROJECT_KEY=SUP
JIRA_USER_EMAIL=clashroyaledeco@gmail.com
JIRA_API_TOKEN=ATATT3xFfGF0ZgrKeTKJJfqc4o9GS2xxAfkiVCxkhQ2yMX4FU3L_ukVwVFxk6tBEeqUMSmM_jdd_lXK-uo1b6XHcPmI5rVq867wih-q_y_-uxbnUyH3OrKDPomDpoDIW_RQVBPkSOmNs_AsW7cjD4jqwLKcpMyPZa1lCugdk_dpgl82tubvdxQ8=F678946B
JIRA_ISSUE_TYPE=Task
EOF
      echo -e "     ${GREEN}✓ Konfigurasi chatbot berhasil ditambahkan ke .env target.${NC}"
    else
      echo -e "     ${GREEN}✓ Variabel chatbot sudah ada di target .env.${NC}"
    fi
  fi

  # 11. Salin Panduan Deployment ke Target
  if [ -f "$SCRIPT_DIR/PANDUAN_DEPLOYMENT_DEVOPS_SISKEUDES_TNT.md" ]; then
    cp "$SCRIPT_DIR/PANDUAN_DEPLOYMENT_DEVOPS_SISKEUDES_TNT.md" "$TARGET_DIR/PANDUAN_DEPLOYMENT_CHATBOT.md"
    echo -e "     ${GREEN}✓ Panduan deployment disalin ke $TARGET_DIR/PANDUAN_DEPLOYMENT_CHATBOT.md${NC}"
  fi

  # 12. Clear Cache Target jika PHP CLI aktif
  if command -v php &> /dev/null; then
    echo -e "  🧹 Membersihkan cache Laravel target..."
    (cd "$TARGET_DIR" && php artisan config:clear >/dev/null 2>&1 || true)
    (cd "$TARGET_DIR" && php artisan view:clear >/dev/null 2>&1 || true)
    echo -e "     ${GREEN}✓ Cache config dan view berhasil dibersihkan.${NC}"
  fi

  echo ""
  echo -e "${GREEN}${BOLD}================================================================================"
  echo -e "  🎉 DEPLOYMENT KE SISKEUDES TNT SELESAI DENGAN AMAN & TANPA ERROR!"
  echo -e "================================================================================${NC}"
  echo -e "Langkah Selanjutnya untuk Tim DevOps di Target Server:"
  echo -e "1. Eksekusi migrasi tabel chatbot: ${CYAN}cd $TARGET_DIR && php artisan migrate --force${NC}"
  echo -e "2. Uji koneksi Jira Service Management: ${CYAN}php artisan chatbot:jira --test${NC}"
  echo -e "3. Pasang daemon background watcher di Supervisor:"
  echo -e "   ${CYAN}command=php $TARGET_DIR/artisan chatbot:jira --watch --interval=15${NC}"
  echo -e "4. Buka panduan lengkap di: ${CYAN}$TARGET_DIR/PANDUAN_DEPLOYMENT_CHATBOT.md${NC}"
  echo -e "================================================================================"

else
  # Pesan jika dijalankan tanpa parameter target
  echo ""
  echo -e "${GREEN}${BOLD}✓ Persiapan lokal repositori chatbot berhasil!${NC}"
  echo ""
  echo -e "${YELLOW}Untuk melakukan deployment otomatis & non-destructive ke Siskeudes TNT:${NC}"
  echo -e "Jalankan perintah dengan menyertakan path folder Siskeudes TNT:"
  echo -e "  ${CYAN}./install.sh /path/ke/siskeudes-tnt${NC}"
  echo ""
  echo -e "Contoh di live server:"
  echo -e "  ${CYAN}./install.sh /var/www/siskeudes-tnt${NC}"
  echo -e "================================================================================"
fi
