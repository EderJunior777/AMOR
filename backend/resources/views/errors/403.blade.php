@extends('painel.layout')

@section('titulo', 'Acesso negado')

@section('conteudo')
    <h1 class="titulo">Acesso negado</h1>
    <p class="ajuda">Você não tem permissão para fazer isso.</p>
    <p><a class="botao" href="/painel">Voltar ao painel</a></p>
@endsection
