<?php

declare(strict_types=1);

namespace App\Tests\Auth\Doubles;

use App\Auth\DTO\ConsultaOabResultado;
use App\Auth\Service\OabIndisponivelException;
use App\Auth\Service\OabWebServiceClientInterface;

/**
 * Test double configurável do backend de OAB — permite exercitar TODA a lógica do ValidadorOab
 * (confirmada/divergente/matcher) sem rede, mesmo o backend de produção sendo o dormente.
 */
final class OabWebServiceClientFake implements OabWebServiceClientInterface
{
    private ?ConsultaOabResultado $resultado = null;
    private bool $indisponivel = false;

    public function comResultado(ConsultaOabResultado $resultado): self
    {
        $this->resultado   = $resultado;
        $this->indisponivel = false;

        return $this;
    }

    public function indisponivel(): self
    {
        $this->indisponivel = true;

        return $this;
    }

    public function consultar(string $inscricao, string $uf, string $nome): ConsultaOabResultado
    {
        if ($this->indisponivel === true) {
            throw new OabIndisponivelException('fake: indisponível');
        }

        return $this->resultado ?? throw new \LogicException('OabWebServiceClientFake não configurado (use comResultado/indisponivel).');
    }
}
