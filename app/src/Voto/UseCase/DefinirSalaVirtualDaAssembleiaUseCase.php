<?php

declare(strict_types=1);

namespace App\Voto\UseCase;

use App\Entity\Tenant\Tenant;
use App\Voto\Exception\AssembleiaNaoEncontradaException;
use App\Voto\Repository\AssembleiaRepository;

/**
 * Troca o link da sala virtual do debate (Meet, Zoom, Teams...) da assembleia já publicada: o link costuma ser criado
 * depois. Só http ou https, até 500 caracteres; vazio apaga. A validação é da entidade; outro escritório vira "não encontrada".
 */
final class DefinirSalaVirtualDaAssembleiaUseCase
{
    public function __construct(private readonly AssembleiaRepository $assembleias)
    {
    }

    public function executar(Tenant $tenant, int $assembleiaId, string $link): void
    {
        $assembleia = $this->assembleias->buscarDoEscritorio($assembleiaId, $tenant)
            ?? throw new AssembleiaNaoEncontradaException();
        $assembleia->definirLinkDaSalaVirtual($link);
        $this->assembleias->salvar($assembleia, flush: true);
    }
}
