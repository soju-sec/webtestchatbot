#!/bin/bash
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
  echo "⚠️  PERINGATAN: Docker belum terinstal pada sistem ini."
  echo "Silakan instal Docker untuk menjalankan container n8n."
else
  echo "✓ Docker terdeteksi: $(docker --version)"
fi

# 3. Validasi n8n workflow
if command -v node &> /dev/null; then
  echo "🔍 Memvalidasi sintaks n8n/universal-chatbot-workflow.json..."
  node -e "JSON.parse(require('fs').readFileSync('n8n/universal-chatbot-workflow.json'))"
  echo "✓ Validasi JSON workflow berhasil!"
fi

echo ""
echo "=========================================================="
echo "Instalasi selesai!"
echo "Langkah berikutnya:"
echo "1. Sesuaikan GEMINI_API_KEY & INTERNAL_API_KEY di file .env"
echo "2. Jalankan orkestrator n8n:"
echo "   docker compose up -d"
echo "3. Buka n8n di browser: http://localhost:5678"
echo "4. Import workflow dari n8n/universal-chatbot-workflow.json"
echo "5. Pasang package-backend ke aplikasi Laravel dan pasang chat-widget.js ke web frontend"
echo "=========================================================="
