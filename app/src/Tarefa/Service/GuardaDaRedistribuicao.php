<?php

declare(strict_types=1);

namespace App\Tarefa\Service;

use App\Entity\Auth\User;
use App\Entity\Tarefa\Tarefa;
use App\Entity\Tenant\Tenant;
use App\Repository\UserRepository;
use App\Service\NotificacaoService;
use App\Tarefa\Exception\RedistribuicaoForaDoEscritorioException;
use App\Tarefa\Exception\RedistribuicaoNaoPermitidaException;
use App\Tarefa\Exception\RedistribuicaoRecusadaException;
use App\Tenant\Service\RegraDosPrimarios;

/**
 * As guardas comuns de listar e executar a redistribuição (spec painel-acompanhamento-redistribuir, fatia B):
 * quem age é o Master ou um primário ativo (403); a origem é colaboradora ativa do escritório da sessão (404, IDOR) e
 * não é a conta do Master (400); e quem pode receber: colaboradora ativa do escritório, diferente da origem e nunca a
 * conta do Master. Que o destino veja a pasta da meta (cadeado) é conferido por meta ({@see self::veAPasta()}).
 */
final class GuardaDaRedistribuicao
{
    public function __construct(
        private readonly UserRepository $userRepository,
        private readonly RegraDosPrimarios $regraDosPrimarios,
        private readonly NotificacaoService $notificacoes,
    ) {
    }

    /**
     * @throws RedistribuicaoNaoPermitidaException
     * @throws RedistribuicaoForaDoEscritorioException
     * @throws RedistribuicaoRecusadaException
     */
    public function exigirOrigem(Tenant $tenant, User $ator, int $origemId): User
    {
        if (!$this->regraDosPrimarios->podeGerirPrimarios($ator, $tenant)) {
            throw new RedistribuicaoNaoPermitidaException('Seu perfil não pode redistribuir metas.');
        }
        $origem = $this->userRepository->findColaboradorAtivoPorIdETenant($origemId, $tenant);
        if ($origem === null) {
            throw new RedistribuicaoForaDoEscritorioException('Colaborador não encontrado.');
        }
        if ($this->regraDosPrimarios->ehMaster($origem)) {
            throw new RedistribuicaoRecusadaException('As metas desta conta não podem ser redistribuídas aqui.');
        }

        return $origem;
    }

    /** @return array<int, User> id => colaboradora que pode receber, em ordem de id (o empate da sugestão é o menor id) */
    public function elegiveis(Tenant $tenant, User $origem): array
    {
        $pessoas = array_filter(
            $this->userRepository->findColaboradoresAtivosPorTenant($tenant),
            fn (User $u): bool => $u->getId() !== $origem->getId() && !$this->regraDosPrimarios->ehMaster($u),
        );
        usort($pessoas, static fn (User $a, User $b): int => $a->getId() <=> $b->getId());

        $porId = [];
        foreach ($pessoas as $pessoa) {
            $porId[(int) $pessoa->getId()] = $pessoa;
        }

        return $porId;
    }

    /** Cadeado: a pessoa vê a pasta da meta. */
    public function veAPasta(Tarefa $meta, User $pessoa): bool
    {
        return $this->notificacoes->veAPastaDaMeta($meta, $pessoa);
    }
}
