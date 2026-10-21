<?php

declare(strict_types=1);

namespace App\Tenant\UseCase;

use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Master\Service\RegraDosMasters;
use App\Shared\Audit\EscopoDeAuditoriaDePlataforma;
use App\Tenant\Exception\PedidoDeExclusaoRecusado;
use App\Tenant\Repository\PedidoDeExclusaoDeContaRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Exclusão (soft delete) de um escritório: desativa (isActive=false) e marca a
 * quarentena (excluidoEm). Não remove dados — a purga definitiva fica para um job
 * futuro. Idempotente: excluir um escritório já excluído é no-op.
 *
 * A autorização (dono/admin do tenant ou SuperAdmin) é responsabilidade do
 * controller chamador — este UseCase só executa o soft delete.
 *
 * PR 5: com pedido de exclusão PENDENTE, só o Master decide (decisão do coordenador, 20/10): quem não é
 * Master é recusado (403). O Master decide o pedido ("excluida") na mesma transação da conta, e a decisão
 * concorrente com a desistência do primário devolve 409 a quem chega depois.
 *
 * Auditoria: o flush roda em {@see EscopoDeAuditoriaDePlataforma::executarComoConta()} com a conta excluída,
 * e não no escritório da sessão (a rota antiga pode ser chamada com outro escritório selecionado).
 */
final class ExcluirEscritorioUseCase
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly PedidoDeExclusaoDeContaRepository $pedidos,
        private readonly RegraDosMasters $regraDosMasters,
        private readonly EscopoDeAuditoriaDePlataforma $escopoDeAuditoria,
    ) {}

    /** @throws PedidoDeExclusaoRecusado 403 com pedido pendente e ator que não é Master; 409 pedido já decidido */
    public function executar(Tenant $tenant, User $ator): void
    {
        if ($tenant->isActive() !== true) {
            return;
        }

        $pedido = $this->pedidos->pendenteDoEscritorio($tenant);
        if ($pedido !== null && !$this->regraDosMasters->ehMaster($ator)) {
            throw new PedidoDeExclusaoRecusado('Esta conta tem um pedido de exclusão aguardando a decisão do Master. Só o Master pode excluí-la agora.', 403);
        }

        $tenant->setIsActive(false);
        $tenant->setExcluidoEm(new \DateTimeImmutable());
        $gravar = fn () => $this->escopoDeAuditoria->executarComoConta((int) $tenant->getId(), fn () => $this->em->flush());

        if ($pedido === null) {
            $gravar();

            return;
        }

        $pedido->marcarExcluida($ator);
        $this->pedidos->gravarDecisaoSePendente($pedido, $gravar);
    }
}
