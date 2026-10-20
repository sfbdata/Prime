<?php

declare(strict_types=1);

namespace App\Indicacao\UseCase;

use App\Indicacao\Repository\IndicacaoCodigoRepository;

/**
 * O link curto `/i/{codigo}` (spec `indicar-bluejus.md`, comportamento 7): devolve o código normalizado se ele existe,
 * ou nulo. Quem guarda na sessão é o controller; quem usa no cadastro novo é o {@see \App\Indicacao\EventListener\CadastroPeloLinkListener}.
 */
final class ConferirCodigoDeIndicacaoUseCase
{
    public function __construct(
        private readonly IndicacaoCodigoRepository $codigos,
    ) {
    }

    public function executar(string $codigo): ?string
    {
        $codigo = strtoupper(trim($codigo));
        if (preg_match('/^[A-Z0-9]{1,12}$/', $codigo) !== 1) {
            return null;
        }

        return $this->codigos->existe($codigo) ? $codigo : null;
    }
}
