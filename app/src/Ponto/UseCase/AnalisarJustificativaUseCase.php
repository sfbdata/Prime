<?php

declare(strict_types=1);

namespace App\Ponto\UseCase;

use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Ponto\DTO\AbonoDoLoteOutput;
use App\Ponto\Entity\JustificativaPonto;
use App\Ponto\Enum\AcaoDaTrilhaDoPonto;
use App\Ponto\Exception\AnaliseDeJustificativaRecusadaException;
use App\Ponto\Repository\JustificativaPontoRepository;
use App\Ponto\Service\AnaliseDaPropriaJustificativa;
use App\Ponto\Service\DetalheDaTrilhaDoPonto;
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
 * abonada, recusada, voltou para análise e, no "aprovar todos", um evento "Abono em lote" com as abonadas. A batida
 * do esquecimento entra pelo `RegistrarBatidaDoEsquecimentoUseCase`. Recusa não grava evento.
 *
 * ## Contrato com a aba Equipe (E5)
 *
 * `abonarPorId` é a porta estável para abonar UMA justificativa sem a ficha: busca pelo escritório, aplica a regra da
 * própria (P43 a) e as recusas rápidas da ficha, e só então `abonar`. A permissão de analisar (`admin.users.manage`,
 * `GestaoDoPonto::veEquipe`) e o aviso do sininho (`NotificacaoService::notificarJustificativaAprovada`) ficam com quem
 * chama, como na ficha. Erro sempre por `AnaliseDeJustificativaRecusadaException`; nada de flash nem Request aqui.
 */
final class AnalisarJustificativaUseCase
{
    private const EM_ANALISE = 'pendente';

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly JustificativaPontoRepository $repositorio,
        private readonly RegistrarBatidaDoEsquecimentoUseCase $registrarBatida,
        private readonly AnaliseDaPropriaJustificativa $analiseDaPropria,
        private readonly TrilhaDoPonto $trilha,
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
     * Abona e, no esquecimento de registro, grava a batida pedida, tudo na mesma transação.
     *
     * @throws AnaliseDeJustificativaRecusadaException excluída pelo colaborador ou já analisada
     */
    public function abonar(JustificativaPonto $justificativa, Tenant $tenant, User $analista, string $observacao): void
    {
        $this->gravarSobATrava($justificativa, $tenant, true, 'analise.so_para_abonar', function () use ($justificativa, $tenant, $analista, $observacao): void {
            $this->marcarAbonada($justificativa, $analista, $observacao);
            $this->registrarNaTrilha(AcaoDaTrilhaDoPonto::JustificativaAbonada, $justificativa, $tenant, $analista);
            ($this->registrarBatida)($justificativa, $tenant);
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
     * Volta a justificativa analisada para a análise. A batida que o abono do esquecimento criou fica (E10).
     *
     * @return string a situação de antes, lida sob a trava
     *
     * @throws AnaliseDeJustificativaRecusadaException excluída ou já em análise
     */
    public function reverter(JustificativaPonto $justificativa, Tenant $tenant, User $analista): string
    {
        $anterior = (string) $justificativa->getStatus();
        $this->gravarSobATrava($justificativa, $tenant, false, 'reverter.ja_esta', function () use ($justificativa, $tenant, $analista, &$anterior): void {
            $justificativa->setStatus('pendente');
            $justificativa->setAnalisadoPor(null);
            $justificativa->setDataAnalise(null);
            $justificativa->setObservacaoAnalise(null);
            $this->registrarNaTrilha(AcaoDaTrilhaDoPonto::JustificativaReaberta, $justificativa, $tenant, $analista, ['situacao_anterior' => $anterior]);
        }, $anterior);

        return $anterior;
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

            if ($emAnalise !== []) {
                $this->trilha->registrar($tenant, $analista, AcaoDaTrilhaDoPonto::AbonoEmLote->value, DetalheDaTrilhaDoPonto::doLote($emAnalise));
            }
            foreach ($emAnalise as $justificativa) {
                $this->marcarAbonada($justificativa, $analista, '');
                ($this->registrarBatida)($justificativa, $tenant);
                // Por item, como antes: a conferência da batida já registrada (R1) do próximo enxerga esta.
                $this->em->flush();
            }

            return new AbonoDoLoteOutput($emAnalise, $excluidas);
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
        if ($justificativa->getStatus() !== self::EM_ANALISE) {
            throw AnaliseDeJustificativaRecusadaException::situacaoMudou(NomesDasSituacoesDaJustificativa::texto('analise.so_para_abonar'));
        }
        if (($jaBatida = $this->registrarBatida->recusa($justificativa, $escritorio)) !== null) {
            throw AnaliseDeJustificativaRecusadaException::batidaJaRegistrada($jaBatida);
        }
        if ($this->registrarBatida->pedeHorarioFuturo($justificativa)) {
            throw AnaliseDeJustificativaRecusadaException::horarioFuturo();
        }
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
