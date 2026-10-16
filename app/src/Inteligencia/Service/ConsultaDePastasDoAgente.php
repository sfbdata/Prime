<?php

declare(strict_types=1);

namespace App\Inteligencia\Service;

use App\Entity\Tenant\Tenant;
use App\Expediente\Entity\Marcador;
use App\Expediente\Repository\MarcadorRepository;
use App\Inteligencia\DTO\FiltroDoAgenteInput;
use App\Inteligencia\Exception\FiltroDoAgenteInvalidoException;
use App\Pasta\Entity\Pasta;
use App\Pasta\Repository\PastaRepository;
use App\Repository\UserTenantRepository;

/**
 * A consulta de pastas do Agente de Pastas: **as consultas da Lista do Expediente**
 * (`findByFilters`/`findByFiltrosEMarcador` e as contagens irmãs), sem cópia de filtro que pudesse
 * divergir dela com o tempo. O agente enxerga o que a Lista enxerga, com o escritório explícito.
 *
 * A única consulta própria é a lista de NUPs, que a Lista não tem. `pasta.nup` não é único no
 * escritório: a lista pode devolver mais pastas do que NUPs pedidos, cada uma com o seu id.
 */
final class ConsultaDePastasDoAgente
{
    public function __construct(
        private readonly PastaRepository $pastaRepository,
        private readonly MarcadorRepository $marcadorRepository,
        private readonly UserTenantRepository $userTenantRepository,
    ) {
    }

    /**
     * @return list<Pasta>
     *
     * @throws FiltroDoAgenteInvalidoException
     */
    public function buscar(FiltroDoAgenteInput $filtro, Tenant $tenant, int $porPagina): array
    {
        if ($filtro->nups !== []) {
            return array_slice($this->pastasPorNup($filtro, $tenant), ($filtro->pagina - 1) * $porPagina, $porPagina);
        }

        $this->conferirResponsavel($filtro, $tenant);
        $marcador = $this->marcador($filtro, $tenant);
        $filtros = $filtro->paraRepositorio();
        $pastas = $marcador !== null
            ? $this->pastaRepository->findByFiltrosEMarcador($filtros, $marcador, $tenant, $filtro->pagina, $porPagina, 'nup', 'asc')
            : $this->pastaRepository->findByFilters($filtros, $tenant, $filtro->pagina, $porPagina, 'nup', 'asc');

        return array_values($pastas);
    }

    /** @throws FiltroDoAgenteInvalidoException */
    public function contar(FiltroDoAgenteInput $filtro, Tenant $tenant): int
    {
        if ($filtro->nups !== []) {
            return $this->pastaRepository->count(['tenant' => $tenant, 'nup' => $filtro->nups]);
        }

        $this->conferirResponsavel($filtro, $tenant);
        $marcador = $this->marcador($filtro, $tenant);
        $filtros = $filtro->paraRepositorio();

        return $marcador !== null
            ? $this->pastaRepository->countByFiltrosEMarcador($filtros, $marcador, $tenant)
            : $this->pastaRepository->countByFilters($filtros, $tenant);
    }

    /**
     * Marcador de outro escritório (ou inexistente) é recusado em vez de virar "nenhuma pasta": a
     * lista vazia levaria o agente a dizer "nenhuma pasta tem esse marcador", o que é falso.
     *
     * @throws FiltroDoAgenteInvalidoException
     */
    private function marcador(FiltroDoAgenteInput $filtro, Tenant $tenant): ?Marcador
    {
        if ($filtro->marcadorId === null) {
            return null;
        }

        return $this->marcadorRepository->findPorTenant($filtro->marcadorId, $tenant)
            ?? throw new FiltroDoAgenteInvalidoException('marcador', 'Marcador não encontrado neste escritório.');
    }

    /**
     * Responsável sem vínculo com o escritório (id inexistente ou de outro escritório) é recusado pelo
     * mesmo motivo do marcador: "nenhuma pasta da Mariana" seria falso. Vínculo inativo vale — quem saiu
     * continua responsável pelas pastas que ninguém reatribuiu.
     *
     * @throws FiltroDoAgenteInvalidoException
     */
    private function conferirResponsavel(FiltroDoAgenteInput $filtro, Tenant $tenant): void
    {
        if ($filtro->responsavelId === null) {
            return;
        }
        if ($this->userTenantRepository->findOneBy(['user' => $filtro->responsavelId, 'tenant' => $tenant]) === null) {
            throw new FiltroDoAgenteInvalidoException('responsavel', 'Responsável não encontrado neste escritório.');
        }
    }

    /**
     * Na ordem natural do NUP ("9" antes de "10"). Coincide com a ordem da Lista (prefixo numérico) para NUP
     * que começa por número — todos em produção em 14/10; NUP sem prefixo numérico a Lista ordena como texto.
     *
     * @return list<Pasta>
     */
    private function pastasPorNup(FiltroDoAgenteInput $filtro, Tenant $tenant): array
    {
        /** @var list<Pasta> $pastas */
        $pastas = $this->pastaRepository->findBy(['tenant' => $tenant, 'nup' => $filtro->nups], ['id' => 'ASC']);
        usort($pastas, static fn (Pasta $a, Pasta $b): int => strnatcasecmp((string) $a->getNup(), (string) $b->getNup()) ?: $a->getId() <=> $b->getId());

        return $pastas;
    }
}
