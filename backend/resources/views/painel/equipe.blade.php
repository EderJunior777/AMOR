@extends('painel.layout')

@section('titulo', 'Equipe')

@section('conteudo')
    <h1 class="titulo">Equipe</h1>

    @if (session('erro'))
        <p class="aviso aviso--erro" role="alert">{{ session('erro') }}</p>
    @endif
    @if (session('sucesso'))
        <p class="aviso" role="status">{{ session('sucesso') }}</p>
    @endif

    <ol class="lista-de-cartoes">
        @foreach ($pessoas as $pessoa)
            <li class="cartao">
                <header class="cartao__topo">
                    <h2 class="cartao__nome">{{ $pessoa['nome'] }}</h2>
                    <p class="etiqueta @if (! $pessoa['ativo']) etiqueta--inativo @endif">{{ $pessoa['papel'] }}@if (! $pessoa['ativo']) · Desativado @endif</p>
                </header>
                <dl class="cartao__dados">
                    <div class="dado"><dt>E-mail</dt><dd>{{ $pessoa['email'] }}</dd></div>
                    @if ($pessoa['profissional'])
                        <div class="dado"><dt>Profissional</dt><dd>{{ $pessoa['profissional'] }}</dd></div>
                    @endif
                    <div class="dado">
                        <dt>Último acesso</dt>
                        <dd>{{ $pessoa['ultimoAcesso'] ?? 'Ainda não entrou' }}@if ($pessoa['senhaTemporaria']) <span class="suave">· ainda com a senha temporária</span>@endif</dd>
                    </div>
                </dl>

                @unless ($pessoa['ehProprietario'])
                    <div class="cartao__acoes">
                        @if ($pessoa['ativo'])
                            <form method="post" action="/painel/equipe/redefinir-senha">
                                @csrf
                                <input type="hidden" name="usuario" value="{{ $pessoa['id'] }}">
                                <button class="botao" type="submit">Redefinir senha</button>
                            </form>
                            <form method="post" action="/painel/equipe/desativar">
                                @csrf
                                <input type="hidden" name="usuario" value="{{ $pessoa['id'] }}">
                                <button class="botao botao--perigo" type="submit">Desativar</button>
                            </form>
                        @else
                            <form method="post" action="/painel/equipe/reativar">
                                @csrf
                                <input type="hidden" name="usuario" value="{{ $pessoa['id'] }}">
                                <button class="botao botao--principal" type="submit">Reativar (gera senha nova)</button>
                            </form>
                        @endif
                    </div>
                @endunless
            </li>
        @endforeach
    </ol>

    <section class="cartao novo-acesso">
        <h2 class="cartao__nome">Novo acesso</h2>
        <p class="ajuda">Barbeiro ou recepção. Uma senha temporária é gerada e mostrada uma única vez; a pessoa troca no primeiro acesso.</p>
        <form method="post" action="/painel/equipe/criar" class="formulario">
            @csrf
            <label class="campo">
                <span class="campo__rotulo">Nome</span>
                <input type="text" name="nome" required minlength="2" maxlength="120" autocomplete="off">
            </label>
            <label class="campo">
                <span class="campo__rotulo">E-mail</span>
                <input type="email" name="email" required maxlength="254" autocomplete="off" autocapitalize="none" inputmode="email">
            </label>
            <label class="campo">
                <span class="campo__rotulo">Papel</span>
                <select name="papel" required>
                    <option value="barbeiro">Barbeiro</option>
                    <option value="recepcao">Recepção</option>
                </select>
            </label>
            <label class="campo">
                <span class="campo__rotulo">Profissional (só para barbeiro)</span>
                <select name="profissional_id">
                    <option value="">Nenhum</option>
                    @foreach ($profissionaisLivres as $profissional)
                        <option value="{{ $profissional->id }}">{{ $profissional->nome_exibicao }}</option>
                    @endforeach
                </select>
            </label>
            <button class="botao botao--enorme botao--principal" type="submit">Criar acesso</button>
        </form>
    </section>
@endsection
