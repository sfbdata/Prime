<?php

declare(strict_types=1);

namespace App\Pasta\UseCase;

use App\Pasta\DTO\PastaRestaurada;
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
 * última, a próxima pasta criada recebe o mesmo número. Restaurar SEMPRE funciona: com o número
 * antigo livre a pasta volta com ele; ocupado por outra pasta viva do escritório, volta com o
 * próximo número livre (maior número + 1). A decisão roda sob a mesma trava da numeração, dentro
 * da transação, para não cruzar com uma criação ao mesmo tempo. O histórico guarda "antes era N"
 * porque o `AuditLogSubscriber` grava a troca de `nup` junto com a limpeza da lápide.
 */
final class RestaurarPastaUseCase
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly PastaRepository $pastas,
        private readonly NumeracaoDePastaInterface $numeracao,
    ) {}

    public function executar(Pasta $pasta, Tenant $tenant): PastaRestaurada
    {
        if ($pasta->getTenant() !== $tenant) {
            throw new AccessDeniedException('Pasta não pertence ao tenant do usuário.');
        }

        // O guard real é da entidade (restaurar() recusa pasta não excluída); aqui a checagem
        // existe para o controller poder responder "não há o que restaurar" sem estourar 500.
        if (!$pasta->estaExcluida()) {
            throw new \InvalidArgumentException('Esta pasta não está excluída.');
        }

        $numeroAntes = (string) $pasta->getNup();

        return $this->em->wrapInTransaction(function () use ($pasta, $tenant, $numeroAntes): PastaRestaurada {
            $this->numeracao->travar($tenant);

            $numeroNovo = $this->numeroOcupado($pasta, $tenant)
                ? (string) ($this->numeracao->maiorNumero($tenant) + 1)
                : $numeroAntes;

            $pasta->restaurar();
            if ($numeroNovo !== $numeroAntes) {
                $pasta->setNup($numeroNovo);
            }
            $this->em->flush();

            return new PastaRestaurada($numeroAntes, (string) $pasta->getNup());
        });
    }

    /** O número da lápide já é de outra pasta viva do escritório. */
    public function numeroOcupado(Pasta $pasta, Tenant $tenant): bool
    {
        $nup = $pasta->getNup();
        if ($nup === null || $nup === '') {
            return false;
        }

        // `findDoEscritorioPorNup` já deixa a lixeira de fora: a própria lápide não se acha.
        $ocupante = $this->pastas->findDoEscritorioPorNup($nup, $tenant);

        return $ocupante !== null && $ocupante !== $pasta;
    }
}
