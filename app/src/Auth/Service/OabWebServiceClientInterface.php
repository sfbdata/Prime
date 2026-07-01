<?php

declare(strict_types=1);

namespace App\Auth\Service;

use App\Auth\DTO\ConsultaOabResultado;

/**
 * Backend de consulta ao Cadastro Nacional de Advogados. Abstrai o "como" (SOAP oficial da
 * OAB, API paga de terceiro, ou dormente enquanto não há chave) — o ValidadorOab depende só
 * desta interface, então trocar de backend não toca no resto do sistema.
 */
interface OabWebServiceClientInterface
{
    /**
     * @throws OabIndisponivelException quando o serviço não pôde responder (fail-open).
     */
    public function consultar(string $inscricao, string $uf, string $nome): ConsultaOabResultado;
}
