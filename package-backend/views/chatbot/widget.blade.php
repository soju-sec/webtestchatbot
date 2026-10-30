{{-- 
|--------------------------------------------------------------------------
| Universal AI Chatbot Widget Component (Asisten Digital Siskeudes TNT)
|--------------------------------------------------------------------------
| Menampilkan widget chatbot floating di aplikasi Siskeudes TNT.
| Terintegrasi dengan Multi-turn Memory, PII Shield, Ticket Reporting,
| Dual-Mode Adaptive (Login Assistance vs Siskeudes TNT Dashboard),
| Pengecekan Pencairan SPP (Read-Only), dan SOP Resmi Kemendagri/BPKP.
--}}
@php
    $isLoggedIn = auth()->check();
    $currentUser = $isLoggedIn ? auth()->user() : null;
    $kabupatenName = $isLoggedIn ? ($currentUser->pemda->nama_pemda ?? $currentUser->pemda->namapemda ?? session('kabupaten', '')) : '';
    $desaName = $isLoggedIn ? ($currentUser->desa->nama_desa ?? $currentUser->desa->namadesa ?? session('desa', '')) : '';
    $userId = $isLoggedIn ? ($currentUser->userid ?? $currentUser->id ?? 'operator') : ('guest_' . substr(session()->getId(), 0, 8));
    $userName = $isLoggedIn ? ($currentUser->nama ?? $currentUser->name ?? $currentUser->username ?? 'Operator Siskeudes') : 'Pelapor Tamu';
    $mode = $isLoggedIn ? 'dashboard' : 'login';
    $botName = $isLoggedIn ? 'Asisten Digital Siskeudes TNT' : 'Helpdesk Login Siskeudes TNT';
@endphp

<link rel="stylesheet" href="{{ asset('/vendor/chatbot/chat-widget.css') }}?v={{ time() }}">
<script>
    window.CHATBOT_CONFIG = {
        apiBase: "{{ url('/api/v1') }}",
        webhookUrl: "{{ url('/api/webhook/chat-entry') }}",
        backendUrl: "{{ url('/') }}",
        logoUrl: "{{ asset('/vendor/chatbot/assets/logo-siskeudes-tnt.png') }}",
        title: "{{ $botName }}",
        botName: "{{ $botName }}",
        theme: "blue",
        userId: "{{ $userId }}",
        userName: "{{ $userName }}",
        kabupaten: "{{ $kabupatenName }}",
        desa: "{{ $desaName }}",
        mode: "{{ $mode }}",
        executiveUrl: "{{ asset('/vendor/chatbot/whatsapp-executive-simulator.html') }}"
    };
</script>
<script 
    src="{{ asset('/vendor/chatbot/chat-widget.js') }}?v={{ time() }}" 
    data-mode="{{ $mode }}"
    data-backend-url="{{ url('/') }}"
    data-webhook="{{ url('/api/webhook/chat-entry') }}"
    data-logo-url="{{ asset('/vendor/chatbot/assets/logo-siskeudes-tnt.png') }}"
    data-bot-name="{{ $botName }}"
    data-user-id="{{ $userId }}"
    data-user-name="{{ $userName }}"
    @if(!empty($kabupatenName)) data-kabupaten="{{ $kabupatenName }}" @endif
    @if(!empty($desaName)) data-desa="{{ $desaName }}" @endif
    data-tahun="{{ date('Y') }}"
    defer>
</script>
