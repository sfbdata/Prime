<?php

declare(strict_types=1);

namespace App\Pasta\UseCase;

use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Pasta\Entity\Pasta;
use App\Pasta\Repository\PastaRepository;

/**
 * Minhas Demandas (desenho 15): as pastas do escritório em que o usuário LOGADO é o responsável, com a mesma
 * máquina de filtros, ordem e paginação da Lista do Expediente.
 *
 * Quem: a própria pessoa, para ela mesma. O responsável é fixado aqui, no servidor, e nunca vem do request
 * (evita IDOR entre colegas). O filtro Responsável da tela só estreita dentro das minhas (dc `filtrados`:
 * `respNome(d.resp) === eu() && (!fResp || respNome(d.resp) === fResp)`): vazio ou eu mesmo listam as minhas;
 * qualquer outro valor não casa com nenhuma, porque a pasta tem um responsável só.
 *
 * Cadeado (spec pasta-cadeado D1): quem busca é o próprio usuário, então a pasta oculta para ele pelo cadeado
 * não aparece, mesmo sendo dele (o responsável é filtro de atributo e esconde a oculta).
 */
final class ListarMinhasDemandasUseCase
{
    public function __construct(
        private readonly PastaRepository $repository,
    ) {}

    /**
     * @param array<string, string> $filtros   busca, status, responsavel, prioridade, data_de, data_ate
     * @param int|null              $porPagina `null` = todas de uma vez (exportação CSV)
     * @return array{pastas: Pasta[], total: int, pagina: int}
     */
    public function executar(
        User $usuario,
        Tenant $tenant,
        array $filtros,
        int $pagina,
        ?int $porPagina,
        string $ordenar,
        string $direcao,
    ): array {
        if (!self::responsavelPedidoSouEu($usuario, $filtros)) {
            return ['pastas' => [], 'total' => 0, 'pagina' => 1];
        }

        $filtros['responsavel'] = (string) $usuario->getId();
        $total   = $this->repository->countByFilters($filtros, $tenant, $usuario);
        $tamanho = $porPagina ?? max(1, $total);
        $pagina  = min(max(1, $pagina), max(1, (int) ceil($total / $tamanho)));

        return [
            'pastas' => $this->repository->findByFilters($filtros, $tenant, $pagina, $tamanho, $ordenar, $direcao, $usuario, $usuario),
            'total'  => $total,
            'pagina' => $pagina,
        ];
    }

    /** @param array<string, string> $filtros */
    private static function responsavelPedidoSouEu(User $usuario, array $filtros): bool
    {
        $pedido = trim((string) ($filtros['responsavel'] ?? ''));

        return $pedido === '' || $pedido === (string) $usuario->getId();
    }
}
