<?php

declare(strict_types=1);

namespace App\Tenant\Service;

use App\Entity\Auth\User;
use App\Entity\Notificacao;
use App\Repository\UserRepository;
use App\Repository\UserTenantRepository;
use App\Service\NotificacaoService;
use App\Tenant\Entity\PedidoDeExclusaoDeConta;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Avisa os Masters de um pedido de exclusão (PR 5, decisão E5; desenho 07 L265): no sino de cada Master
 * ativo, título "Pedido de exclusão de conta" e texto "{escritório} pediu exclusão. Motivo: {motivo}". A
 * notificação é gravada no escritório da conta do próprio Master (nunca no do cliente: ela é auditada e
 * apareceria na trilha do cliente). Master sem escritório não tem sino: fica de fora. Falha aqui não
 * derruba o pedido: vai ao log.
 */
final class AvisoAoMasterDoPedidoDeExclusao
{
    public const TITULO = 'Pedido de exclusão de conta';

    public function __construct(
        private readonly UserRepository $usuarios,
        private readonly UserTenantRepository $vinculos,
        private readonly NotificacaoService $notificacoes,
        private readonly EntityManagerInterface $em,
        private readonly UrlGeneratorInterface $urls,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function avisar(PedidoDeExclusaoDeConta $pedido): void
    {
        try {
            $texto = sprintf('%s pediu exclusão. Motivo: %s', (string) $pedido->getTenant()->getName(), $pedido->getMotivo());
            $url = $this->urls->generate('master_painel');
            foreach ($this->usuarios->findComPapelGlobal('ROLE_SUPER_ADMIN') as $master) {
                $this->avisarUm($master, $texto, $url);
            }
            $this->em->flush();
        } catch (\Throwable $e) {
            $this->logger->error('Aviso do pedido de exclusão ao Master falhou.', ['pedido' => $pedido->getId(), 'erro' => $e::class]);
        }
    }

    private function avisarUm(User $master, string $texto, string $url): void
    {
        if (!$master->isActive()) {
            return;
        }
        $escritorio = ($this->vinculos->findActiveByUser($master)[0] ?? null)?->getTenant();
        if ($escritorio === null) {
            return;
        }

        $this->notificacoes
            ->criar($master, $escritorio, Notificacao::TIPO_CONTA_PEDIDO_EXCLUSAO, self::TITULO, $texto)
            ->setUrl($url);
    }
}
