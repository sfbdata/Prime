<?php

declare(strict_types=1);

namespace App\Voto\UseCase;

use App\Entity\Tenant\Tenant;
use App\Voto\DTO\DivulgacaoDaAssembleiaOutput;
use App\Voto\Enum\EstadoDaAssembleia;
use App\Voto\Exception\AssembleiaNaoEncontradaException;
use App\Voto\Service\EnderecoPublicoDaAssembleia;
use App\Voto\Repository\AssembleiaRepository;

/** Gestor autorizado solicita material do edital. Outro escritório e rascunho são tratados como inexistentes. */
final class MontarDivulgacaoDaAssembleiaUseCase
{
    public function __construct(private readonly AssembleiaRepository $assembleias, private readonly EnderecoPublicoDaAssembleia $endereco)
    {
    }

    public function executar(Tenant $tenant, int $id): DivulgacaoDaAssembleiaOutput
    {
        $a = $this->assembleias->buscarDoEscritorio($id, $tenant) ?? throw new AssembleiaNaoEncontradaException();
        if ($a->getEstado() !== EstadoDaAssembleia::Publicada) {
            throw new AssembleiaNaoEncontradaException();
        }

        return new DivulgacaoDaAssembleiaOutput($id, $a->getTitulo(), $a->getCodigoPublico(), $this->endereco->gerar($a->getCodigoPublico()));
    }
}
