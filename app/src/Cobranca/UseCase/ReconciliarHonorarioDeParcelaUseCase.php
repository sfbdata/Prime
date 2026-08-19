<?php

declare(strict_types=1);

namespace App\Cobranca\UseCase;

use App\Cobranca\DTO\ExpectativaDaLista;
use App\Cobranca\DTO\ResultadoReconciliacaoHonorario;
use App\Cobranca\Entity\Carteira;
use App\Cobranca\Entity\CasoCobranca;
use App\Cobranca\Entity\Obrigacao;
use App\Cobranca\Enum\TipoEventoHistorico;
use App\Cobranca\Exception\UniversoDaListaMudouException;
use App\Cobranca\Repository\ObrigacaoRepository;
use App\Cobranca\Service\RegistrarEventoHistorico;
use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Põe o override `taxaHonorariosBp = 0` nas PARCELAS DE ACORDO que ficaram sem ele, e tira o
 * honorário que a cascata cobrou por cima — spec `docs/specs/cobranca-honorario-no-total.md` §10.
 *
 * ⚠️ **Escreve dinheiro em produção.** Simula por padrão; só grava com `--aplicar` e autor.
 *
 * 🔑 **O defeito, em uma frase.** O relatório de acordos da contabilidade não tem coluna de encargo
 * nenhuma — de 8.671 linhas de parcela, ZERO com juros, multa, honorário ou total. Ela publica só o
 * Valor acordado. O sistema já cumpre isso em 301 parcelas (honorário R$ 0,00); em 135 o override
 * ficou faltando e elas cobram R$ 2.764,16 que ela não cobra. É o sistema formando opinião (§1.1), e
 * o conserto é copiar ela.
 *
 * ⛔ **NÃO é a conta original reconstruída.** A primeira versão desta fatia mirou na procedência
 * ("nasceu de `reconstruirContaOriginal`") em vez do papel ("é parcela"), e teria zerado o honorário
 * de 3.347 dívidas VELHAS engolidas por acordo — R$ 102.126,32 que a carteira cobra legitimamente e
 * que a produção já trata assim em 3.473 delas. Ver
 * {@see ObrigacaoRepository::parcelasDeAcordoSemOverrideDeHonorario}.
 *
 * ⛔ **INV-H1 — não toca obrigação CONGELADA.** Congelada nunca é re-hidratada, então mexer no snapshot
 * dela é decisão de outra natureza. Pular **não é no-op**: o honorário indevido fica, e por isso sai no
 * relatório com valor, não só com contagem.
 *
 * 🔑 **INV-H2 — preserva `encargosAtualizadosEm`.** A correção REMOVE uma soma indevida de um snapshot
 * tirado na data do acordo; não recalcula nada. Recarimbar com "hoje" mentiria sobre a procedência do
 * número e quebraria a régua, que confere o gravado contra a fórmula NA DATA do snapshot.
 *
 * 🔑 **INV-H3 — juros, multa e correção não são tocados.** Que a parcela atrasada volte a render juros
 * é decisão consciente, registrada em `parcelaInput`. Ampliar aqui seria decidir por conta própria.
 */
final class ReconciliarHonorarioDeParcelaUseCase
{
    public function __construct(
        private readonly ObrigacaoRepository $obrigacoes,
        private readonly RegistrarEventoHistorico $registrarEvento,
        private readonly EntityManagerInterface $em,
    ) {
    }

    /**
     * Dry-run — é o artefato que o dono aprova antes de existir qualquer escrita. Somente leitura.
     *
     * @param list<Carteira> $carteiras
     */
    public function prever(array $carteiras, Tenant $tenant): ResultadoReconciliacaoHonorario
    {
        return $this->processar($carteiras, $tenant, null);
    }

    /**
     * Aplica numa transação única (ou tudo, ou nada). Idempotente: rodar de novo não acha mais nada,
     * porque `taxaHonorariosBp` deixa de ser nulo e a consulta não casa mais a obrigação.
     *
     * `$esperado` é OPCIONAL e trava a lista aprovada: se o universo mudou entre a aprovação do dono e
     * a escrita, nada é gravado.
     *
     * @param list<Carteira> $carteiras
     */
    public function confirmar(
        array $carteiras,
        Tenant $tenant,
        User $usuario,
        ?ExpectativaDaLista $esperado = null,
    ): ResultadoReconciliacaoHonorario {
        return $this->em->wrapInTransaction(
            fn (): ResultadoReconciliacaoHonorario => $this->processar($carteiras, $tenant, $usuario, $esperado),
        );
    }

    /**
     * Prévia e aplicação passam por AQUI; `$usuario === null` é o dry-run. Uma implementação só, porque
     * duas não conseguem garantir que a projeção conferida seja o efeito aplicado — e aqui a projeção é
     * o artefato que o dono aprova antes da escrita.
     *
     * @param list<Carteira> $carteiras
     */
    private function processar(
        array $carteiras,
        Tenant $tenant,
        ?User $usuario,
        ?ExpectativaDaLista $esperado = null,
    ): ResultadoReconciliacaoHonorario {
        $candidatas = 0;
        $corrigidas = [];
        $puladas = [];
        /** @var array<int, list<array{obrigacaoId: int, referencia: ?string, honorarioRemovido: int}>> $porCaso */
        $porCaso = [];

        foreach ($carteiras as $carteira) {
            $alvos = $this->obrigacoes->parcelasDeAcordoSemOverrideDeHonorario($carteira, $tenant);
            // ⚠️ Contado AQUI, do tamanho do universo, e NÃO dentro do laço. Incrementar por item faria
            // `contasFecham()` ser verdade por construção — um `continue` novo cairia fora dos dois
            // baldes E fora da contagem, e a conferência não veria nada. Foi a crítica que a régua
            // irmã já levou; com a contagem independente ela vira guarda de verdade.
            $candidatas += count($alvos);

            foreach ($alvos as $obrigacao) {
                $unidade = (string) $obrigacao->getCaso()?->getObjeto()?->getIdentificacao();

                if ($obrigacao->encargosCongelados()) {
                    $puladas[] = [
                        'obrigacaoId' => (int) $obrigacao->getId(),
                        'unidade' => $unidade,
                        'referencia' => $obrigacao->getReferenciaExterna(),
                        'motivo' => 'encargos congelados — o snapshot é a verdade e mexer nele é outra decisão (INV-H1)',
                        'honorarioQueFicou' => $obrigacao->getHonorarios(),
                    ];

                    continue;
                }

                $substituto = $obrigacao->getAcordoSubstituto();
                $removido = $this->honorarioQueSai($obrigacao);

                $corrigidas[] = [
                    'obrigacaoId' => (int) $obrigacao->getId(),
                    'casoId' => (int) $obrigacao->getCaso()?->getId(),
                    'unidade' => $unidade,
                    'referencia' => $obrigacao->getReferenciaExterna(),
                    'competencia' => $obrigacao->getCompetencia(),
                    'valorOriginal' => $obrigacao->getValorOriginal(),
                    'honorarioRemovido' => $removido,
                    'acordoOrigem' => $obrigacao->getAcordoOrigem()?->getNumeroExterno(),
                    'acordoSubstituto' => $substituto?->getNumeroExterno(),
                    // As DUAS cláusulas de `aplicarExigibilidade`, não só uma: está fora do exigível
                    // quem tem substituto VIGENTE **ou** acordo de origem NÃO vigente. Contar só a
                    // primeira faria o comando apresentar como exato um número que não é (achado da
                    // 2ª revisão).
                    'foraDoExigivel' => ($substituto?->getStatus()->ehVigente() ?? false)
                        || !($obrigacao->getAcordoOrigem()?->getStatus()->ehVigente() ?? false),
                ];

                if ($usuario === null) {
                    continue;
                }

                $this->aplicarNa($obrigacao);

                $porCaso[(int) $obrigacao->getCaso()?->getId()][] = [
                    'obrigacaoId' => (int) $obrigacao->getId(),
                    'referencia' => $obrigacao->getReferenciaExterna(),
                    'honorarioRemovido' => $removido,
                ];
            }
        }

        // 🔴 A trava da lista aprovada dispara ANTES de qualquer evento e DENTRO da transação: o
        // `wrapInTransaction` desfaz as mutações já feitas no laço acima. Conferir depois de gravar o
        // histórico deixaria evento órfão de correção.
        $totalEncontrado = $this->totalDe($corrigidas, $puladas);

        if ($esperado !== null && !$esperado->confere($candidatas, $totalEncontrado)) {
            throw new UniversoDaListaMudouException(
                $esperado->dividas,
                $candidatas,
                $esperado->totalDuplicadoEmCentavos,
                $totalEncontrado,
            );
        }

        $casosComEvento = 0;

        if ($usuario !== null) {
            foreach ($porCaso as $casoId => $linhas) {
                // Só conta o que virou evento DE FATO: `registrarNoHistorico` desiste quando o caso não
                // é encontrado, e um contador cego imprimiria "eventos no histórico: N" sem evento.
                $casosComEvento += $this->registrarNoHistorico($casoId, $linhas, $usuario) ? 1 : 0;
            }

            $this->em->flush();
        }

        return new ResultadoReconciliacaoHonorario(
            aplicou: $usuario !== null,
            candidatas: $candidatas,
            corrigidas: $corrigidas,
            puladas: $puladas,
            casosComEvento: $casosComEvento,
        );
    }

    /**
     * As DUAS metades da correção, e elas andam juntas.
     *
     * O override sozinho não bastaria: o campo `honorarios` já materializado continuaria dentro de
     * `valorExigivel()` até alguma hidratação passar por ali — e a obrigação substituída **não é
     * re-hidratada** (a query do exigível a exclui), então o resto ficaria para sempre.
     *
     * Zerar o campo sozinho também não bastaria: sem o override, a primeira hidratação ao vivo (a tela
     * do acordo hidrata as parcelas de acordo vigente) recolocaria o honorário pela taxa da carteira.
     */
    private function aplicarNa(Obrigacao $obrigacao): void
    {
        $obrigacao->setTaxaHonorariosBp(0);

        // INV-H2: `encargosAtualizadosEm` é PRESERVADO. Quando nunca houve materialização (acordo sem
        // data), não há o que zerar — e inventar uma data aqui é justamente o defeito que a frente
        // vizinha está consertando.
        $referencia = $obrigacao->getEncargosAtualizadosEm();

        if ($referencia !== null) {
            $obrigacao->definirEncargos(
                $obrigacao->getJuros(),
                $obrigacao->getMulta(),
                $obrigacao->getCorrecao(),
                0,
                $referencia,
            );
        }

        $this->obrigacoes->salvar($obrigacao);
    }

    /**
     * O histórico do caso registra o QUE e o PORQUÊ, com os NNs — sem isso, uma correção de dinheiro
     * não tem como ser achada nem desfeita três meses depois. O `AuditLog` automático não serve
     * sozinho: em CLI ele sai degradado (sem ator, sem IP, sem rota).
     *
     * @param list<array{obrigacaoId: int, referencia: ?string, honorarioRemovido: int}> $linhas
     */
    private function registrarNoHistorico(int $casoId, array $linhas, User $usuario): bool
    {
        $caso = $this->em->getRepository(CasoCobranca::class)->find($casoId);

        if ($caso === null) {
            return false;
        }

        $total = array_sum(array_column($linhas, 'honorarioRemovido'));

        $this->registrarEvento->registrar(
            $caso,
            TipoEventoHistorico::ValorAtualizadoReconhecido,
            $usuario,
            sprintf(
                'Honorário retirado de %d parcela(s) de acordo: R$ %s. O relatório de acordos da '
                . 'contabilidade não traz encargo nenhum na parcela — ela publica só o Valor acordado, '
                . 'e o sistema passa a copiar isso, como já fazia nas demais parcelas.',
                count($linhas),
                number_format($total / 100, 2, ',', '.'),
            ),
            [
                'origem' => 'reconciliacao_honorario_parcela_de_acordo',
                'obrigacoes' => array_column($linhas, 'obrigacaoId'),
                'referencias' => array_values(array_filter(array_column($linhas, 'referencia'))),
                'honorarioRemovidoCentavos' => $total,
            ],
        );

        return true;
    }

    /**
     * O honorário que a correção REALMENTE tira desta obrigação — que é zero quando ela nunca foi
     * materializada (`encargosAtualizadosEm` nulo, acordo sem data): ali `aplicarNa` grava o override e
     * não mexe no campo.
     *
     * Existe para o relatório não afirmar o que não aconteceu. Hoje os dois números coincidem sempre,
     * porque `definirEncargos` é o único caminho que escreve `honorarios` e sempre carimba a data junto
     * — mas depender desse acoplamento em silêncio é como o histórico desta frente costuma quebrar.
     */
    private function honorarioQueSai(Obrigacao $obrigacao): int
    {
        return $obrigacao->getEncargosAtualizadosEm() === null ? 0 : $obrigacao->getHonorarios();
    }

    /**
     * @param list<array<string, mixed>> $corrigidas
     * @param list<array<string, mixed>> $puladas
     */
    private function totalDe(array $corrigidas, array $puladas): int
    {
        return (int) array_sum(array_column($corrigidas, 'honorarioRemovido'))
            + (int) array_sum(array_column($puladas, 'honorarioQueFicou'));
    }
}
