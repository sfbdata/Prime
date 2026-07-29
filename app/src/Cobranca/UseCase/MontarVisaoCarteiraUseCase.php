<?php

declare(strict_types=1);

namespace App\Cobranca\UseCase;

use App\Cobranca\DTO\CarteiraDetalheOutput;
use App\Cobranca\DTO\CasoResumoOutput;
use App\Cobranca\Entity\Carteira;
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

    /**
     * @param string $busca busca livre da página (objeto ou pessoa cobrada); vazia = sem filtro.
     *                      Filtra SÓ a lista — os agregados do cabeçalho (saldo consolidado, nº de
     *                      objetos e de casos) continuam sendo os da carteira INTEIRA: buscar não
     *                      muda o quanto a carteira tem a receber.
     *
     * @return array{carteira: CarteiraDetalheOutput, casos: list<CasoResumoOutput>}
     */
    public function executar(Carteira $carteira, string $busca = ''): array
    {
        $casos = $this->casoRepository->daCarteira($carteira);
        $busca = trim($busca);
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
        $casosOutput = [];
        foreach ($casos as $caso) {
            $saldo = $saldos[$caso->getId() ?? 0] ?? ['exigivel' => 0, 'vencido' => 0];
            // O consolidado soma TODOS os casos, inclusive os que a busca esconde da lista.
            $saldoConsolidado += $saldo['exigivel'];

            if ($idsCasando !== null && !isset($idsCasando[$caso->getId() ?? 0])) {
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

        return [
            'carteira' => CarteiraDetalheOutput::fromEntity(
                $carteira,
                $this->objetoRepository->contarDaCarteira($carteira),
                count($casos),
                $saldoConsolidado,
            ),
            'casos' => $casosOutput,
        ];
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
