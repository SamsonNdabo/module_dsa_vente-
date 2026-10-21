<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', 'Connexion') — {{ config('app.name') }}</title>

    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @stack('styles')
</head>
<body class="min-h-screen bg-gradient-to-br from-indigo-500 via-purple-500 to-purple-700
             flex items-center justify-center p-6 font-sans antialiased">

    <div class="w-full max-w-md">
        @yield('content')

        <p class="text-center text-sm text-white/80 mt-6">
            &copy; {{ date('Y') }} {{ config('app.name') }} — Tous droits réservés.
        </p>
    </div>

    <script>
        window.APP_BASE = "{{ url('/') }}";
    </script>
    <script src="{{ asset('js/api.js') }}?v={{ time() }}"></script>
    <script src="{{ asset('js/utils.js') }}?v={{ time() }}"></script>
    <script src="{{ asset('js/auth.js') }}?v={{ time() }}"></script>
    @stack('scripts')
</body>
</html>