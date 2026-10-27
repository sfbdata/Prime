<?php

declare(strict_types=1);

namespace App\Inteligencia\UseCase;

use App\Entity\Auth\User;
use App\Inteligencia\Armazenamento\ChavesDeInteligencia;
use App\Inteligencia\DTO\ArquivoDaVersaoJuridicaOutput;
use App\Inteligencia\Exception\VersaoDeDocumentoJuridicoNaoEncontradaException;
use App\Inteligencia\Repository\VersaoDeDocumentoJuridicoRepository;
use App\Inteligencia\Service\GuardaDoMasterDaIa;

/**
 * O Master baixa uma versão ajustada já enviada. A guarda do Master vem ANTES de montar a chave (INV-5); versão que não
 * existe é 404. O arquivo ausente no armazenamento vira 404 na entrega.
 */
final class ObterVersaoDeDocumentoJuridicoUseCase
{
    public function __construct(
        private readonly VersaoDeDocumentoJuridicoRepository $versoes,
    ) {
    }

    public function executar(int $id, User $master): ArquivoDaVersaoJuridicaOutput
    {
        GuardaDoMasterDaIa::exigir($master);
        $versao = $this->versoes->porId($id) ?? throw new VersaoDeDocumentoJuridicoNaoEncontradaException($id);

        return new ArquivoDaVersaoJuridicaOutput(ChavesDeInteligencia::versaoDeDocumentoJuridico($versao), $versao->getNomeOriginal());
    }
}
