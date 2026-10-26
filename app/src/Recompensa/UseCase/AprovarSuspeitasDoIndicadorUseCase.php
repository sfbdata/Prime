<?php

declare(strict_types=1);

namespace App\Recompensa\UseCase;

use App\Entity\Auth\User;
use App\Indicacao\Repository\IndicacaoRepository;
use App\Recompensa\DTO\AprovarSuspeitasDoIndicadorInput;
use App\Recompensa\Entity\AprovacaoDeContaDeRecompensa;
use App\Recompensa\Exception\IndicadorNaoEncontrado;
use App\Recompensa\Repository\AprovacaoDeContaDeRecompensaRepository;
use App\Recompensa\Service\SuspeitaDeConta;
use App\Recompensa\Exception\RecompensaRecusada;
use App\Shared\Audit\EscopoDeAuditoriaDePlataforma;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * "Revisar suspeitas e aprovar" (menu ⋮; desenho 05 Perfis 1.2 VS 25.10.26; spec perfis-1.2-vs-25-10-recompensas-e-planos,
 * F3). Risco ALTO: decide quais contas geram dinheiro.
 *
 * Quem dispara: o Master (ROLE_SUPER_ADMIN no controller). Dado de plataforma, sem tenant: o indicador é qualquer usuário
 * que já indicou; o id vem da URL e é conferido contra as indicações.
 * Fluxo principal: confere que a quantidade pendente no servidor é a que o Master viu (`vistas`) e aprova, de uma vez, as contas que a regra de suspeita retém e o Master ainda não aprovou; a partir daí
 * elas contam. Devolve quantas aprovou.
 * Idempotência: a trava das indicações do usuário serializa pedidos simultâneos e a aprovação é única por par (indicador,
 * conta); repetir não grava de novo e devolve zero.
 * Fluxos alternativos: indicador inexistente (404); quantidade pendente diferente da vista (422, nada é aprovado).
 * Auditoria: AprovacaoDeContaDeRecompensa é Auditavel; cada aprovação entra na trilha do Master.
 */
final class AprovarSuspeitasDoIndicadorUseCase
{
    public function __construct(
        private readonly IndicacaoRepository $indicacoes,
        private readonly AprovacaoDeContaDeRecompensaRepository $aprovacoes,
        private readonly EntityManagerInterface $em,
        private readonly ClockInterface $relogio,
        private readonly EscopoDeAuditoriaDePlataforma $escopoDePlataforma,
        private readonly TranslatorInterface $tradutor,
    ) {
    }

    /**
     * @throws IndicadorNaoEncontrado
     * @throws RecompensaRecusada
     */
    public function executar(AprovarSuspeitasDoIndicadorInput $input): int
    {
        if ($this->indicacoes->contarDoUsuario($input->userId) === 0) {
            throw new IndicadorNaoEncontrado('Indicador não encontrado.');
        }
        $indicador = $this->em->find(User::class, $input->userId) ?? throw new IndicadorNaoEncontrado('Indicador não encontrado.');

        $aprovadas = $this->escopoDePlataforma->executar(fn (): ?int => $this->indicacoes->emTransacaoTravada($input->userId, function () use ($input, $indicador): ?int {
            $pendentes = $this->contasSuspeitasPendentes($input->userId);
            if (count($pendentes) !== $input->vistas) {
                return null; // a recusa sai fora da transação: uma exceção dentro dela fecharia o EntityManager
            }
            $agora = $this->relogio->now();
            $aprovadas = 0;
            foreach ($pendentes as $contaId) {
                $conta = $this->em->find(User::class, $contaId);
                if ($conta !== null) {
                    $this->aprovacoes->salvar(new AprovacaoDeContaDeRecompensa($indicador, $conta, $input->ator, $agora));
                    ++$aprovadas;
                }
            }

            return $aprovadas;
        }));

        return $aprovadas ?? throw new RecompensaRecusada($this->tradutor->trans('master.recompensas.suspeitas_mudou'));
    }

    /** @return list<int> ids das contas retidas pela regra e ainda não aprovadas */
    private function contasSuspeitasPendentes(int $indicadorId): array
    {
        $ids = [];
        foreach ($this->indicacoes->contasParaRecompensas() as $conta) {
            if ($conta['indicador_id'] === $indicadorId && !$conta['aprovada'] && SuspeitaDeConta::daLinha($conta) !== []) {
                $ids[] = $conta['conta_id'];
            }
        }

        return $ids;
    }
}
