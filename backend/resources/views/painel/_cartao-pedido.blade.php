<li class="cartao" data-id="{{ $pedido['id'] }}">
    <header class="cartao__topo">
        <h2 class="cartao__nome">{{ $pedido['nome'] }}</h2>
        @if ($pedido['expira'])
            <p class="cartao__expira">{{ $pedido['expira'] }}</p>
        @endif
    </header>

    <dl class="cartao__dados">
        <div class="dado">
            <dt>Quando</dt>
            <dd>{{ $pedido['dia'] }} às {{ $pedido['hora'] }} <span class="suave">({{ $pedido['duracao'] }})</span></dd>
        </div>
        <div class="dado">
            <dt>Serviço</dt>
            <dd>{{ $pedido['servicos'] }} <span class="suave">· {{ $pedido['total'] }}</span></dd>
        </div>
        <div class="dado">
            <dt>Onde</dt>
            <dd>
                {{ $pedido['local'] }}
                @if ($pedido['domicilio'])
                    <br>{{ $pedido['endereco'] }}
                    @if ($pedido['regiao'])
                        <span class="suave">· {{ $pedido['regiao'] }}</span>
                    @endif
                @endif
            </dd>
        </div>
        @if ($pedido['observacao'])
            <div class="dado">
                <dt>Observação do cliente</dt>
                <dd>{{ $pedido['observacao'] }}</dd>
            </div>
        @endif
        @if ($pedido['telefone'])
            <div class="dado">
                <dt>Telefone</dt>
                <dd><a class="botao botao--ligar" href="tel:{{ $pedido['telefone'] }}">Ligar {{ $pedido['telefoneFormatado'] }}</a></dd>
            </div>
        @endif
    </dl>

    <div class="cartao__acoes">
        <form method="post" action="/painel/pedidos/confirmar">
            @csrf
            <input type="hidden" name="reserva" value="{{ $pedido['codigo'] }}">
            <button class="botao botao--enorme botao--principal" type="submit">Confirmar</button>
        </form>

        <details class="recusa">
            <summary class="botao botao--enorme botao--perigo">Recusar</summary>
            <form method="post" action="/painel/pedidos/recusar" class="formulario">
                @csrf
                <input type="hidden" name="reserva" value="{{ $pedido['codigo'] }}">
                <label class="campo">
                    <span class="campo__rotulo">Motivo da recusa (obrigatório, até 300 caracteres)</span>
                    <textarea name="motivo" rows="3" maxlength="300" required></textarea>
                </label>
                <button class="botao botao--perigo" type="submit">Confirmar recusa</button>
            </form>
        </details>
    </div>
</li>
