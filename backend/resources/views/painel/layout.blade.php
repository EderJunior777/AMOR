<!doctype html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <meta name="color-scheme" content="light dark">
    <title>@yield('titulo', 'Painel') · Barbearia</title>
    <link rel="stylesheet" href="/painel/painel.css">
    <script src="/painel/painel.js" defer></script>
</head>
<body class="@auth com-navegacao @endauth">
    <main class="pagina">
        @yield('conteudo')
    </main>
    @auth
        @include('painel._navegacao')
    @endauth
</body>
</html>
