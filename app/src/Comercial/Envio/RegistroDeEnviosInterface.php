<?php

declare(strict_types=1);

namespace App\Comercial\Envio;

/**
 * Leitura da situação dos envios por id, para o histórico de um consumidor (o Push da 0d). Só os ids que ele recebeu
 * do Carteiro; ids desconhecidos ficam de fora.
 */
interface RegistroDeEnviosInterface
{
    /**
     * @param list<int> $envioIds
     *
     * @return array<int, SituacaoDoEnvio> envioId => situação
     */
    public function situacoes(array $envioIds): array;
}
