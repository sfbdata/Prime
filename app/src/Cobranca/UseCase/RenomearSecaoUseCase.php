<?php

declare(strict_types=1);

namespace App\Cobranca\UseCase;

use App\Cobranca\Entity\CobrancaSecao;
use App\Cobranca\Repository\CobrancaSecaoRepository;
use App\Entity\Tenant\Tenant;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

/**
 * Renomeia uma seção de documentos de um Caso de Cobrança (SPEC §15, Etapa 6).
 *
 * História: o gestor ajusta o rótulo da seção. Só uma seção do próprio escritório pode ser renomeada
 * (guarda multi-tenant, IDOR); nome vazio é erro de entrada. A normalização (upper/trim) fica a cargo
 * da entidade.
 */
final class RenomearSecaoUseCase
{
    public function __construct(
        private readonly CobrancaSecaoRepository $secaoRepository,
    ) {
    }

    public function executar(CobrancaSecao $secao, string $novoNome, Tenant $tenant): void
    {
        // Guarda multi-tenant: não se renomeia seção de outro escritório.
        if ($secao->getTenant() !== $tenant) {
            throw new AccessDeniedException('Seção não pertence ao tenant do usuário.');
        }

        if (trim($novoNome) === '') {
            throw new \InvalidArgumentException('O nome da seção é obrigatório.');
        }

        $secao->setNome($novoNome);

        $this->secaoRepository->salvar($secao, true);
    }
}
