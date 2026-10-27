<?php

declare(strict_types=1);

namespace App\Djen\UseCase;

use App\Djen\Repository\PublicacaoDjenRepository;
use App\Entity\Tenant\Tenant;

/**
 * "Excluir" (lote ou uma) e "Limpar lidas" da caixa do Push. A publicação sai da caixa; o processo e
 * a pasta continuam como estão. A exclusão é lógica: a sincronização reimporta por djen_id e não
 * pode trazer de volta o que o usuário tirou.
 */
final class ExcluirPublicacoesUseCase
{
    public function __construct(private readonly PublicacaoDjenRepository $publicacoes)
    {
    }

    /**
     * @param list<int> $ids
     * @param list<string> $numerosOcultos
     *
     * @return int quantas publicações saíram da caixa
     */
    public function executar(Tenant $tenant, array $ids, array $numerosOcultos = []): int
    {
        return $this->publicacoes->excluirDoTenant($tenant, $ids, new \DateTimeImmutable(), $numerosOcultos);
    }

    /**
     * @param list<string> $numerosOcultos
     */
    public function limparLidas(Tenant $tenant, array $numerosOcultos = []): int
    {
        return $this->publicacoes->excluirLidasDoTenant($tenant, new \DateTimeImmutable(), $numerosOcultos);
    }
}
