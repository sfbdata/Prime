<?php

declare(strict_types=1);

namespace App\Comercial\UseCase;

use App\Comercial\Service\RastreioDaLeitura;
use App\Comercial\Campanha\PublicosDaCampanha;
use App\Comercial\DTO\LinhaDoRelatorio;
use App\Comercial\DTO\MetricasDaCampanha;
use App\Comercial\DTO\PedidoDoRelatorio;
use App\Comercial\DTO\RelatorioDeCampanhas;
use App\Comercial\Entity\CampanhaDeEmail;
use App\Comercial\Repository\RelatorioDeCampanhasRepository;

/**
 * A lista do "Relatório como caixa de entrada" da Central de envios (desenho 05 VS 21.10.26, L300-305 e L698-717;
 * spec `comercial-relatorio.md`): a contagem das abas (Enviadas, Agendadas, Recorrentes e, da F3e, Com estrela e
 * Arquivadas, pelas marcas do Master logado; spec `comercial-marcas.md`), a busca por campanha ou e-mail, a página de
 * 50 (no SQL) e as métricas de cada linha, numa consulta só para a página inteira.
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

    public function __construct(
        private readonly RelatorioDeCampanhasRepository $relatorio,
        private readonly RastreioDaLeitura $rastreio,
    ) {
    }

    public function executar(PedidoDoRelatorio $pedido, int $masterId): RelatorioDeCampanhas
    {
        $abas = $this->relatorio->contarPorAba($masterId);
        $total = $this->relatorio->contar($masterId, $pedido->aba, $pedido->busca);
        $pagina = min($pedido->pagina, max(1, (int) ceil($total / self::POR_PAGINA)));
        $linhas = $total === 0 ? [] : $this->relatorio->pagina($masterId, $pedido->aba, $pedido->busca, self::POR_PAGINA, ($pagina - 1) * self::POR_PAGINA);
        $metricas = $this->relatorio->metricas(array_map(static fn (array $l): int => $l['id'], $linhas));

        return new RelatorioDeCampanhas(
            $pedido->aba,
            $abas,
            array_map(fn (array $l): LinhaDoRelatorio => self::linha($l, $metricas[$l['id']] ?? null, $this->rastreio->efetivo($l['rastrear_leitura'])), $linhas),
            $total,
            $pagina,
            self::POR_PAGINA,
        );
    }

    /**
     * A métrica da campanha que ainda não começou: os destinatários confirmados, todos na fila. `rastreiaLeitura` é o
     * efetivo (F3g, {@see RastreioDaLeitura::efetivo()}).
     *
     * @param array{total: int, execucoes: int} $c
     * @param array{destinatarios: int, na_fila: int, enviados: int, entregues: int, lidos: int, rejeitados: int, clicaram: int}|null $m
     */
    public static function metricas(array $c, ?array $m, bool $rastreiaLeitura): MetricasDaCampanha
    {
        return MetricasDaCampanha::deArray($m, $c['execucoes'] === 0 ? $c['total'] : 0, $rastreiaLeitura);
    }

    /** O público como a lista e o visualizador mostram; o teste (F3f, dc L669/L709/L717) diz quantos e-mails. */
    public static function rotuloDoPublico(string $publico, int $total): string
    {
        return $publico === self::TESTE ? 'Teste · ' . $total . ' e-mail(s)' : (PublicosDaCampanha::ROTULOS[$publico] ?? $publico);
    }

    /**
     * @param LinhaDaCampanha $c
     * @param array{destinatarios: int, na_fila: int, enviados: int, entregues: int, lidos: int, rejeitados: int, clicaram: int}|null $m
     */
    private static function linha(array $c, ?array $m, bool $rastreiaLeitura): LinhaDoRelatorio
    {
        $sub = $c['modelo_nome'] . ($c['frequencia'] !== CampanhaDeEmail::UNICA ? ' · ' . $c['frequencia'] : '');
        $data = (new \DateTimeImmutable($c['agendada_para'] ?? $c['criada_em']))->format('Y-m-d\TH:i:s');

        return new LinhaDoRelatorio(
            $c['id'],
            $c['nome'],
            $sub,
            self::rotuloDoPublico($c['publico'], $c['total']),
            $c['total'],
            $c['estado'],
            self::ROTULOS[$c['estado']] ?? $c['estado'],
            $data,
            self::metricas($c, $m, $rastreiaLeitura),
            $c['estrela'],
        );
    }
}
