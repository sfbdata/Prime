<?php

declare(strict_types=1);

namespace App\Master\Exception;

/**
 * Recusa ao salvar as configurações globais do Master (desenho 05 Perfis 1.2 VS 25.10.26, seção "Configurações
 * globais"): valor fora do intervalo, opção desconhecida ou texto inválido. O controller devolve 422 com a mensagem.
 */
final class ConfiguracaoRecusada extends \DomainException
{
}
