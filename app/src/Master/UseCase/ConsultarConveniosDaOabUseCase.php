<?php

declare(strict_types=1);

namespace App\Master\UseCase;

use App\Master\DTO\ConveniosDaOabOutput;
use App\Master\Repository\ConvenioDaUfRepository;
use App\Master\Repository\PainelMasterRepository;
use App\Master\Service\UfDoEscritorio;

/**
 * Convênio OAB por estado, para o painel recolhível da seção Contas do /master (desenho 05 Perfis 1.2 VS 25.10.26).
 *
 * Quem dispara: o Master (ROLE_SUPER_ADMIN no controller). Fluxo principal: lê os convênios não gratuitos e conta as
 * contas (fora da quarentena) por UF do escritório ({@see UfDoEscritorio}: o estado ou, sem ele, a UF da OAB do
 * criador). Conta sem nenhum dos dois não entra em nenhum estado.
 */
final class ConsultarConveniosDaOabUseCase
{
    public function __construct(
        private readonly ConvenioDaUfRepository $convenios,
        private readonly PainelMasterRepository $painel,
    ) {
    }

    public function executar(): ConveniosDaOabOutput
    {
        $contas = [];
        foreach ($this->painel->escritorios() as $e) {
            $uf = UfDoEscritorio::daLinha($e);
            if ($uf !== null) {
                $contas[$uf] = ($contas[$uf] ?? 0) + 1;
            }
        }

        return new ConveniosDaOabOutput($this->convenios->todosPorUf(), $contas);
    }
}
