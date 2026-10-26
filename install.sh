#!/usr/bin/env bash
set -e

echo "=========================================================="
echo "      Universal Chatbot - Installation & Setup Script     "
echo "=========================================================="

# 1. Cek .env file
if [ ! -f .env ]; then
  if [ -f .env.example ]; then
    echo "📋 Membuat file .env dari .env.example..."
    cp .env.example .env
    echo "✓ File .env berhasil dibuat. Silakan sesuaikan variabel di .env jika diperlukan."
  fi
else
  echo "✓ File .env sudah ada."
fi

# 2. Cek dependensi Docker
if ! command -v docker &> /dev/null; then
  echo "ℹ️  Docker belum terinstal pada sistem ini (Mode Native)."
  echo "   Jika ingin menjalankan n8n secara native, Anda dapat menggunakan npm install -g n8n."
else
  echo "✓ Docker terdeteksi: $(docker --version)"
fi

# 3. Cek dependensi PHP (untuk backend Laravel)
if command -v php &> /dev/null; then
  echo "✓ PHP terdeteksi: $(php -v | head -n 1)"
else
  echo "ℹ️  PHP CLI belum terdeteksi (abaikan jika backend Laravel berjalan di container lain)."
fi

# 4. Validasi n8n workflow
if command -v node &> /dev/null; then
  echo "🔍 Memvalidasi sintaks n8n/universal-chatbot-workflow.json..."
  node -e "JSON.parse(require('fs').readFileSync('n8n/universal-chatbot-workflow.json'))"
  echo "✓ Validasi JSON workflow berhasil!"
fi

# 5. Set executable permissions untuk script helper
chmod +x check-tokens.sh 2>/dev/null || true
chmod +x package-backend/bin/check-tokens.sh 2>/dev/null || true
chmod +x check-jira.sh 2>/dev/null || true
chmod +x package-backend/bin/check-jira.sh 2>/dev/null || true
chmod +x register-ai-capability.sh 2>/dev/null || true
echo "✓ Permissions script bantuan (check-tokens, check-jira & register-ai-capability) diset executable."

echo ""
echo "=========================================================="
echo "Instalasi selesai!"
echo "Fitur Unggulan:"
echo "1. 🛡️  Database Terisolasi: Tidak mengganggu tabel aplikasi produksi."
echo "2. 🌐  Jira Service Management: Tiket resmi otomatis di imtekservice.atlassian.net / SUP"
echo "3. 🪙  Token Checker Terpisah: ./check-tokens.sh atau buka token-dashboard.html"
echo "4. 🐳  Kompatibel Docker & Native: Mendukung host.docker.internal & multi-network"
echo ""
echo "Langkah berikutnya:"
echo "A. Sesuaikan GEMINI_API_KEY & INTERNAL_API_KEY di file .env"
echo "B. Konfigurasikan JIRA_USER_EMAIL & JIRA_API_TOKEN di file .env lalu uji dengan:"
echo "   ./check-jira.sh"
echo "C. Jalankan orkestrator n8n:"
echo "   docker compose up -d"
echo "D. Salin package-backend/ ke aplikasi Laravel dan jalankan migrasi:"
echo "   php artisan migrate"
echo "=========================================================="
