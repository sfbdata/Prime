<?php

declare(strict_types=1);

namespace App\Ponto\UseCase;

use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Ponto\DTO\AbonoDaEquipeOutput;
use App\Ponto\DTO\AbonoDoLoteOutput;
use App\Ponto\DTO\ReversaoDaJustificativaOutput;
use App\Ponto\Entity\JustificativaPonto;
use App\Ponto\Enum\AcaoDaTrilhaDoPonto;
use App\Ponto\Exception\AnaliseDeJustificativaRecusadaException;
use App\Ponto\Repository\JustificativaPontoRepository;
use App\Ponto\Repository\RegistroPontoRepository;
use App\Ponto\Service\AnaliseDaPropriaJustificativa;
use App\Ponto\Service\DetalheDaTrilhaDoPonto;
use App\Ponto\Service\GravacaoDoAjusteDoPonto;
use App\Ponto\Service\NomesDasSituacoesDaJustificativa;
use App\Ponto\Service\TrilhaDoPonto;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Grava a análise do gestor sobre uma justificativa de ponto: abonar, recusar, voltar para análise e o "aprovar
 * todos" do lote (ficha do colaborador e aba "Minhas Justificativas", rotas do TenantController).
 *
 * Quem: o gestor que já passou, no controller, pela permissão, pela posse (escritório da URL) e pela regra da
 * própria (P43 a). Pré-condição: a justificativa foi lida pelo escritório informado; as recusas rápidas (situação
 * da ficha, batida já registrada, horário futuro) também ficaram no controller.
 *
 * ## Por que relê sob a trava (corrida da E3, docs/specs/ponto-desenho-0811.md)
 *
 * O controller lê a justificativa no começo da requisição. Entre essa leitura e a gravação o colaborador pode
 * excluí-la (P41, `ExcluirJustificativaEmAnaliseUseCase`) e comitar: sem releitura, o UPDATE do status atinge zero
 * linhas sem erro e a batida do esquecimento é gravada para um pedido que não existe. Aqui a situação é relida do
 * banco com a linha travada (`FOR UPDATE`, `statusNoBancoTravadoPorId`), na mesma transação da gravação: a exclusão
 * que já comitou é vista (a linha sumiu) e a que chega depois espera este COMMIT e encontra a justificativa analisada.
 *
 * As recusas não mudam nada: a transação comita vazia (solta a trava) e a recusa sobe depois, com o EntityManager
 * aberto para o resto da requisição, como em `ExcluirJustificativaEmAnaliseUseCase`.
 *
 * ## Trilha de auditoria (E9)
 *
 * Cada análise gravada entra na trilha do Ponto ({@see TrilhaDoPonto}) na mesma transação, depois da trava da linha:
 * abonada, recusada, voltou para análise e, no "aprovar todos" da ficha e no "Abonar selecionadas" da aba Equipe, um
 * evento "Abono em lote" com as abonadas (detalhes diferentes: `doLote` e `daEquipe`). A batida
 * do esquecimento entra pelo `RegistrarBatidaDoEsquecimentoUseCase`. A análise barrada sob a trava
 * (`AnaliseDeJustificativaRecusadaException`: excluída ou situação mudou) não grava evento, porque nada mudou; já a
 * justificativa recusada pelo gestor grava "Justificativa recusada". Se o fato falha depois do evento (horário futuro
 * na batida do esquecimento, por exemplo), a transação volta inteira e o evento sai junto.
 *
 * ## Batida do esquecimento é ajuste (E10, plano-e10-ponto)
 *
 * O abono do esquecimento grava uma INCLUSÃO em `ponto_ajuste`, ligada à justificativa ({@see GravacaoDoAjusteDoPonto});
 * voltar para análise a abonada desconsidera essa inclusão (evento "Ajuste desfeito"), com o registro guardado. O abono
 * de antes da E10 gravou a batida em `registro_ponto`: ela fica, e a tela avisa como tirá-la da conta (decisão 3).
 *
 * Ordem das travas, sem inversão: a linha da justificativa (`FOR UPDATE`), depois a linhagem da pessoa (4210, logo em
 * seguida, antes de qualquer evento) e por último a trilha do escritório (4203, dentro de cada evento). No "aprovar
 * todos", as linhas em ordem de id e as pessoas em ordem crescente de id, antes do evento do lote.
 *
 * ## Contrato com a aba Equipe (E5)
 *
 * `abonarPorId` é a porta estável para abonar UMA justificativa sem a ficha: busca pelo escritório, aplica a regra da
 * própria (P43 a) e as recusas rápidas da ficha, e só então `abonar`. É a da própria de quem analisa no "Abonar
 * selecionadas" (desenho L818: analisada uma a uma, com o evento dela). As da equipe vão por `abonarDaEquipe`, numa
 * transação só com o evento único. A permissão de analisar (`admin.users.manage`, `GestaoDoPonto::veEquipe`) e o aviso
 * do sininho (`NotificacaoService::notificarJustificativaAprovada`) ficam com quem chama, como na ficha. Erro sempre por
 * `AnaliseDeJustificativaRecusadaException`; nada de flash nem Request aqui.
 */
final class AnalisarJustificativaUseCase
{
    /** O motivo da desconsideração da inclusão do esquecimento quando a justificativa volta para análise. */
    public const MOTIVO_DA_VOLTA_PARA_ANALISE = 'A justificativa do esquecimento voltou para análise: o abono foi desfeito.';

    private const EM_ANALISE = 'pendente';

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly JustificativaPontoRepository $repositorio,
        private readonly RegistrarBatidaDoEsquecimentoUseCase $registrarBatida,
        private readonly AnaliseDaPropriaJustificativa $analiseDaPropria,
        private readonly TrilhaDoPonto $trilha,
        private readonly GravacaoDoAjusteDoPonto $gravacao,
        private readonly RegistroPontoRepository $batidas,
    ) {
    }

    /**
     * Abona uma justificativa pelo id, com as mesmas regras da ficha (porta da aba Equipe, E5).
     *
     * @return JustificativaPonto a abonada, para o aviso do sininho de quem chama
     *
     * @throws AnaliseDeJustificativaRecusadaException não encontrada no escritório, a própria sem ser primário nem
     *                                                 Master, fora de análise, batida já registrada, horário futuro
     *                                                 ou excluída pelo colaborador
     */
    public function abonarPorId(int $justificativaId, User $analista, Tenant $escritorio, ?string $observacao = null): JustificativaPonto
    {
        $justificativa = $this->repositorio->findOneBy(['id' => $justificativaId, 'tenant' => $escritorio]);
        $dono = $justificativa?->getUser();
        if (!$justificativa instanceof JustificativaPonto || $dono === null) {
            throw AnaliseDeJustificativaRecusadaException::naoEncontrada();
        }
        if (!$this->analiseDaPropria->permite($analista, $dono, $escritorio)) {
            throw AnaliseDeJustificativaRecusadaException::propria();
        }
        $this->exigirAbonavel($justificativa, $escritorio);

        $this->abonar($justificativa, $escritorio, $analista, trim((string) $observacao));

        return $justificativa;
    }

    /**
     * Abona e, no esquecimento de registro, grava a inclusão da batida pedida, tudo na mesma transação.
     *
     * @throws AnaliseDeJustificativaRecusadaException excluída pelo colaborador ou já analisada
     */
    public function abonar(JustificativaPonto $justificativa, Tenant $tenant, User $analista, string $observacao): void
    {
        $this->gravarSobATrava($justificativa, $tenant, true, 'analise.so_para_abonar', function () use ($justificativa, $tenant, $analista, $observacao): void {
            $this->marcarAbonada($justificativa, $analista, $observacao);
            $this->registrarNaTrilha(AcaoDaTrilhaDoPonto::JustificativaAbonada, $justificativa, $tenant, $analista);
            ($this->registrarBatida)($justificativa, $tenant, $analista);
        });
    }

    /** @throws AnaliseDeJustificativaRecusadaException excluída pelo colaborador ou já analisada */
    public function recusar(JustificativaPonto $justificativa, Tenant $tenant, User $analista, string $observacao): void
    {
        $this->gravarSobATrava($justificativa, $tenant, true, 'analise.so_para_recusar', function () use ($justificativa, $tenant, $analista, $observacao): void {
            $justificativa->setStatus('rejeitado');
            $justificativa->setDataAnalise(new \DateTime());
            $justificativa->setAnalisadoPor($analista);
            $justificativa->setObservacaoAnalise($observacao);
            $this->registrarNaTrilha(AcaoDaTrilhaDoPonto::JustificativaRecusada, $justificativa, $tenant, $analista);
        });
    }

    /**
     * Volta a justificativa analisada para a análise. Da abonada, a inclusão que o abono do esquecimento criou sai da
     * conta (desconsiderada, evento "Ajuste desfeito"), na mesma transação; a batida do abono de antes da E10, gravada
     * em `registro_ponto`, fica (decisão 3).
     *
     * @return ReversaoDaJustificativaOutput a situação de antes, lida sob a trava, e o que houve com a batida do abono
     *
     * @throws AnaliseDeJustificativaRecusadaException excluída ou já em análise
     */
    public function reverter(JustificativaPonto $justificativa, Tenant $tenant, User $analista): ReversaoDaJustificativaOutput
    {
        $anterior      = (string) $justificativa->getStatus();
        $batidaDoAbono = null;
        $this->gravarSobATrava($justificativa, $tenant, false, 'reverter.ja_esta', function () use ($justificativa, $tenant, $analista, &$anterior, &$batidaDoAbono): void {
            $justificativa->setStatus('pendente');
            $justificativa->setAnalisadoPor(null);
            $justificativa->setDataAnalise(null);
            $justificativa->setObservacaoAnalise(null);
            $this->registrarNaTrilha(AcaoDaTrilhaDoPonto::JustificativaReaberta, $justificativa, $tenant, $analista, ['situacao_anterior' => $anterior]);
            if ($anterior === 'abonado') {
                $batidaDoAbono = $this->desfazerAInclusaoDoAbono($justificativa, $analista);
            }
        }, $anterior);

        return new ReversaoDaJustificativaOutput((string) $anterior, $batidaDoAbono);
    }

    /**
     * "Aprovar todos": trava todas as linhas antes de gravar qualquer uma (em ordem de id, para dois lotes não se
     * esperarem em ordem trocada) e abona só as que continuam em análise. As que sumiram entram na contagem.
     *
     * @param list<JustificativaPonto> $pendentes
     */
    public function abonarLote(array $pendentes, Tenant $tenant, User $analista): AbonoDoLoteOutput
    {
        return $this->em->wrapInTransaction(function () use ($pendentes, $tenant, $analista): AbonoDoLoteOutput {
            $emAnalise = [];
            $excluidas = 0;
            foreach ($this->emOrdemDeId($pendentes) as $justificativa) {
                $status = $this->repositorio->statusNoBancoTravadoPorId((int) $justificativa->getId(), $tenant);
                $excluidas += $status === null ? 1 : 0;
                if ($status === self::EM_ANALISE) {
                    $emAnalise[] = $justificativa;
                }
            }

            // A linhagem de cada pessoa (4210), em ordem crescente de id, antes do evento do lote (4203 vem por último).
            foreach ($this->pessoasEmOrdemDeId($emAnalise) as $pessoa) {
                $this->gravacao->travar($pessoa);
            }
            if ($emAnalise !== []) {
                $this->trilha->registrar($tenant, $analista, AcaoDaTrilhaDoPonto::AbonoEmLote->value, DetalheDaTrilhaDoPonto::doLote($emAnalise));
            }
            foreach ($emAnalise as $justificativa) {
                $this->marcarAbonada($justificativa, $analista, '');
                ($this->registrarBatida)($justificativa, $tenant, $analista);
                // Por item, como antes: a conferência da batida já registrada (R1) do próximo enxerga esta.
                $this->em->flush();
            }

            return new AbonoDoLoteOutput($emAnalise, $excluidas);
        });
    }

    /**
     * "Abonar selecionadas" da aba Equipe (E5, desenho L818): abona as justificativas da equipe que quem chama já
     * conferiu (escritório da sessão, vínculo ativo) numa transação só, e a trilha ganha UM evento "Abono em lote" com
     * a lista das abonadas ({@see DetalheDaTrilhaDoPonto::daEquipe}), no mesmo COMMIT dos abonos.
     *
     * Cada uma passa pelas regras do abono da ficha com a linha travada (escritório, P43 a, excluída, fora de análise,
     * batida já registrada, horário futuro): a recusa vira o motivo dela e as outras seguem, sem desfazer nada. Trava
     * todas as linhas antes de gravar qualquer uma, em ordem de id, como o "aprovar todos"; a trilha é a última trava.
     * Nenhuma abonada, nenhum evento. Uma falha inesperada volta a transação inteira, abonos e evento juntos.
     *
     * @param list<JustificativaPonto> $daEquipe
     */
    public function abonarDaEquipe(array $daEquipe, Tenant $tenant, User $analista): AbonoDaEquipeOutput
    {
        return $this->em->wrapInTransaction(function () use ($daEquipe, $tenant, $analista): AbonoDaEquipeOutput {
            $situacoes = $this->travarEmOrdemDeId($daEquipe, $tenant);
            $abonadas  = [];
            $recusas   = [];
            foreach ($daEquipe as $justificativa) {
                $id     = (int) $justificativa->getId();
                $recusa = $this->recusaDaEquipeSobATrava($justificativa, $situacoes[$id] ?? null, $tenant, $analista);
                if ($recusa !== null) {
                    $recusas[$id] = $recusa->getMessage();
                    continue;
                }
                $this->marcarAbonada($justificativa, $analista, '');
                ($this->registrarBatida)($justificativa, $tenant);
                // Por item, como no "aprovar todos": a conferência da batida já registrada (R1) da próxima enxerga esta.
                $this->em->flush();
                $abonadas[] = $justificativa;
            }
            if ($abonadas !== []) {
                $this->trilha->registrar($tenant, $analista, AcaoDaTrilhaDoPonto::AbonoEmLote->value, DetalheDaTrilhaDoPonto::daEquipe($abonadas));
            }

            return new AbonoDaEquipeOutput($abonadas, $recusas);
        });
    }

    /**
     * @param \Closure(): void $gravar        roda com a linha travada, só quando a situação é a esperada
     * @param bool             $querEmAnalise abonar e recusar pedem "em análise"; voltar para análise, o contrário
     * @param string|null      $anterior      recebe a situação lida sob a trava
     */
    private function gravarSobATrava(JustificativaPonto $justificativa, Tenant $tenant, bool $querEmAnalise, string $recusa, \Closure $gravar, ?string &$anterior = null): void
    {
        $falha = $this->em->wrapInTransaction(function () use ($justificativa, $tenant, $querEmAnalise, $recusa, $gravar, &$anterior): ?AnaliseDeJustificativaRecusadaException {
            $status = $this->repositorio->statusNoBancoTravadoPorId((int) $justificativa->getId(), $tenant);
            // E10: a linhagem da pessoa logo depois da linha, antes de qualquer evento da trilha (ordem sem inversão).
            $this->gravacao->travar(self::pessoaDa($justificativa));
            if ($status === null) {
                return AnaliseDeJustificativaRecusadaException::excluida();
            }
            if (($status === self::EM_ANALISE) !== $querEmAnalise) {
                return AnaliseDeJustificativaRecusadaException::situacaoMudou(NomesDasSituacoesDaJustificativa::texto($recusa));
            }

            $anterior = $status;
            $gravar();

            return null;
        });

        if ($falha instanceof AnaliseDeJustificativaRecusadaException) {
            throw $falha;
        }
    }

    /** As recusas rápidas da ficha (TenantController::aprovarJustificativa), na mesma ordem. */
    private function exigirAbonavel(JustificativaPonto $justificativa, Tenant $escritorio): void
    {
        $recusa = $justificativa->getStatus() !== self::EM_ANALISE
            ? self::foraDeAnalise()
            : $this->recusaDaBatida($justificativa, $escritorio);
        if ($recusa !== null) {
            throw $recusa;
        }
    }

    /**
     * As regras do abono da ficha para uma da equipe, com a linha já travada e a situação lida sob a trava (null: a
     * linha sumiu). Null quando pode abonar.
     */
    private function recusaDaEquipeSobATrava(JustificativaPonto $justificativa, ?string $situacao, Tenant $tenant, User $analista): ?AnaliseDeJustificativaRecusadaException
    {
        $dono = $justificativa->getUser();
        if ($dono === null || $justificativa->getTenant()?->getId() !== $tenant->getId()) {
            return AnaliseDeJustificativaRecusadaException::naoEncontrada();
        }
        if (!$this->analiseDaPropria->permite($analista, $dono, $tenant)) {
            return AnaliseDeJustificativaRecusadaException::propria();
        }
        if ($situacao === null) {
            return AnaliseDeJustificativaRecusadaException::excluida();
        }

        return $situacao !== self::EM_ANALISE ? self::foraDeAnalise() : $this->recusaDaBatida($justificativa, $tenant);
    }

    /** As recusas da batida do esquecimento, na ordem da ficha: já registrada no dia (R1) e horário que não chegou. */
    private function recusaDaBatida(JustificativaPonto $justificativa, Tenant $escritorio): ?AnaliseDeJustificativaRecusadaException
    {
        if (($jaBatida = $this->registrarBatida->recusa($justificativa, $escritorio)) !== null) {
            return AnaliseDeJustificativaRecusadaException::batidaJaRegistrada($jaBatida);
        }

        return $this->registrarBatida->pedeHorarioFuturo($justificativa) ? AnaliseDeJustificativaRecusadaException::horarioFuturo() : null;
    }

    private static function foraDeAnalise(): AnaliseDeJustificativaRecusadaException
    {
        return AnaliseDeJustificativaRecusadaException::situacaoMudou(NomesDasSituacoesDaJustificativa::texto('analise.so_para_abonar'));
    }

    /**
     * Trava as linhas em ordem de id (dois lotes não se esperam em ordem trocada) e devolve a situação de cada uma
     * lida sob a trava, pelo id; a que sumiu fica de fora.
     *
     * @param list<JustificativaPonto> $justificativas
     *
     * @return array<int, string>
     */
    private function travarEmOrdemDeId(array $justificativas, Tenant $tenant): array
    {
        $situacoes = [];
        foreach ($this->emOrdemDeId($justificativas) as $justificativa) {
            $id     = (int) $justificativa->getId();
            $status = $this->repositorio->statusNoBancoTravadoPorId($id, $tenant);
            if ($status !== null) {
                $situacoes[$id] = $status;
            }
        }

        return $situacoes;
    }

    /**
     * A inclusão que o abono deste esquecimento criou sai da conta, ligada à justificativa e desfazendo a inclusão
     * (evento "Ajuste desfeito"). Roda sob as travas da justificativa e da pessoa.
     *
     * @return string|null o que aconteceu com a batida do abono ({@see ReversaoDaJustificativaOutput}); null quando
     *                     não há o que mencionar (não é esquecimento, ou a inclusão já estava fora da conta)
     */
    private function desfazerAInclusaoDoAbono(JustificativaPonto $justificativa, User $analista): ?string
    {
        $inclusao = $this->batidas->inclusaoDoEsquecimento($justificativa);
        if ($inclusao === null) {
            // Sem inclusão: o abono de antes da E10 gravou a batida em registro_ponto, e ela fica (decisão 3).
            return $justificativa->getTipo() === 'esquecimento_registro' ? ReversaoDaJustificativaOutput::BATIDA_DE_ANTES : null;
        }
        if ($inclusao->desconsiderada()) {
            return null;
        }

        $this->gravacao->desconsiderar($inclusao, self::MOTIVO_DA_VOLTA_PARA_ANALISE, $analista, $justificativa, $inclusao->raizInclusao);

        return ReversaoDaJustificativaOutput::BATIDA_DESCONSIDERADA;
    }

    private static function pessoaDa(JustificativaPonto $justificativa): User
    {
        return $justificativa->getUser() ?? throw new \LogicException('Justificativa sem pessoa.');
    }

    /** @param array<string, mixed> $extra */
    private function registrarNaTrilha(AcaoDaTrilhaDoPonto $acao, JustificativaPonto $justificativa, Tenant $tenant, User $analista, array $extra = []): void
    {
        $this->trilha->registrar($tenant, $analista, $acao->value, DetalheDaTrilhaDoPonto::daJustificativa($justificativa) + $extra);
    }

    private function marcarAbonada(JustificativaPonto $justificativa, User $analista, string $observacao): void
    {
        $justificativa->setStatus('abonado');
        $justificativa->setDataAnalise(new \DateTime());
        $justificativa->setAnalisadoPor($analista);
        if ($observacao !== '') {
            $justificativa->setObservacaoAnalise($observacao);
        }
    }

    /**
     * As pessoas das justificativas, uma vez cada, em ordem crescente de id (a ordem da trava 4210 entre pessoas).
     *
     * @param list<JustificativaPonto> $justificativas
     *
     * @return list<User>
     */
    private function pessoasEmOrdemDeId(array $justificativas): array
    {
        $pessoas = [];
        foreach ($justificativas as $justificativa) {
            $pessoa = self::pessoaDa($justificativa);
            $pessoas[(int) $pessoa->getId()] = $pessoa;
        }
        ksort($pessoas);

        return array_values($pessoas);
    }

    /**
     * @param list<JustificativaPonto> $justificativas
     *
     * @return list<JustificativaPonto>
     */
    private function emOrdemDeId(array $justificativas): array
    {
        usort($justificativas, static fn (JustificativaPonto $a, JustificativaPonto $b): int => (int) $a->getId() <=> (int) $b->getId());

        return $justificativas;
    }
}
