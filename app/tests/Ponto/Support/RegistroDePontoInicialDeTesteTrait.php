<?php

declare(strict_types=1);

namespace App\Tests\Ponto\Support;

use App\Ponto\Repository\JornadaColaboradorRepository;
use App\Ponto\Repository\JornadaTenantRepository;
use App\Ponto\Service\ChoqueDeJornadaEntreEscritorios;
use App\Ponto\Service\IntervalosDeTrabalho;
use App\Ponto\Service\JornadaResolver;
use App\Ponto\Service\RegistroDePontoInicial;
use App\Repository\UserTenantRepository;

/**
 * O `RegistroDePontoInicial` dos testes de unidade dos criadores de vínculo: sem jornada e sem outro escritório, a chave
 * nasce ligada. O choque em si é provado em `ChoqueDeJornadaEntreEscritoriosTest` e nos functional.
 */
trait RegistroDePontoInicialDeTesteTrait
{
    private function registroDePontoInicialSemChoque(): RegistroDePontoInicial
    {
        $jornadas = $this->createStub(JornadaColaboradorRepository::class);
        $vinculos = $this->createStub(UserTenantRepository::class);
        $intervalos = new IntervalosDeTrabalho(new JornadaResolver(), $jornadas, $this->createStub(JornadaTenantRepository::class));

        return new RegistroDePontoInicial(
            $intervalos,
            new ChoqueDeJornadaEntreEscritorios($intervalos, $jornadas, $vinculos),
            $vinculos,
        );
    }
}
