<?php

declare(strict_types=1);

namespace App\Pasta\Twig;

use App\Entity\Auth\User;
use App\Pasta\Entity\Pasta;
use App\Pasta\Repository\PastaFavoritaRepository;
use App\Service\Tenant\TenantContext;
use Symfony\Bundle\SecurityBundle\Security;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * Pergunta dos templates sobre os favoritos do USUÁRIO LOGADO no escritório da sessão:
 *   - `pasta_favorita(pasta)`: o estado do item "Fixar nos favoritos" no menu ⋮ da pasta;
 *   - `pastas_favoritas_ids()`: o conjunto `[id => true]` que a listagem do Expediente consulta
 *     para desenhar a estrela — UMA consulta por listagem, não uma por linha.
 *
 * Está aqui (e não no controller) para a tela da pasta e as listagens não precisarem de variável
 * nova: o cabeçalho e a tabela são parciais incluídos por várias telas.
 *
 * Sem usuário ou sem escritório na sessão, ninguém tem favorito: devolve falso / vazio.
 */
final class PastaFavoritaExtension extends AbstractExtension
{
    public function __construct(
        private readonly PastaFavoritaRepository $repository,
        private readonly Security $security,
        private readonly TenantContext $tenantContext,
    ) {
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('pasta_favorita', $this->pastaFavorita(...)),
            new TwigFunction('pastas_favoritas_ids', $this->pastasFavoritasIds(...)),
        ];
    }

    public function pastaFavorita(Pasta $pasta): bool
    {
        $usuario = $this->security->getUser();
        $tenant  = $this->tenantContext->getCurrentTenant();

        if (!$usuario instanceof User || $tenant === null || $pasta->getTenant() !== $tenant) {
            return false;
        }

        return $this->repository->ehFavorita($pasta, $usuario, $tenant);
    }

    /** @return array<int, true> */
    public function pastasFavoritasIds(): array
    {
        $usuario = $this->security->getUser();
        $tenant  = $this->tenantContext->getCurrentTenant();

        if (!$usuario instanceof User || $tenant === null) {
            return [];
        }

        return $this->repository->idsDasPastasFavoritas($usuario, $tenant);
    }
}
