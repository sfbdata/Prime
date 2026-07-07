<?php

declare(strict_types=1);

namespace App\Djen\Service;

use App\Djen\DTO\ConsultaComunicacoesQuery;
use App\Djen\DTO\RespostaComunicacoes;
use App\Djen\Exception\ConsultaDjenException;

/**
 * Contrato do cliente do DJEN. Depender da interface (não da implementação concreta) mantém a
 * integração desacoplada — permite trocar a implementação numa futura mudança da API do CNJ e
 * substituí-la por um mock nos testes.
 */
interface DjenClientInterface
{
    /**
     * @throws ConsultaDjenException se a consulta falhar (config, rede, timeout, limite, sobrecarga
     *         ou resposta malformada)
     */
    public function consultarComunicacoes(ConsultaComunicacoesQuery $query): RespostaComunicacoes;
}
