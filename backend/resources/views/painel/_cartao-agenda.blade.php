<li class="cartao cartao--{{ $reserva['estado'] }}" data-id="{{ $reserva['id'] }}">
    <header class="cartao__topo">
        <p class="cartao__hora">{{ $mostrarDia ? $reserva['dia'].' · ' : '' }}{{ $reserva['hora'] }}</p>
        <h3 class="cartao__nome">{{ $reserva['nome'] }}</h3>
        <p class="etiqueta etiqueta--{{ $reserva['estado'] }}">{{ $reserva['estadoRotulo'] }}</p>
    </header>

    <dl class="cartao__dados">
        <div class="dado">
            <dt>Serviço</dt>
            <dd>{{ $reserva['servicos'] }} <span class="suave">({{ $reserva['duracao'] }}) · {{ $reserva['total'] }}</span></dd>
        </div>
        <div class="dado">
            <dt>Onde</dt>
            <dd>
                {{ $reserva['local'] }}
                @if ($reserva['domicilio'])
                    <br>{{ $reserva['endereco'] }}
                    @if ($reserva['regiao'])
                        <span class="suave">· {{ $reserva['regiao'] }}</span>
                    @endif
                @endif
            </dd>
        </div>
        @if ($reserva['observacao'])
            <div class="dado">
                <dt>Observação do cliente</dt>
                <dd>{{ $reserva['observacao'] }}</dd>
            </div>
        @endif
        @if ($reserva['telefone'])
            <div class="dado">
                <dt>Telefone</dt>
                <dd><a class="botao botao--ligar" href="tel:{{ $reserva['telefone'] }}">Ligar {{ $reserva['telefoneFormatado'] }}</a></dd>
            </div>
        @endif
    </dl>

    <div class="cartao__acoes">
        @if ($reserva['estado'] === 'confirmado')
            <form method="post" action="/painel/agenda/iniciar">
                @csrf
                <input type="hidden" name="reserva" value="{{ $reserva['codigo'] }}">
                <button class="botao botao--enorme botao--principal" type="submit">Iniciar atendimento</button>
            </form>
        @endif

        <form method="post" action="/painel/agenda/concluir">
            @csrf
            <input type="hidden" name="reserva" value="{{ $reserva['codigo'] }}">
            <button class="botao botao--enorme" type="submit">Concluir</button>
        </form>

        @if ($reserva['estado'] === 'confirmado')
            <form method="post" action="/painel/agenda/faltou">
                @csrf
                <input type="hidden" name="reserva" value="{{ $reserva['codigo'] }}">
                <button class="botao botao--enorme" type="submit">Não compareceu</button>
            </form>
        @endif

        <details class="recusa">
            <summary class="botao botao--enorme botao--perigo">Cancelar</summary>
            <form method="post" action="/painel/agenda/cancelar" class="formulario">
                @csrf
                <input type="hidden" name="reserva" value="{{ $reserva['codigo'] }}">
                <label class="campo">
                    <span class="campo__rotulo">Motivo do cancelamento (obrigatório, até 300 caracteres)</span>
                    <textarea name="motivo" rows="3" maxlength="300" required></textarea>
                </label>
                <button class="botao botao--perigo" type="submit">Confirmar cancelamento</button>
            </form>
        </details>
    </div>
</li>
