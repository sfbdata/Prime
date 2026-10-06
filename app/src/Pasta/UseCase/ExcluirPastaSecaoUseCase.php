<?php

declare(strict_types=1);

namespace App\Pasta\UseCase;

use App\Entity\Auth\User;
use App\Pasta\Entity\PastaSecao;
use App\Entity\Tenant\Tenant;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

/**
 * Exclui UMA subpasta pela rota `pasta_secao_excluir` — desde o L7, manda a subárvore inteira para
 * a LIXEIRA (lápide com o mesmo carimbo), sem apagar linha nem arquivo. É a mesma operação do
 * excluir-lote com uma seção só, e por isso delega a `ExcluirItensDaPastaUseCase`: uma regra de
 * marcação, um lugar. Devolve as contagens que a rota responde (`subpastasRemovidas`,
 * `arquivosRemovidos`).
 */
final class ExcluirPastaSecaoUseCase
{
    public function __construct(
        private readonly ExcluirItensDaPastaUseCase $excluirItens,
    ) {
    }

    public function executar(PastaSecao $secao, User $autor, Tenant $tenant): ResultadoExcluirItensDaPasta
    {
        if ($secao->getTenant() !== $tenant) {
            throw new AccessDeniedException('Seção não pertence ao tenant do usuário.');
        }

        $pasta = $secao->getPasta();
        if ($pasta === null) {
            throw new \InvalidArgumentException('A subpasta não está vinculada a nenhuma pasta.');
        }

        return $this->excluirItens->executar($pasta, [], [$secao], $autor, $tenant);
    }
}
