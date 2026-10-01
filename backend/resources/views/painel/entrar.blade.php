@extends('painel.layout')

@section('titulo', 'Entrar')

@section('conteudo')
    <h1 class="titulo">Painel do barbeiro</h1>

    @if (isset($erro) || session('erro'))
        <p class="aviso aviso--erro" role="alert">{{ $erro ?? session('erro') }}</p>
    @endif

    <form method="post" action="/painel/entrar" class="formulario">
        @csrf
        <label class="campo">
            <span class="campo__rotulo">E-mail</span>
            <input type="email" name="email" value="{{ old('email') }}" required maxlength="254"
                   autocomplete="username" autocapitalize="none" autocorrect="off" spellcheck="false" inputmode="email">
        </label>
        <label class="campo">
            <span class="campo__rotulo">Senha</span>
            <input type="password" name="senha" required maxlength="200" autocomplete="current-password">
        </label>
        <button class="botao botao--principal" type="submit">Entrar</button>
    </form>

    <p class="ajuda">Esqueceu a senha? Peça ao proprietário para redefinir.</p>
@endsection
