<?php

namespace App\Http\Controllers\Painel;

use App\Domain\Painel\ConsultasDoPainel;
use App\Http\Controllers\Controller;
use App\Models\Estabelecimento;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Agenda: confirmadas e em atendimento de hoje e dos proximos dias, agrupadas
 * por dia (as dos dias anteriores ainda abertas vem primeiro, como "Atrasadas").
 */
final class AgendaController extends Controller
{
    use ComReservasNaTela;

    /** Hoje e os 6 dias seguintes. */
    private const DIAS = 7;

    public function __construct(private readonly ConsultasDoPainel $consultas) {}

    public function index(Request $request): View
    {
        $usuario = $request->user();
        $fuso = Estabelecimento::atual()?->fuso_horario ?? 'America/Sao_Paulo';
        $hoje = CarbonImmutable::now($fuso)->startOfDay();

        $reservas = $this->apresentar($this->consultas->agenda($usuario, $fuso, self::DIAS));

        $atrasadas = array_values(array_filter($reservas, fn (array $r) => $r['dataIso'] < $hoje->format('Y-m-d')));
        $porDia = [];
        foreach ($reservas as $reserva) {
            if ($reserva['dataIso'] >= $hoje->format('Y-m-d')) {
                $porDia[$reserva['dataIso']][] = $reserva;
            }
        }
        ksort($porDia);

        $dias = [];
        foreach ($porDia as $data => $itens) {
            $dias[] = ['titulo' => $this->tituloDoDia((string) $data, $hoje), 'reservas' => $itens];
        }

        return view('painel.agenda', [
            'atrasadas' => $atrasadas,
            'dias' => $dias,
            'resultado' => $this->resultadoDaAcao($request, $usuario, $this->consultas),
            'vazia' => $reservas === [],
        ]);
    }

    private function tituloDoDia(string $data, CarbonImmutable $hoje): string
    {
        $dia = CarbonImmutable::parse($data, $hoje->getTimezone())->locale('pt_BR');
        $texto = $dia->translatedFormat('l, d/m');

        return match (true) {
            $dia->isSameDay($hoje) => "Hoje · {$texto}",
            $dia->isSameDay($hoje->addDay()) => "Amanhã · {$texto}",
            default => $texto,
        };
    }
}
