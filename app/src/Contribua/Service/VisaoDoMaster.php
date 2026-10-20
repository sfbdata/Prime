<?php

declare(strict_types=1);

namespace App\Contribua\Service;

use Doctrine\ORM\EntityManagerInterface;

/**
 * A ÚNICA porta do Contribua para o Master enxergar dados de todos os escritórios (as sugestões de
 * todo mundo, para marcar como atendidas). Suspende o filtro de tenant só durante o callback e o
 * restaura no `finally`, mesmo com exceção.
 *
 * `suspend()`/`restore()` e não `disable()`/`enable()`: o `enable()` do Doctrine cria uma instância
 * NOVA do filtro, sem o parâmetro do escritório, e o filtro ficaria inerte pelo resto da requisição.
 * `suspend()` guarda a mesma instância, com o parâmetro.
 *
 * Quem chama garante que é o Master (`ROLE_SUPER_ADMIN` no controller). Aqui não há checagem de
 * papel, de propósito: a regra de quem pode ver mora na camada de autorização.
 */
final class VisaoDoMaster
{
    private const FILTRO = 'tenant';

    public function __construct(
        private readonly EntityManagerInterface $em,
    ) {
    }

    /**
     * @template T
     *
     * @param callable(): T $trabalho
     *
     * @return T
     */
    public function deTodosOsEscritorios(callable $trabalho): mixed
    {
        $filtros = $this->em->getFilters();
        $estavaLigado = $filtros->isEnabled(self::FILTRO);
        if ($estavaLigado) {
            $filtros->suspend(self::FILTRO);
        }

        try {
            return $trabalho();
        } finally {
            if ($estavaLigado) {
                $filtros->restore(self::FILTRO);
            }
        }
    }
}
