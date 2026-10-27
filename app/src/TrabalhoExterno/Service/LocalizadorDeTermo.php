<?php

declare(strict_types=1);

namespace App\TrabalhoExterno\Service;

use App\TrabalhoExterno\Entity\TermoDeVoluntariado;
use App\TrabalhoExterno\Exception\DemandaNaoEncontrada;
use App\TrabalhoExterno\Repository\TermoDeVoluntariadoRepository;

/**
 * A porta única de "termo por código": só quem pode ver o termo completo o alcança ({@see PoliticaDoTermo}).
 * Termo de outro voluntário, de outro escritório ou inexistente é a mesma resposta (404), para não confirmar
 * que ele existe.
 */
final class LocalizadorDeTermo
{
    public function __construct(
        private readonly TermoDeVoluntariadoRepository $repositorio,
        private readonly PoliticaDoTermo $politica,
        private readonly PoliticaDoCurriculo $curriculos,
    ) {
    }

    public function localizar(Contexto $ctx, string $codigo): TermoDeVoluntariado
    {
        $termo = $this->curriculos->veAba($ctx) ? $this->repositorio->porCodigo($codigo) : null;
        if ($termo === null || !$this->politica->podeVerCompleto($ctx, $termo)) {
            throw new DemandaNaoEncontrada('Termo não encontrado.');
        }

        return $termo;
    }
}
