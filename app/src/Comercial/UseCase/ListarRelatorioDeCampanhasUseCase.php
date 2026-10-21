<?php

declare(strict_types=1);

namespace App\Comercial\UseCase;

use App\Comercial\Campanha\PublicosDaCampanha;
use App\Comercial\DTO\LinhaDoRelatorio;
use App\Comercial\DTO\MetricasDaCampanha;
use App\Comercial\DTO\PedidoDoRelatorio;
use App\Comercial\DTO\RelatorioDeCampanhas;
use App\Comercial\Entity\CampanhaDeEmail;
use App\Comercial\Repository\RelatorioDeCampanhasRepository;

/**
 * A lista do "Relatório como caixa de entrada" da Central de envios (desenho 05 VS 21.10.26, L300-305 e L698-717;
 * spec `comercial-relatorio.md`): a contagem das 3 abas (Enviadas, Agendadas, Recorrentes), a busca por campanha ou
 * e-mail, a página de 50 (no SQL) e as métricas de cada linha, numa consulta só para a página inteira.
 *
 * O estado `teste` (a 5d grava o "Enviar teste" como campanha) cai em Enviadas com o selo "Teste"; sem ele, nada muda.
 *
 * @phpstan-import-type LinhaDaCampanha from RelatorioDeCampanhasRepository
 */
final class ListarRelatorioDeCampanhasUseCase
{
    public const POR_PAGINA = 50;
    public const TESTE = 'teste';
    /** O selo da linha (dc L714): os 4 do dc e os estados reais que o protótipo não tinha. */
    public const ROTULOS = [
        self::TESTE => 'Teste', CampanhaDeEmail::RECORRENTE => 'Recorrente', CampanhaDeEmail::AGENDADA => 'Agendada',
        CampanhaDeEmail::NA_FILA => 'Na fila', CampanhaDeEmail::ENVIANDO => 'Enviando', CampanhaDeEmail::ENVIADA => 'Enviada',
        CampanhaDeEmail::CANCELADA => 'Cancelada',
    ];

    public function __construct(private readonly RelatorioDeCampanhasRepository $relatorio)
    {
    }

    public function executar(PedidoDoRelatorio $pedido): RelatorioDeCampanhas
    {
        $abas = $this->relatorio->contarPorAba();
        $total = $this->relatorio->contar($pedido->aba, $pedido->busca);
        $pagina = min($pedido->pagina, max(1, (int) ceil($total / self::POR_PAGINA)));
        $linhas = $total === 0 ? [] : $this->relatorio->pagina($pedido->aba, $pedido->busca, self::POR_PAGINA, ($pagina - 1) * self::POR_PAGINA);
        $metricas = $this->relatorio->metricas(array_map(static fn (array $l): int => $l['id'], $linhas));

        return new RelatorioDeCampanhas(
            $pedido->aba,
            $abas,
            array_map(static fn (array $l): LinhaDoRelatorio => self::linha($l, $metricas[$l['id']] ?? null), $linhas),
            $total,
            $pagina,
            self::POR_PAGINA,
        );
    }

    /**
     * A métrica da campanha que ainda não começou: os destinatários confirmados, todos na fila.
     *
     * @param array{total: int, execucoes: int} $c
     * @param array{destinatarios: int, na_fila: int, enviados: int, entregues: int, lidos: int, rejeitados: int, clicaram: int}|null $m
     */
    public static function metricas(array $c, ?array $m): MetricasDaCampanha
    {
        return MetricasDaCampanha::deArray($m, $c['execucoes'] === 0 ? $c['total'] : 0);
    }

    /**
     * @param LinhaDaCampanha $c
     * @param array{destinatarios: int, na_fila: int, enviados: int, entregues: int, lidos: int, rejeitados: int, clicaram: int}|null $m
     */
    private static function linha(array $c, ?array $m): LinhaDoRelatorio
    {
        $sub = $c['modelo_nome'] . ($c['frequencia'] !== CampanhaDeEmail::UNICA ? ' · ' . $c['frequencia'] : '');
        $data = (new \DateTimeImmutable($c['agendada_para'] ?? $c['criada_em']))->format('Y-m-d\TH:i:s');

        return new LinhaDoRelatorio(
            $c['id'],
            $c['nome'],
            $sub,
            PublicosDaCampanha::ROTULOS[$c['publico']] ?? $c['publico'],
            $c['total'],
            $c['estado'],
            self::ROTULOS[$c['estado']] ?? $c['estado'],
            $data,
            self::metricas($c, $m),
        );
    }
}
