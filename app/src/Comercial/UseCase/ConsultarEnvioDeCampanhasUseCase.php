<?php

declare(strict_types=1);

namespace App\Comercial\UseCase;

use App\Comercial\Campanha\ChaveDasCampanhas;
use App\Comercial\DTO\EstadoDoEnvioDeCampanhas;
use App\Comercial\Repository\CampanhaDeEmailRepository;
use Psr\Clock\ClockInterface;

/** O estado da chave do envio de campanhas para a Central de envios (só leitura, só o Master). */
final class ConsultarEnvioDeCampanhasUseCase
{
    public function __construct(
        private readonly ChaveDasCampanhas $chave,
        private readonly CampanhaDeEmailRepository $campanhas,
        private readonly ClockInterface $relogio,
    ) {
    }

    public function executar(): EstadoDoEnvioDeCampanhas
    {
        return new EstadoDoEnvioDeCampanhas(
            $this->chave->ligada(),
            $this->chave->origem(),
            $this->campanhas->contarEsperandoOEnvio($this->relogio->now()),
        );
    }
}
