<?php

declare(strict_types=1);

namespace App\Tenant\Service;

use App\Entity\Tenant\Tenant;
use App\Repository\TenantRepository;

/**
 * Dá ao escritório o código da conta quando ele ainda não tem (a migration não preenche os existentes: o código nasce
 * no primeiro acesso à tela Meu Escritório). Tenta de novo se o código sorteado já existir. Só altera a entidade: o
 * `flush` é de quem chama (o `AuditLogSubscriber` registra a mudança do `codigoConta` do Tenant).
 */
final class CodigoDaContaDoEscritorio
{
    private const TENTATIVAS = 10;

    public function __construct(
        private readonly GeradorDeCodigoDaConta $gerador,
        private readonly TenantRepository $escritorios,
    ) {
    }

    /** Devolve true quando gerou um código novo (o chamador deve dar o flush). */
    public function garantir(Tenant $tenant): bool
    {
        if ($tenant->getCodigoConta() !== null) {
            return false;
        }
        for ($i = 0; $i < self::TENTATIVAS; ++$i) {
            $codigo = $this->gerador->gerar();
            if (!$this->escritorios->existeCodigoDaConta($codigo)) {
                $tenant->setCodigoConta($codigo);

                return true;
            }
        }

        throw new \RuntimeException('Não foi possível gerar um código de conta único.');
    }
}
