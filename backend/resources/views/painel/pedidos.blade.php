@extends('painel.layout')

@section('titulo', ($total > 0 ? "({$total}) " : '').'Pedidos')

@section('conteudo')
    <div class="topo">
        <h1 class="titulo">Pedidos</h1>
        <a class="botao botao--pequeno" id="atualizar-agora" href="/painel">Atualizar agora</a>
    </div>

    <p id="faixa-novos" class="faixa" role="status" aria-live="polite" hidden></p>
    <p id="estado-da-conexao" class="aviso" role="status" aria-live="polite" hidden></p>

    @if (session('erro'))
        <p class="aviso aviso--erro" role="alert">{{ session('erro') }}</p>
    @endif
    @if (session('sucesso'))
        <p class="aviso" role="status">{{ session('sucesso') }}</p>
    @endif

    @if ($resultado)
        <section class="resultado" role="status">
            <h2 class="resultado__titulo">{{ $resultado['tipo'] === 'confirmado' ? 'Pedido confirmado' : 'Pedido recusado' }}</h2>
            <p>{{ $resultado['reserva']['nome'] }} · {{ $resultado['reserva']['servicos'] }} · {{ $resultado['reserva']['dia'] }} às {{ $resultado['reserva']['hora'] }}</p>
            @if ($resultado['link'])
                <a class="botao botao--enorme botao--whatsapp" href="{{ $resultado['link'] }}" target="_blank" rel="noopener noreferrer">Avisar cliente no WhatsApp</a>
                <p class="ajuda">O WhatsApp abre com a mensagem pronta; ela só sai quando você tocar em enviar.</p>
            @else
                <p class="ajuda">Este cliente não tem telefone cadastrado para avisar.</p>
            @endif
        </section>
    @endif

    <ol id="lista-de-pedidos" class="lista-de-cartoes"
        data-resumo="/painel/pedidos/resumo"
        data-ids="{{ json_encode(array_column($pedidos, 'id')) }}"
        data-intervalo="25">
        @forelse ($pedidos as $pedido)
            @include('painel._cartao-pedido', ['pedido' => $pedido])
        @empty
            <li class="vazio" id="sem-pedidos">Nenhum pedido esperando você agora.</li>
        @endforelse
    </ol>
@endsection
