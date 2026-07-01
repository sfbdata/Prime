<?php

declare(strict_types=1);

namespace App\Auth\Service;

use App\Auth\DTO\ConsultaOabResultado;

/**
 * Backend dormente (manual-first): não há verificação automática de OAB enquanto não tivermos
 * a chave do web service oficial (ou uma API de terceiro). Sempre sinaliza indisponibilidade,
 * então o ValidadorOab devolve `nao_verificada` e a confirmação fica a cargo do admin.
 *
 * Quando houver backend real, basta implementar OabWebServiceClientInterface e trocar o alias.
 */
final class ClienteOabIndisponivel implements OabWebServiceClientInterface
{
    public function consultar(string $inscricao, string $uf, string $nome): ConsultaOabResultado
    {
        throw new OabIndisponivelException('Verificação automática de OAB indisponível (sem backend configurado).');
    }
}
