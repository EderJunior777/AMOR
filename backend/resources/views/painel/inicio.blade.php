@extends('painel.layout')

@section('titulo', 'Início')

@section('conteudo')
    <h1 class="titulo">Olá, {{ $usuario->name }}</h1>

    @if (session('sucesso'))
        <p class="aviso" role="status">{{ session('sucesso') }}</p>
    @endif
    <p class="ajuda">A tela de pedidos chega na próxima entrega.</p>

    <nav class="acoes">
        <a class="botao" href="/painel/conta/senha">Trocar minha senha</a>
    </nav>

    <form method="post" action="/painel/sair" class="formulario">
        @csrf
        <button class="botao" type="submit">Sair</button>
    </form>
@endsection
