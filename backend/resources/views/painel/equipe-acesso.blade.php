@extends('painel.layout')

@section('titulo', $titulo)

@section('conteudo')
    <h1 class="titulo">{{ $titulo }}</h1>

    <section class="resultado" role="status">
        <p><strong>{{ $nome }}</strong> · {{ $papel }}<br>{{ $email }}</p>

        <p class="campo__rotulo">Senha temporária</p>
        <code id="senha-temporaria" class="copiavel">{{ $senha }}</code>

        <button class="botao botao--enorme botao--principal" type="button" data-copiar="#senha-temporaria" data-copiar-aviso="#aviso-da-copia">Copiar</button>
        <p id="aviso-da-copia" class="aviso" role="status" aria-live="polite" hidden></p>

        <p class="aviso aviso--erro" role="alert">Anote ou copie agora: esta senha não aparece de novo. A pessoa precisa trocá-la no primeiro acesso.</p>
        <p class="ajuda">Se o botão não copiar, toque no texto da senha: ele fica todo selecionado, e aí é só tocar e segurar para copiar.</p>
    </section>

    <p><a class="botao" href="/painel/equipe">Voltar para a equipe</a></p>
@endsection
