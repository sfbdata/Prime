<?php

declare(strict_types=1);

namespace App\Cobranca\UseCase;

use App\Cobranca\DTO\CarteiraDetalheOutput;
use App\Cobranca\DTO\CasoResumoOutput;
use App\Cobranca\Entity\Carteira;
use App\Cobranca\Enum\StatusCaso;
use App\Cobranca\Repository\CasoCobrancaRepository;
use App\Cobranca\Repository\ObjetoCobrancaRepository;
use App\Cobranca\Service\CalculadoraSaldo;
use App\Entity\Tenant\Tenant;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;

/**
 * Leitura: monta a visão da Carteira (Etapa 8) — cabeçalho de configuração + agregados (nº de
 * objetos e casos, saldo consolidado derivado) + a lista de casos da carteira com saldo por caso.
 * A carteira já vem resolvida por tenant no controller; aqui só agrega. Saldo é derivado
 * (invariável 20). Devolve Output DTOs.
 */
final class MontarVisaoCarteiraUseCase
{
    public function __construct(
        private readonly ObjetoCobrancaRepository $objetoRepository,
        private readonly CasoCobrancaRepository $casoRepository,
        private readonly CalculadoraSaldo $calculadoraSaldo,
        private readonly Connection $connection,
    ) {
    }

    /** Ordenações aceitas na lista. Chave = valor que chega pela URL; o resto é recusado. */
    public const ORDENACOES = ['saldo', 'objeto', 'pessoa'];

    /**
     * Filtros de Estado aceitos na lista. Os três primeiros são o `StatusCaso`; `vencidos` NÃO é
     * estado — é o recorte derivado "só o que tem atraso", que atravessa os três (um caso
     * judicializado também vence). Qualquer outro valor é ignorado, e a lista volta inteira.
     */
    public const ESTADOS = ['ativo', 'judicializado', 'encerrado', 'vencidos'];

    /**
     * @param string $busca   busca livre da página (objeto ou pessoa cobrada); vazia = sem filtro.
     *                        Filtra SÓ a lista — os agregados do cabeçalho (saldo consolidado,
     *                        vencido, nº de objetos e de casos) continuam sendo os da carteira
     *                        INTEIRA: buscar não muda o quanto a carteira tem a receber.
     * @param int    $pagina  1-based; valor fora da faixa é grampeado à última página existente
     * @param int    $porPagina >= 1
     * @param string $ordenar uma de self::ORDENACOES; qualquer outra coisa cai no padrão
     * @param string $direcao 'asc' ou 'desc'
     * @param string $estado  uma de self::ESTADOS; vazia (ou desconhecida) = sem filtro. Filtra SÓ
     *                        a lista, pela MESMA razão da busca: o cabeçalho responde "quanto esta
     *                        carteira tem a receber", e essa resposta não pode mudar porque alguém
     *                        escolheu ver um recorte
     *
     * @return array{carteira: CarteiraDetalheOutput, casos: list<CasoResumoOutput>, total: int, pagina: int, total_paginas: int, por_pagina: int}
     */
    public function executar(
        Carteira $carteira,
        string $busca = '',
        int $pagina = 1,
        int $porPagina = 25,
        string $ordenar = 'saldo',
        string $direcao = 'desc',
        string $estado = '',
    ): array {
        $casos = $this->casoRepository->daCarteira($carteira);
        $busca = trim($busca);
        $estado = in_array($estado, self::ESTADOS, true) ? $estado : '';
        $idsCasando = $busca !== ''
            ? $this->casoRepository->idsDaCarteiraCasandoBusca($carteira, $busca)
            : null;

        // Saldos derivados em LOTE (uma carga tenant-scoped) — fim do N+1 de saldoExigivel+saldoVencido
        // por caso. Mesma regra dos métodos por-caso (via `CalculadoraSaldo::saldosDosCasos`).
        $saldos = $this->calculadoraSaldo->saldosDosCasos($casos, $carteira->getTenant());

        // Grampo (Ajuste #6): quais objetos da carteira têm documento — UMA agregação em lote
        // (nada de N+1 por objeto).
        $objetoIds = [];
        foreach ($casos as $caso) {
            $objetoId = $caso->getObjeto()?->getId();
            if ($objetoId !== null) {
                $objetoIds[$objetoId] = true;
            }
        }
        $objetosComDocumento = $this->objetosComDocumento(array_keys($objetoIds), $carteira->getTenant());

        $saldoConsolidado = 0;
        $saldoVencido = 0;
        $totalComAtraso = 0;
        $casosOutput = [];
        foreach ($casos as $caso) {
            $saldo = $saldos[$caso->getId() ?? 0] ?? ['exigivel' => 0, 'vencido' => 0];
            // Os agregados somam TODOS os casos, inclusive os que a busca esconde e os que caem
            // fora da página. O vencido sai do mesmo lote já calculado — não custa consulta.
            $saldoConsolidado += $saldo['exigivel'];
            $saldoVencido += $saldo['vencido'];
            if ($saldo['vencido'] > 0) {
                ++$totalComAtraso;
            }

            if ($idsCasando !== null && !isset($idsCasando[$caso->getId() ?? 0])) {
                continue;
            }

            // Depois da soma, de propósito: o `continue` esconde a linha da lista sem tirar o valor
            // do cabeçalho. `vencidos` lê o saldo já calculado (não custa consulta); os demais
            // comparam o estado do caso.
            if ($estado !== '' && !self::casaComEstado($caso->getStatus(), $saldo['vencido'], $estado)) {
                continue;
            }

            $objetoId = $caso->getObjeto()?->getId() ?? 0;
            $casosOutput[] = CasoResumoOutput::fromEntity(
                $caso,
                $saldo['exigivel'],
                $saldo['vencido'],
                isset($objetosComDocumento[$objetoId]),
            );
        }

        // Ordenar ANTES de fatiar: paginar uma lista fora de ordem devolveria páginas que não
        // conversam entre si (o mesmo caso podendo aparecer em duas, e outro em nenhuma).
        $this->ordenar($casosOutput, $ordenar, $direcao);

        // `total` é o tamanho da lista JÁ FILTRADA pela busca — é o que a paginação navega e o que
        // o rodapé conta. Não confundir com `totalCasos` do cabeçalho, que é a carteira inteira.
        $total = count($casosOutput);
        $porPagina = max(1, $porPagina);
        $totalPaginas = max(1, (int) ceil($total / $porPagina));
        // Grampeia em vez de devolver página vazia: quem estava na página 5 e busca algo que só tem
        // 1 página veria uma lista vazia com "0 resultados" ao lado de um contador dizendo que há
        // resultados. Cair na última página existente é o que o usuário quis dizer.
        $pagina = min(max(1, $pagina), $totalPaginas);

        return [
            'carteira' => CarteiraDetalheOutput::fromEntity(
                $carteira,
                $this->objetoRepository->contarDaCarteira($carteira),
                count($casos),
                $saldoConsolidado,
                $saldoVencido,
                $totalComAtraso,
            ),
            'casos' => array_slice($casosOutput, ($pagina - 1) * $porPagina, $porPagina),
            'total' => $total,
            'pagina' => $pagina,
            'total_paginas' => $totalPaginas,
            // Devolvido porque a tela precisa dele para dizer "Mostrando 101–121 de 121": calcular o
            // primeiro item a partir do tamanho da lista devolvida erra justamente na última página,
            // que é a única que vem incompleta.
            'por_pagina' => $porPagina,
        ];
    }

    /** `vencidos` é recorte derivado (tem atraso); o resto compara o estado do caso. */
    private static function casaComEstado(StatusCaso $status, int $vencido, string $estado): bool
    {
        return $estado === 'vencidos' ? $vencido > 0 : $status->value === $estado;
    }

    /**
     * Ordena a lista no lugar. Em PHP mesmo, porque a lista já está carregada e já é filtrada em
     * PHP — trazer ordenação para o SQL exigiria mover também a busca e o cálculo de saldo (que é
     * derivado, não coluna), o que é outra frente.
     *
     * O desempate por `id` é obrigatório, não estético: sem ele, dois casos de mesmo saldo podem
     * trocar de lugar entre duas requisições e um deles aparece em duas páginas enquanto o outro
     * some — o defeito clássico de paginar por chave não-única.
     *
     * @param list<CasoResumoOutput> $casos
     */
    private function ordenar(array &$casos, string $ordenar, string $direcao): void
    {
        $campo = in_array($ordenar, self::ORDENACOES, true) ? $ordenar : 'saldo';
        $sinal = strtolower($direcao) === 'asc' ? 1 : -1;

        usort($casos, static function (CasoResumoOutput $a, CasoResumoOutput $b) use ($campo, $sinal): int {
            $c = match ($campo) {
                'objeto' => strnatcasecmp($a->objetoIdentificacao, $b->objetoIdentificacao),
                'pessoa' => strnatcasecmp($a->pessoaCobradaNome, $b->pessoaCobradaNome),
                default => $a->saldoExigivel <=> $b->saldoExigivel,
            };

            return $c !== 0 ? $sinal * $c : $a->id <=> $b->id;
        });
    }

    /**
     * Grampo (Ajuste #6): IDs de objeto (dentre os informados) que têm ao menos um documento —
     * num CASO (`cobranca_documento`) ou num ACORDO de um caso do objeto (`cobranca_acordo_documento`
     * → `cobranca_acordo` → `cobranca_caso.objeto_id`). UMA única agregação nativa (DBAL) para toda a
     * carteira — sem N+1 por objeto. Escopo por tenant SEMPRE (defesa em profundidade).
     *
     * @param list<int> $objetoIds
     *
     * @return array<int, true> objetoId => true (presença; sem contagem, SPEC §9 YAGNI)
     */
    private function objetosComDocumento(array $objetoIds, Tenant $tenant): array
    {
        if ($objetoIds === []) {
            return [];
        }

        $sql = <<<'SQL'
            SELECT DISTINCT c.objeto_id
            FROM cobranca_caso c
            WHERE c.tenant_id = :tenant
              AND c.objeto_id IN (:objetos)
              AND (
                EXISTS (
                  SELECT 1 FROM cobranca_documento d
                  WHERE d.caso_id = c.id AND d.tenant_id = :tenant
                )
                OR EXISTS (
                  SELECT 1
                  FROM cobranca_acordo a
                  JOIN cobranca_acordo_documento ad ON ad.acordo_id = a.id AND ad.tenant_id = :tenant
                  WHERE a.caso_id = c.id AND a.tenant_id = :tenant
                )
              )
            SQL;

        $ids = $this->connection->fetchFirstColumn(
            $sql,
            ['tenant' => $tenant->getId(), 'objetos' => $objetoIds],
            ['objetos' => ArrayParameterType::INTEGER],
        );

        $mapa = [];
        foreach ($ids as $id) {
            $mapa[(int) $id] = true;
        }

        return $mapa;
    }
}
