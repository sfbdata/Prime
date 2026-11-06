<?php

declare(strict_types=1);

namespace App\Dashboard\DTO;

use App\Entity\Auth\User;

/**
 * A equipe que o `ObterDadosDashboardUseCase` já leu, para o controller montar as opções dos filtros
 * Responsável e Cargo sem consultar de novo. Null num mapa = o UseCase não leu (o controller consulta).
 */
final class EquipeDoDashboardOutput
{
    /**
     * @param list<array{id: int|null, nome: string|null}> $colaboradores ativos, na ordem do repositório, antes de qualquer filtro
     * @param array<int, string|null>|null                 $cargo         userId => nome do cargo (UserRepository::findCargoPorColaboradores)
     * @param array<int, string|null>|null                 $foto          userId => fotoUrl (UserRepository::findFotoPorColaboradores)
     */
    public function __construct(
        public readonly array $colaboradores,
        public readonly ?array $cargo,
        public readonly ?array $foto = null,
    ) {
    }

    /**
     * @param list<User>              $colaboradores a lista canônica, antes dos filtros
     * @param array<int, string|null> $cargo         lido só quando há colaborador
     */
    public static function antesDosFiltros(array $colaboradores, array $cargo): self
    {
        return new self(
            array_map(static fn (User $u): array => ['id' => $u->getId(), 'nome' => $u->getFullName()], $colaboradores),
            $colaboradores === [] ? null : $cargo,
        );
    }

    /** @param array<int, string|null>|null $foto */
    public function comFoto(?array $foto): self
    {
        return new self($this->colaboradores, $this->cargo, $foto);
    }
}
