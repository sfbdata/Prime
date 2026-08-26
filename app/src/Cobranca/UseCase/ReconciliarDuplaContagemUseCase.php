<?php

declare(strict_types=1);

namespace App\Cobranca\UseCase;

use App\Cobranca\DTO\ExpectativaDaLista;
use App\Cobranca\DTO\ResultadoReconciliacao;
use App\Cobranca\Entity\Carteira;
use App\Cobranca\Entity\Obrigacao;
use App\Cobranca\Enum\TipoEventoHistorico;
use App\Cobranca\Exception\RastroIncompletoException;
use App\Cobranca\Exception\UniversoDaListaMudouException;
use App\Cobranca\Repository\ObrigacaoRepository;
use App\Cobranca\Repository\RelatorioImportadoRepository;
use App\Cobranca\Service\Espelho\ConferenciaDeEncargos;
use App\Cobranca\Service\RegistrarEventoHistorico;
use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Tira do banco o encargo que a importação contou DUAS VEZES nas parcelas de acordo
 * (SPEC `docs/specs/cobranca-espelho-da-contabilidade.md` §17.8/§17.11; frente
 * `docs/HANDOFF_DUPLA_CONTAGEM.md`).
 *
 * ⚠️ **Escreve dinheiro em produção.** É a única peça desta frente que escreve.
 *
 * 🔑 **A lista vem da RÉGUA, não daqui.** `ConferenciaDeEncargos` acha as dívidas pela assinatura e diz
 * o que gravar em cada campo (INV-CE8/CE9); este UseCase só aplica. Reescrever a assinatura ou o valor
 * corrigido aqui seria uma segunda cópia da regra de dinheiro (D10) — e a assimetria do honorário
 * (`Σ` coluna L de TODAS as linhas, **não** `gravado − duplicado`) é exatamente o tipo de detalhe que
 * a segunda cópia perde em silêncio.
 *
 * 🔑 **Simula por padrão.** Prévia e aplicação percorrem ESTE mesmo método — `$usuario === null` é o
 * dry-run —, seguindo `ImportarAcordosDetalhadosUseCase`. Duas implementações da mesma decisão não
 * conseguem garantir que a projeção conferida seja o efeito aplicado, e aqui a projeção é o artefato
 * que o dono aprova antes da escrita.
 *
 * ⛔ **INV-R1 — não toca obrigação CONGELADA.** Congelada nunca é re-hidratada, então mexer no snapshot
 * dela é decisão de outra natureza (e o dono não a tomou). Mas pular **não é no-op**: é dinheiro que
 * fica inflado para sempre, e por isso sai no relatório com valor, não só com contagem.
 *
 * 🔑 **INV-R2 — preserva `encargosAtualizadosEm`.** A correção REMOVE uma soma indevida de um snapshot
 * tirado naquela data; ela não recalcula nada. Recarimbar com "hoje" mentiria sobre a procedência do
 * número e quebraria a própria régua, que compara o gravado contra a fórmula **na data do snapshot**.
 *
 * 🔑 **INV-R3 — o histórico registra QUAL LOTE.** Sem o lote, um erro de reconciliação não tem como ser
 * achado nem desfeito três meses depois (decisão do dono, §17.8). O `AuditLog` automático não serve
 * sozinho: `Obrigacao` é `Auditavel`, mas em CLI `actorUserId`, `ipAddress` e `route` ficam nulos e o
 * `tenantId` vem do `TenantContext`, que não está populado num comando.
 */
final class ReconciliarDuplaContagemUseCase
{
    public function __construct(
        private readonly ConferenciaDeEncargos $conferencia,
        private readonly RelatorioImportadoRepository $relatorios,
        private readonly ObrigacaoRepository $obrigacoes,
        private readonly RegistrarEventoHistorico $registrarEvento,
        private readonly EntityManagerInterface $em,
    ) {
    }

    /**
     * Dry-run — **é o produto principal desta entrega**: é o que o dono lê e aprova antes de existir
     * qualquer escrita. Somente leitura.
     *
     * @param list<Carteira> $carteiras
     */
    public function prever(array $carteiras, Tenant $tenant): ResultadoReconciliacao
    {
        return $this->processar($carteiras, $tenant, null);
    }

    /**
     * Aplica numa transação única (ou tudo, ou nada). Idempotente: rodar de novo não acha mais nada,
     * porque o encargo corrigido deixa de casar a assinatura.
     *
     * `$esperado` é **opcional**: quando informado, a lista encontrada tem de bater com a que foi
     * aprovada, senão nada é gravado (§17.11). Fica a critério de quem opera — o risco que ela cobre
     * (lote novo entrando entre a aprovação e a escrita) é pequeno enquanto a importação está
     * bloqueada, e exigi-la custaria uma etapa manual em toda execução.
     *
     * @param list<Carteira> $carteiras
     */
    public function confirmar(
        array $carteiras,
        Tenant $tenant,
        User $usuario,
        ?ExpectativaDaLista $esperado = null,
    ): ResultadoReconciliacao {
        return $this->em->wrapInTransaction(
            fn (): ResultadoReconciliacao => $this->processar($carteiras, $tenant, $usuario, $esperado),
        );
    }

    /**
     * Prévia e aplicação passam por aqui; `$usuario === null` é o dry-run.
     *
     * @param list<Carteira> $carteiras
     */
    private function processar(
        array $carteiras,
        Tenant $tenant,
        ?User $usuario,
        ?ExpectativaDaLista $esperado = null,
    ): ResultadoReconciliacao {
        $candidatas = 0;
        $corrigidas = [];
        $puladas = [];
        /** @var array<int, list<array<string, mixed>>> $porCaso */
        $porCaso = [];

        foreach ($carteiras as $carteira) {
            $lote = $this->relatorios->findUltimoDaCarteira($carteira);

            if ($lote === null) {
                continue;
            }

            foreach ($this->conferencia->conferir($lote)->duplicadas as $alvo) {
                ++$candidatas;

                $id = $alvo['obrigacaoId'];

                // Guarda de posse: o id vem da régua, que já filtra por carteira e tenant, mas o
                // recarregamento passa pelo find COM tenant assim mesmo — a régua e a escrita não podem
                // depender uma da confiança na outra quando o efeito é reescrever dinheiro.
                $obrigacao = $id === null ? null : $this->obrigacoes->findOneByIdDoTenant($id, $tenant);

                if ($obrigacao === null) {
                    $puladas[] = $this->pulada($alvo, 'obrigação não encontrada no escritório');

                    continue;
                }

                // INV-R1 — congelada não é re-hidratada; corrigi-la é outra decisão.
                if ($obrigacao->encargosCongelados()) {
                    $puladas[] = $this->pulada($alvo, 'encargos CONGELADOS — o valor inflado permanece');

                    continue;
                }

                $antes = $this->encargosDe($obrigacao);
                $depois = array_merge($antes, $alvo['corrigidoPorCampo']);

                if ($antes === $depois) {
                    // Não deveria acontecer (a régua só marca campo cujo gravado difere da coluna), mas
                    // gravar "nada" e contar como corrigida inflaria o relatório com trabalho que não
                    // houve.
                    $puladas[] = $this->pulada($alvo, 'o gravado já é o valor das colunas');

                    continue;
                }

                if ($usuario !== null) {
                    $this->aplicar($obrigacao, $depois);
                }

                $casoId = $obrigacao->getCaso()?->getId() ?? 0;
                $linha = $this->corrigida($alvo, $obrigacao, $antes, $depois, $casoId);

                $corrigidas[] = $linha;
                $porCaso[$casoId][] = $linha;
            }
        }

        $resultado = new ResultadoReconciliacao(
            aplicou: $usuario !== null,
            candidatas: $candidatas,
            corrigidas: $corrigidas,
            puladas: $puladas,
            casosComEvento: 0,
        );

        // 🔑 A TRAVA DA LISTA APROVADA (§17.11). Roda antes do histórico e do flush, e lança dentro da
        // transação: o `wrapInTransaction` derruba as alterações já feitas no laço e nada é gravado.
        if ($esperado !== null
            && !$esperado->confere($candidatas, $resultado->duplicadoTotalEmCentavos())) {
            throw new UniversoDaListaMudouException(
                $esperado->dividas,
                $candidatas,
                $esperado->totalDuplicadoEmCentavos,
                $resultado->duplicadoTotalEmCentavos(),
            );
        }

        $casosComEvento = 0;

        if ($usuario !== null) {
            $casosComEvento = $this->registrarHistorico($porCaso, $usuario);

            // INV-R3 conferido AQUI — dentro da transação e antes do flush. A versão anterior conferia
            // isto no relatório do comando, depois de `confirmar()` retornar: ali o commit já tinha
            // acontecido, e a mensagem afirmava "transação revertida" sobre dinheiro gravado.
            if (count($porCaso) !== $casosComEvento) {
                throw new RastroIncompletoException(count($porCaso), $casosComEvento);
            }

            // Flush único: as obrigações managed e os eventos numa transação só.
            $this->em->flush();
        }

        return new ResultadoReconciliacao(
            aplicou: $usuario !== null,
            candidatas: $candidatas,
            corrigidas: $corrigidas,
            puladas: $puladas,
            casosComEvento: $casosComEvento,
        );
    }

    /**
     * Grava os campos corrigidos **preservando a data do snapshot** (INV-R2) e os campos não marcados.
     *
     * @param array{juros:int, multa:int, correcao:int, honorarios:int} $depois
     */
    private function aplicar(Obrigacao $obrigacao, array $depois): void
    {
        $snapshot = $obrigacao->getEncargosAtualizadosEm();

        if ($snapshot === null) {
            // Inalcançável pela régua (sem carimbo ela é injulgável e nunca entra na lista), mas
            // `definirEncargos()` exige a data e o fallback silencioso seria carimbar "hoje" — que é
            // exatamente o que o INV-R2 proíbe.
            throw new \LogicException(sprintf(
                'Obrigação %d sem `encargosAtualizadosEm`: não dá para corrigir sem inventar a data do '
                . 'snapshot, e inventar a data é o defeito que o INV-R2 fecha.',
                $obrigacao->getId() ?? 0,
            ));
        }

        $obrigacao->definirEncargos(
            $depois['juros'],
            $depois['multa'],
            $depois['correcao'],
            $depois['honorarios'],
            $snapshot,
        );
    }

    /**
     * UM evento por CASO (não um por obrigação — seria ruído), no molde de
     * `EditarConfiguracaoCasoUseCase`. Sem flush aqui: o `processar()` fecha a transação.
     *
     * @param array<int, list<array<string, mixed>>> $porCaso
     */
    private function registrarHistorico(array $porCaso, User $usuario): int
    {
        $registrados = 0;

        foreach ($porCaso as $linhas) {
            $primeira = $linhas[0];
            /** @var Obrigacao|null $qualquer */
            $qualquer = $this->obrigacoes->find($primeira['obrigacaoId']);
            $caso = $qualquer?->getCaso();

            if ($caso === null) {
                continue;
            }

            // 🔴 UM número só, e o texto NÃO explica mais por que ele estaria partido.
            //
            // Aqui saíam dois valores e a frase "(o honorário não entra no saldo)" — verdade sob a
            // INV-E2, que a spec `cobranca-honorario-no-total.md` REVOGOU. Depois dela
            // `removidoForaDoSaldo` é sempre 0, então o evento gravado no histórico do caso diria
            // "honorário reduzido em R$ 0,00" num caso em que o honorário caiu de verdade — número
            // errado E motivo revogado, permanentes, no lugar em que ninguém mais vai conferi-los.
            $reduzido = array_sum(array_column($linhas, 'removidoNoSaldo'))
                + array_sum(array_column($linhas, 'removidoForaDoSaldo'));

            $this->registrarEvento->registrar(
                $caso,
                TipoEventoHistorico::ValorAtualizadoReconhecido,
                $usuario,
                sprintf(
                    'Dupla contagem de encargo corrigida em %d obrigação(ões): saldo devido reduzido '
                    . 'em R$ %s (juros, multa, correção e honorário — todos entram no saldo).',
                    count($linhas),
                    number_format($reduzido / 100, 2, ',', '.'),
                ),
                [
                    'origem' => 'reconciliacao_dupla_contagem',
                    // INV-R3 — o lote é o que torna a correção auditável e reversível.
                    'loteId' => $primeira['loteId'],
                    'loteEmitidoEm' => $primeira['loteEmitidoEm']?->format('Y-m-d'),
                    'removidoDoSaldoCentavos' => $reduzido,
                    // 🔑 A MARCA DE REGRA, e ela existe por um motivo medido: a chave
                    // `removidoDoSaldoCentavos` mudou de SIGNIFICADO com a spec
                    // `cobranca-honorario-no-total.md` — antes dela excluía o honorário, depois
                    // inclui. Os eventos gravados em produção em agosto/2026 usam a regra antiga e não
                    // trazem esta marca. Sem ela, o mesmo nome de campo teria dois sentidos no mesmo
                    // histórico, sem nada que distinguisse um do outro. `removidoForaDoSaldoCentavos`
                    // saiu: era sempre o honorário, que agora está dentro.
                    'regraDoSaldo' => 'honorario_incluido',
                    'obrigacoes' => array_map(
                        static fn (array $l): array => [
                            'id' => $l['obrigacaoId'],
                            'referencia' => $l['referencia'],
                            'antes' => $l['antes'],
                            'depois' => $l['depois'],
                        ],
                        $linhas,
                    ),
                ],
            );

            ++$registrados;
        }

        return $registrados;
    }

    /**
     * @param array<string, mixed>                                      $alvo
     * @param array{juros:int, multa:int, correcao:int, honorarios:int} $antes
     * @param array{juros:int, multa:int, correcao:int, honorarios:int} $depois
     *
     * @return array<string, mixed>
     */
    private function corrigida(array $alvo, Obrigacao $obrigacao, array $antes, array $depois, int $casoId): array
    {
        // O removido sai da diferença REAL entre antes e depois, não do "duplicado" da régua — e os dois
        // divergem no honorário de propósito (INV-CE9: a coluna L da linha 1.15 volta para o campo).
        // Reportar o número da régua como se fosse o efeito seria dizer que saiu do banco o que não saiu.
        // 🔴 O honorário ENTRA no saldo desde a spec `cobranca-honorario-no-total.md` (INV-E2 revogada).
        // Antes dela este cálculo somava só juros/multa/correção e jogava o honorário em
        // `removidoForaDoSaldo`; manter assim faria o relatório afirmar que saiu do saldo menos do que
        // saiu de fato, na mesma tela em que o dono decide se aprova a escrita.
        $noSaldo = ($antes['juros'] - $depois['juros'])
            + ($antes['multa'] - $depois['multa'])
            + ($antes['correcao'] - $depois['correcao'])
            + ($antes['honorarios'] - $depois['honorarios']);

        return [
            'obrigacaoId' => $obrigacao->getId() ?? 0,
            'unidade' => $alvo['unidade'],
            'referencia' => $alvo['referencia'],
            'casoId' => $casoId,
            'loteId' => $alvo['loteId'],
            'loteEmitidoEm' => $alvo['loteEmitidoEm'],
            'antes' => $antes,
            'depois' => $depois,
            'removidoNoSaldo' => $noSaldo,
            // Sempre 0 desde `cobranca-honorario-no-total.md`: era aqui que o honorário caía, e ele
            // agora está DENTRO do saldo, contado em `removidoNoSaldo`. O campo fica para a soma das
            // duas continuar fechando com o total, e para não mudar a forma de um relatório que já
            // roda em produção — mas não há mais nada que saia "fora do saldo".
            'removidoForaDoSaldo' => 0,
            // O que a RÉGUA chamou de duplicado — é contra isto que a lista foi aprovada, e ele
            // difere do `removido*` no honorário de propósito (INV-CE9).
            'duplicadoNoSaldo' => $alvo['duplicadoNoSaldo'],
            'duplicadoForaDoSaldo' => $alvo['duplicadoForaDoSaldo'],
        ];
    }

    /**
     * @param array<string, mixed> $alvo
     *
     * @return array<string, mixed>
     */
    private function pulada(array $alvo, string $motivo): array
    {
        return [
            'obrigacaoId' => $alvo['obrigacaoId'] ?? 0,
            'unidade' => $alvo['unidade'],
            'referencia' => $alvo['referencia'],
            'motivo' => $motivo,
            'duplicadoNoSaldo' => $alvo['duplicadoNoSaldo'],
            'duplicadoForaDoSaldo' => $alvo['duplicadoForaDoSaldo'],
        ];
    }

    /** @return array{juros:int, multa:int, correcao:int, honorarios:int} */
    private function encargosDe(Obrigacao $obrigacao): array
    {
        return [
            'juros' => $obrigacao->getJuros(),
            'multa' => $obrigacao->getMulta(),
            'correcao' => $obrigacao->getCorrecao(),
            'honorarios' => $obrigacao->getHonorarios(),
        ];
    }
}
