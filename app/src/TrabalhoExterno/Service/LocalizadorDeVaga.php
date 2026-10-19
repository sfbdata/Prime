<?php

declare(strict_types=1);

namespace App\TrabalhoExterno\Service;

use App\TrabalhoExterno\Entity\Vaga;
use App\TrabalhoExterno\Exception\DemandaNaoEncontrada;
use App\TrabalhoExterno\Repository\VagaRepository;

/**
 * A porta única de "vaga por código", como o `LocalizadorDeDemanda`: consulta escopada pelo perfil
 * e, por cima, a política. Qualquer falha é a mesma resposta de "não encontrada" (404), para não
 * confirmar que a vaga de outro escritório existe.
 */
final class LocalizadorDeVaga
{
    public function __construct(
        private readonly VagaRepository $repositorio,
        private readonly PoliticaDaVaga $politica,
    ) {
    }

    public function localizar(Contexto $ctx, string $codigo, \DateTimeImmutable $agora): Vaga
    {
        $id = Vaga::idDoCodigo($codigo);
        $vaga = match (true) {
            $id === null => null,
            $this->politica->ehDaCentral($ctx) => $this->repositorio->daCentralPorId($id),
            $ctx->tenant !== null => $this->repositorio->visivelAoEscritorioPorId($id, $ctx->tenant),
            default => null,
        };
        if ($vaga === null || !$this->politica->podeVer($ctx, $vaga, $agora)) {
            throw new DemandaNaoEncontrada('Vaga não encontrada.');
        }

        return $vaga;
    }

    /** @return list<Vaga> */
    public function visiveis(Contexto $ctx, \DateTimeImmutable $agora): array
    {
        if (!$this->politica->veAba($ctx)) {
            return [];
        }
        $vagas = match (true) {
            $this->politica->ehDaCentral($ctx) => $this->repositorio->daCentral(),
            $ctx->tenant !== null => $this->repositorio->visiveisAoEscritorio($ctx->tenant),
            default => [],
        };

        return array_values(array_filter($vagas, fn (Vaga $v): bool => $this->politica->podeVer($ctx, $v, $agora)));
    }
}
