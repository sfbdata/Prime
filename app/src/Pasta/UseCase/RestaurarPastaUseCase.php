<?php

declare(strict_types=1);

namespace App\Pasta\UseCase;

use App\Pasta\Entity\Pasta;
use App\Entity\Tenant\Tenant;
use App\Pasta\Repository\PastaRepository;
use App\Pasta\Service\NumeracaoDePastaInterface;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

/**
 * Desfaz a lápide: a pasta da lixeira volta a ser uma pasta comum, ativa e editável.
 *
 * Não há nada a devolver do disco nem do Drive — a exclusão-lápide não apagou nada. Quem restaura
 * é quem pode excluir (decisão do dono), e o rastro da exclusão continua no histórico e na
 * auditoria: o `AuditLogSubscriber` grava esta limpeza como uma alteração, então a pasta guarda as
 * duas pontas — quando foi excluída e quando voltou.
 *
 * Desde 18/11/2026 a pasta excluída não ocupa mais número (ver `NumeracaoDePasta`): se ela era a
 * última, a próxima pasta criada recebe o mesmo número. Por isso a restauração é recusada quando
 * outra pasta viva do escritório já tem o número da lápide: voltariam duas pastas iguais na lista.
 * A conferência roda sob a mesma trava da numeração, para não cruzar com uma criação ao mesmo tempo.
 */
final class RestaurarPastaUseCase
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly PastaRepository $pastas,
        private readonly NumeracaoDePastaInterface $numeracao,
    ) {}

    public function executar(Pasta $pasta, Tenant $tenant): void
    {
        if ($pasta->getTenant() !== $tenant) {
            throw new AccessDeniedException('Pasta não pertence ao tenant do usuário.');
        }

        // O guard real é da entidade (restaurar() recusa pasta não excluída); aqui a checagem
        // existe para o controller poder responder "não há o que restaurar" sem estourar 500.
        if (!$pasta->estaExcluida()) {
            throw new \InvalidArgumentException('Esta pasta não está excluída.');
        }

        $this->em->wrapInTransaction(function () use ($pasta, $tenant): void {
            $this->numeracao->travar($tenant);
            $this->recusarSeONumeroJaFoiReusado($pasta, $tenant);

            $pasta->restaurar();
            $this->em->flush();
        });
    }

    private function recusarSeONumeroJaFoiReusado(Pasta $pasta, Tenant $tenant): void
    {
        $nup = $pasta->getNup();
        if ($nup === null || $nup === '') {
            return;
        }

        // `findDoEscritorioPorNup` já deixa a lixeira de fora: a própria lápide não se acha.
        $ocupante = $this->pastas->findDoEscritorioPorNup($nup, $tenant);
        if ($ocupante === null || $ocupante === $pasta) {
            return;
        }

        $cliente = trim((string) $ocupante->getNomeCliente());

        throw new \InvalidArgumentException(sprintf(
            'O número %s já é usado por outra pasta%s. Mude o número dela antes de restaurar esta.',
            $nup,
            $cliente === '' ? '' : ' (' . $cliente . ')',
        ));
    }
}
