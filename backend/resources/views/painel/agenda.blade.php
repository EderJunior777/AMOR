@extends('painel.layout')

@section('titulo', 'Agenda')

@section('conteudo')
    <div class="topo">
        <h1 class="titulo">Agenda</h1>
        <a class="botao botao--pequeno" href="/painel/agenda">Atualizar</a>
    </div>

    @if (session('erro'))
        <p class="aviso aviso--erro" role="alert">{{ session('erro') }}</p>
    @endif
    @if (session('sucesso'))
        <p class="aviso" role="status">{{ session('sucesso') }}</p>
    @endif

    @if ($resultado)
        <section class="resultado" role="status">
            <h2 class="resultado__titulo">Reserva cancelada</h2>
            <p>{{ $resultado['reserva']['nome'] }} · {{ $resultado['reserva']['servicos'] }} · {{ $resultado['reserva']['dia'] }} às {{ $resultado['reserva']['hora'] }}</p>
            @if ($resultado['link'])
                <a class="botao botao--enorme botao--whatsapp" href="{{ $resultado['link'] }}" target="_blank" rel="noopener noreferrer">Avisar cliente no WhatsApp</a>
                <p class="ajuda">O WhatsApp abre com a mensagem pronta; ela só sai quando você tocar em enviar.</p>
            @else
                <p class="ajuda">Este cliente não tem telefone cadastrado para avisar.</p>
            @endif
        </section>
    @endif

    @if ($vazia)
        <p class="vazio">Nenhuma reserva confirmada nos próximos dias.</p>
    @endif

    @if (count($atrasadas) > 0)
        <section class="dia dia--atrasadas">
            <h2 class="dia__titulo">Atrasadas (de dias anteriores)</h2>
            <p class="ajuda">Ainda abertas: conclua ou marque a falta.</p>
            <ol class="lista-de-cartoes">
                @foreach ($atrasadas as $reserva)
                    @include('painel._cartao-agenda', ['reserva' => $reserva, 'mostrarDia' => true])
                @endforeach
            </ol>
        </section>
    @endif

    @foreach ($dias as $dia)
        <section class="dia">
            <h2 class="dia__titulo">{{ $dia['titulo'] }}</h2>
            <ol class="lista-de-cartoes">
                @foreach ($dia['reservas'] as $reserva)
                    @include('painel._cartao-agenda', ['reserva' => $reserva, 'mostrarDia' => false])
                @endforeach
            </ol>
        </section>
    @endforeach
@endsection
