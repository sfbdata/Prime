<?php

declare(strict_types=1);

namespace App\Cobranca\UseCase;

use App\Cobranca\DTO\ExpectativaDaLista;
use App\Cobranca\DTO\ResultadoReconciliacaoHonorario;
use App\Cobranca\Entity\Carteira;
use App\Cobranca\Entity\CasoCobranca;
use App\Cobranca\Entity\Obrigacao;
use App\Cobranca\Enum\TipoEventoHistorico;
use App\Cobranca\Exception\AprovacaoNaoConfereException;
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
 * 🔑 **O defeito, em uma frase.** Estas 135 obrigações cobram R$ 2.764,16 de honorário que a
 * contabilidade não cobra em relatório nenhum — conferido contra a inadimplência de 17/08: 0 de 135
 * aparecem lá; no relatório de acordos, o único em que aparecem, ela publica só o Valor acordado. É o
 * sistema formando opinião (§1.1), e o conserto é copiar ela.
 *
 * 🔴 **NÃO generalize isto para "parcela de acordo não cobra encargo".** A frase estava aqui e é
 * FALSA: vale para o relatório de ACORDOS, que não tem coluna de encargo nenhuma (0 de 8.671 linhas),
 * mas a parcela ATRASADA migra para o relatório de INADIMPLÊNCIA, que tem as colunas, e lá ela cobra —
 * 114 parcelas, R$ 6.601,57, medidos no lote de 17/08 (spec §10.8). Olhar um relatório e concluir
 * sobre os dois foi o erro que reduziu esta fatia pela metade. O que separa as 135 dessas 114 é
 * medido, não deduzido: as 135 têm acordo substituto vigente e nenhuma recebeu dinheiro; as 114 não
 * têm substituto nenhum.
 *
 * ⛔ **NÃO é a conta original reconstruída.** A primeira versão desta fatia mirou na procedência
 * ("nasceu de `reconstruirContaOriginal`") em vez do papel ("é parcela"), e teria zerado o honorário
 * de 3.347 dívidas VELHAS engolidas por acordo — R$ 102.126,32 que a carteira cobra legitimamente e
 * que a produção já trata assim em 3.473 delas. Ver
 * {@see ObrigacaoRepository::parcelasDeAcordoSemOverrideDeHonorario}.
 *
 * ⛔ **INV-H0 — não escreve sem LISTA APROVADA.** O dado que decide se uma candidata deve ser
 * corrigida — o Valor acordado que a contabilidade declara — **não está no banco** no momento da
 * correção. Três revisões derrubaram três réguas automáticas para contornar isso (procedência, papel,
 * marca na descrição) — todas erravam em algum recorte. Então a varredura apenas PROPÕE: quem escolhe
 * é o humano, passando os ids que aprovou na simulação. Candidata fora da lista é PULADA com motivo;
 * id aprovado que sumiu do universo ABORTA tudo. Ver spec §10.5.2.
 *
 * ⛔ **INV-H4 — a linha pronta para colar só traz as de FORA do exigível.** O override é PERMANENTE;
 * numa dívida que está DENTRO do saldo ele desliga para sempre um honorário que a contabilidade pode
 * voltar a cobrar quando a parcela atrasar (§10.8). As de dentro saem em tabela separada, com aviso, e
 * entram só se o humano incluir o id à mão. Medido em produção em 20/08: 0 de 135 estão no exigível —
 * a separação existe para o dia em que não for assim.
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
     * `$idsAprovados` é **obrigatório**: são as obrigações que o humano aprovou olhando a simulação.
     * Nada fora dela é escrito, e id aprovado que já não é candidato aborta tudo (INV-H0).
     *
     * `$esperado` é OPCIONAL e trava o TAMANHO do universo: se ele mudou entre a aprovação e a
     * escrita, nada é gravado.
     *
     * @param list<Carteira> $carteiras
     * @param list<int>      $idsAprovados
     */
    public function confirmar(
        array $carteiras,
        Tenant $tenant,
        User $usuario,
        array $idsAprovados,
        ?ExpectativaDaLista $esperado = null,
    ): ResultadoReconciliacaoHonorario {
        return $this->em->wrapInTransaction(
            fn (): ResultadoReconciliacaoHonorario => $this->processar($carteiras, $tenant, $usuario, $esperado, $idsAprovados),
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
        ?array $idsAprovados = null,
    ): ResultadoReconciliacaoHonorario {
        $aprovados = $idsAprovados === null ? null : array_flip($idsAprovados);
        $encontrados = [];
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

                $encontrados[(int) $obrigacao->getId()] = true;

                // 🔴 INV-H0. A varredura PROPÕE; quem escolhe é o humano. O guard do importador
                // condiciona ao valor bater com a planilha (§10.5.1) e aqui esse dado não existe —
                // três réguas automáticas já foram tentadas para contornar isso e as três erravam
                // (§10.6). Fora da lista aprovada, não se escreve.
                if ($aprovados !== null && !isset($aprovados[(int) $obrigacao->getId()])) {
                    $puladas[] = [
                        'obrigacaoId' => (int) $obrigacao->getId(),
                        'unidade' => $unidade,
                        'referencia' => $obrigacao->getReferenciaExterna(),
                        'motivo' => 'não está na lista aprovada — o humano não a marcou para correção',
                        'honorarioQueFicou' => $obrigacao->getHonorarios(),
                    ];

                    continue;
                }

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
                    // `origem === null` conta como DENTRO, igual a `aplicarExigibilidade` (`aorig.id IS
                    // NULL` não exclui). Inalcançável pela consulta de hoje, mas o docblock promete ser
                    // a régua inteira — então tem de valer sozinha.
                    'foraDoExigivel' => ($substituto?->getStatus()->ehVigente() ?? false)
                        || ($obrigacao->getAcordoOrigem() !== null
                            && !$obrigacao->getAcordoOrigem()->getStatus()->ehVigente()),
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

        // 🔴 Id aprovado que já não é candidato: o universo mudou desde a simulação que o humano leu.
        // Abortar é o único caminho honesto — corrigir o resto entregaria uma correção parcial que
        // ninguém aprovou, em silêncio.
        if ($aprovados !== null) {
            $sumiram = array_values(array_diff(array_keys($aprovados), array_keys($encontrados)));

            if ($sumiram !== []) {
                throw new AprovacaoNaoConfereException($sumiram);
            }
        }

        // 🔴 A trava do TAMANHO do universo dispara ANTES de qualquer evento e DENTRO da transação: o
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
     * As DUAS metades da correção — implementadas na entidade, ver `Obrigacao::pararDeCobrarHonorario`.
     *
     * ⚠️ Uma redação anterior dizia aqui que "a obrigação substituída não é re-hidratada". **É** —
     * `MontarDetalheAcordoUseCase` hidrata `$acordo->getParcelas()` sempre que o acordo de ORIGEM é
     * vigente, e as 135 estão nessa condição (§10.4). Zerar o campo continua certo, mas pelo motivo
     * inverso: sem o override, a próxima hidratação recolocaria o honorário; sem zerar o campo, o
     * valor antigo fica no banco até que uma hidratação com flush passe por ali.
     *
     * 📌 Efeito de borda, registrado porque ninguém tinha notado: essa hidratação recarimba
     * `encargosAtualizadosEm` com "hoje". O INV-H2 preserva a data NA CORREÇÃO — ele não impede que a
     * tela a mova depois, o que é comportamento normal do modelo ao vivo, não regressão desta fatia.
     */
    private function aplicarNa(Obrigacao $obrigacao): void
    {
        // As DUAS metades vivem na ENTIDADE (`pararDeCobrarHonorario`) e não podem ser separadas: é
        // regra de dinheiro, e duas cópias divergem em silêncio. Escrever só o override aqui deixaria
        // a obrigação em `bp = 0` com honorário sobrando — estado que a consulta deste próprio comando
        // (`taxaHonorariosBp IS NULL`) nunca mais alcança.
        $obrigacao->pararDeCobrarHonorario();
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
            // 🔴 O QUE ESTE TEXTO PODE AFIRMAR — leia antes de "melhorá-lo".
            //
            // Ele fica GRAVADO no histórico do caso, em produção, para sempre: é o rastro pelo qual
            // alguém, meses depois, entende por que o honorário sumiu e decide se desfaz. Uma redação
            // anterior dizia "a contabilidade não cobra encargo em parcela de acordo". A frase é
            // verdadeira sobre o relatório de ACORDOS (0 de 8.671 linhas têm coluna de encargo) e
            // FALSA como regra: a parcela ATRASADA migra para o relatório de INADIMPLÊNCIA, que tem as
            // colunas, e lá ela cobra — 114 parcelas, R$ 6.601,57, medidos no lote de 17/08 (spec
            // §10.8). Carimbar a generalização no banco seria deixar um motivo errado no lugar em que
            // ninguém mais vai conferi-lo.
            //
            // Então o texto afirma só o que vale PARA ESTAS obrigações e nomeia a exceção. O que
            // autoriza a correção não é uma regra geral: é a conferência humana da lista (INV-H0).
            sprintf(
                'Honorário retirado de %d parcela(s) de acordo: R$ %s. Estas parcelas não aparecem em '
                . 'nenhum relatório da contabilidade que tenha coluna de encargo — no relatório de '
                . 'acordos ela publica só o Valor acordado —, e a lista foi conferida contra a planilha '
                . 'antes da aplicação. ATENÇÃO: isto NÃO é regra geral. Parcela de acordo que ATRASA '
                . 'migra para o relatório de inadimplência, e lá a contabilidade cobra encargo '
                . 'normalmente.',
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
