<!doctype html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <meta name="color-scheme" content="light dark">
    <title>@yield('titulo', 'Painel') · Barbearia</title>
    {{-- Estaticos FORA de /painel: uma pasta public/painel colidiria com a rota /painel (o servidor serviria a pasta, nao o Laravel). --}}
    <link rel="stylesheet" href="/recursos-do-painel/painel.css">
    <script src="/recursos-do-painel/painel.js" defer></script>
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
