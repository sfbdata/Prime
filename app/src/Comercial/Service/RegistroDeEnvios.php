<?php

declare(strict_types=1);

namespace App\Comercial\Service;

use App\Comercial\Envio\RegistroDeEnviosInterface;
use App\Comercial\Envio\SituacaoDoEnvio;
use App\Comercial\Repository\EnvioDeEmailRepository;

/** A situação dos envios por id, para o histórico do consumidor (o Push da 0d). Ids desconhecidos ficam de fora. */
final class RegistroDeEnvios implements RegistroDeEnviosInterface
{
    public function __construct(
        private readonly EnvioDeEmailRepository $envios,
    ) {
    }

    public function situacoes(array $envioIds): array
    {
        $resultado = [];
        foreach ($this->envios->situacoes($envioIds) as $linha) {
            $resultado[$linha['id']] = new SituacaoDoEnvio(
                $linha['id'],
                $linha['situacao'],
                $linha['enviado_em'] === null ? null : new \DateTimeImmutable($linha['enviado_em']),
                $linha['aberturas'] > 0,
                $linha['cliques'] > 0,
            );
        }

        return $resultado;
    }
}
