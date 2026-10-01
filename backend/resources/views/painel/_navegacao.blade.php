<nav class="navegacao" aria-label="Menu do painel">
    <a class="navegacao__item @if (request()->is('painel')) navegacao__item--atual @endif" href="/painel" @if (request()->is('painel')) aria-current="page" @endif>Pedidos</a>
    <a class="navegacao__item @if (request()->is('painel/agenda')) navegacao__item--atual @endif" href="/painel/agenda" @if (request()->is('painel/agenda')) aria-current="page" @endif>Agenda</a>
    <a class="navegacao__item @if (request()->is('painel/conta/*')) navegacao__item--atual @endif" href="/painel/conta/senha" @if (request()->is('painel/conta/*')) aria-current="page" @endif>Conta</a>
</nav>
