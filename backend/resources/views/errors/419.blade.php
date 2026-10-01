@extends('painel.layout')

@section('titulo', 'Página expirada')

@section('conteudo')
    <h1 class="titulo">Sua página expirou</h1>
    <p class="ajuda">Por segurança, a página ficou aberta por muito tempo. Volte e tente de novo.</p>
    <p><a class="botao botao--principal" href="/painel">Voltar ao painel</a></p>
@endsection
