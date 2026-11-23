<?php

declare(strict_types=1);

namespace App\Produto\Service;

use App\Entity\Auth\User;
use App\Master\Service\RegraDosMasters;
use App\Produto\Enum\Produto;
use App\Service\Tenant\TenantContext;
use Symfony\Bundle\SecurityBundle\Security;

/**
 * O que a tela mostra do produto (spec docs/specs/multiproduto-contratacao-e-voto.md, §7): a mesma régua da
 * GuardaDoProduto, para o menu nunca levar a um 404 nem esconder o que abre. Mostra tudo quando a chave está
 * `desligada`, sem login, para o Master ou sem escritório na sessão; senão, só os produtos do escritório da sessão.
 * Em `observacao` o menu já segue os produtos (D3: a mesma chave governa a guarda e o menu).
 */
final class ProdutosNaTela
{
    public function __construct(
        private readonly ChaveDoMultiproduto $chave,
        private readonly ProdutosDoEscritorio $produtos,
        private readonly Security $security,
        private readonly RegraDosMasters $regraDosMasters,
        private readonly TenantContext $tenantContext,
    ) {
    }

    /** @param Produto|list<Produto> $produtos com lista, basta um */
    public function inclui(Produto|array $produtos): bool
    {
        if (!$this->chave->ligada()) {
            return true;
        }
        $usuario = $this->security->getUser();
        if (!$usuario instanceof User || $this->regraDosMasters->ehMaster($usuario)) {
            return true;
        }
        $tenant = $this->tenantContext->getCurrentTenant();

        return null === $tenant || $this->produtos->inclui($tenant, $produtos);
    }
}
