@extends('painel.layout')

@section('titulo', 'Trocar senha')

@section('conteudo')
    <h1 class="titulo">Minha conta</h1>
    <p class="ajuda">Você entrou como <strong>{{ auth()->user()->name }}</strong> ({{ auth()->user()->papel->rotulo() }}).</p>

    <h2 class="subtitulo">Trocar minha senha</h2>

    @if ($temporaria)
        <p class="aviso" role="status">Por segurança, troque a senha temporária antes de continuar.</p>
    @endif

    @if (isset($erro) || session('erro'))
        <p class="aviso aviso--erro" role="alert">{{ $erro ?? session('erro') }}</p>
    @endif

    @foreach ($errors->all() as $mensagem)
        <p class="aviso aviso--erro" role="alert">{{ $mensagem }}</p>
    @endforeach

    <form method="post" action="/painel/conta/senha" class="formulario">
        @csrf
        <label class="campo">
            <span class="campo__rotulo">Senha atual</span>
            <input type="password" name="senha_atual" required maxlength="200" autocomplete="current-password">
        </label>
        <label class="campo">
            <span class="campo__rotulo">Senha nova (12 a 72 caracteres, com letras e números)</span>
            <input type="password" name="senha_nova" required minlength="12" maxlength="72" autocomplete="new-password">
        </label>
        <label class="campo">
            <span class="campo__rotulo">Repita a senha nova</span>
            <input type="password" name="senha_nova_confirmation" required minlength="12" maxlength="72" autocomplete="new-password">
        </label>
        <button class="botao botao--principal" type="submit">Trocar senha</button>
    </form>

    <h2 class="subtitulo">Sair</h2>
    <form method="post" action="/painel/sair" class="formulario">
        @csrf
        <button class="botao botao--enorme botao--perigo" type="submit">Sair</button>
    </form>

    <h2 class="subtitulo">Sair</h2>
    <form method="post" action="/painel/sair" class="formulario">
        @csrf
        <button class="botao botao--enorme botao--perigo" type="submit">Sair</button>
    </form>

    @unless ($temporaria)
        <p class="ajuda"><a href="/painel">Voltar</a></p>
    @endunless
@endsection
