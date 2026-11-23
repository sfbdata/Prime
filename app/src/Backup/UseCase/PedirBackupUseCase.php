<?php

declare(strict_types=1);

namespace App\Backup\UseCase;

use App\Backup\Entity\PedidoDeBackup;
use App\Backup\Exception\PedidoDeBackupNaoPermitido;
use App\Backup\Message\GerarBackup;
use App\Backup\Repository\PedidoDeBackupRepository;
use App\Backup\Service\DonoDoEscritorio;
use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

/**
 * A pessoa pede um backup do escritório (spec backup-e-exportacao-de-dados, seção 5): no máximo um pedido aberto
 * (`pedido` ou `montando`) por pessoa e escritório, e no máximo um pedido nas últimas 24 horas corridas. Cria o pedido,
 * grava e manda a montagem para o segundo plano ({@see GerarBackup}).
 */
final class PedirBackupUseCase
{
    public const JANELA_DO_LIMITE = '-24 hours';

    /** Classe da trava `pg_advisory_xact_lock(classe, hashtext('tenant:usuario'))`: um pedido por vez por pessoa e escritório. */
    public const CLASSE_TRAVA = 4432;

    public function __construct(
        private readonly PedidoDeBackupRepository $pedidos,
        private readonly EntityManagerInterface $em,
        private readonly MessageBusInterface $bus,
        private readonly ClockInterface $clock,
        private readonly DonoDoEscritorio $dono,
    ) {
    }

    /**
     * @throws AccessDeniedException quem não é o dono do escritório (nada é criado nem despachado)
     * @throws PedidoDeBackupNaoPermitido
     */
    public function executar(User $usuario, Tenant $tenant): PedidoDeBackup
    {
        if (!$this->dono->ehDono($usuario, $tenant)) {
            throw new AccessDeniedException('Só o dono do escritório pode pedir o backup.');
        }

        $agora = \DateTimeImmutable::createFromInterface($this->clock->now());

        $pedido = $this->gravarSobTrava($usuario, $tenant, $agora);

        try {
            $this->bus->dispatch(new GerarBackup((int) $pedido->getId()));
        } catch (\Throwable $e) {
            // Sem fila o pedido ficaria "na fila" para sempre e travaria os próximos.
            $pedido->falhar('Não foi possível enfileirar o backup. Peça um novo backup.', $agora);
            $this->pedidos->salvar($pedido, flush: true);

            throw $e;
        }

        return $pedido;
    }

    /**
     * A checagem e o insert andam sob trava da pessoa: dois cliques ao mesmo tempo não criam dois pedidos. A trava só
     * solta no commit; o dispatch vem depois, para o worker nunca ler um pedido ainda não gravado. A transação é da
     * conexão (não `wrapInTransaction`, que fecha o EntityManager ao recusar um pedido por regra de negócio).
     */
    private function gravarSobTrava(User $usuario, Tenant $tenant, \DateTimeImmutable $agora): PedidoDeBackup
    {
        $tenantId = (int) $tenant->getId();
        $usuarioId = (int) $usuario->getId();
        $conexao = $this->em->getConnection();
        $conexao->beginTransaction();
        try {
            $conexao->executeStatement('SELECT pg_advisory_xact_lock(?, hashtext(?))', [self::CLASSE_TRAVA, $tenantId . ':' . $usuarioId]);

            if ($this->pedidos->temAbertoDaPessoa($tenantId, $usuarioId)) {
                throw PedidoDeBackupNaoPermitido::jaTemPedidoAberto();
            }
            if ($this->pedidos->quantosDaPessoaDesde($tenantId, $usuarioId, $agora->modify(self::JANELA_DO_LIMITE)) >= 1) {
                throw PedidoDeBackupNaoPermitido::limiteDoDia();
            }

            $pedido = new PedidoDeBackup($tenant, $usuario, $agora);
            $this->pedidos->salvar($pedido, flush: true);
            $conexao->commit();
        } catch (\Throwable $e) {
            $conexao->rollBack();

            throw $e;
        }

        return $pedido;
    }
}
