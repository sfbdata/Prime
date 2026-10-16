<?php

declare(strict_types=1);

namespace App\Central\UseCase;

use App\Central\DTO\PreferenciasOutput;
use App\Central\Exception\ConversaNaoEncontradaException;
use App\Central\Exception\PedidoInvalidoNaCentralException;
use App\Central\Exception\SemAcessoACentralException;
use App\Central\Service\AcessoACentral;
use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Fixar, silenciar e arquivar (`docs/specs/central-comunicacao-b.md` §4) — preferências SÓ de quem pede, gravadas na
 * participação dele: a de ninguém mais muda. Silenciar não impede receber; arquivar não apaga nada. Só as chaves
 * enviadas mudam.
 */
final class DefinirPreferenciasDaConversaUseCase
{
    public function __construct(
        private readonly AcessoACentral $acesso,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * @throws SemAcessoACentralException
     * @throws ConversaNaoEncontradaException
     * @throws PedidoInvalidoNaCentralException
     */
    public function executar(User $eu, ?Tenant $tenant, int $conversaId, ?bool $fixada, ?bool $silenciada, ?bool $arquivada): PreferenciasOutput
    {
        $tenant = $this->acesso->exigirVinculoAtivo($eu, $tenant);
        $participacao = $this->acesso->participacao($conversaId, $eu, $tenant);

        if ($fixada === null && $silenciada === null && $arquivada === null) {
            throw new PedidoInvalidoNaCentralException('Nenhuma preferência informada.');
        }
        if ($fixada !== null) {
            $participacao->fixar($fixada);
        }
        if ($silenciada !== null) {
            $participacao->silenciar($silenciada);
        }
        if ($arquivada !== null) {
            $participacao->arquivar($arquivada);
        }
        $this->entityManager->flush();

        return new PreferenciasOutput($participacao->isFixada(), $participacao->isSilenciada(), $participacao->isArquivada());
    }
}
