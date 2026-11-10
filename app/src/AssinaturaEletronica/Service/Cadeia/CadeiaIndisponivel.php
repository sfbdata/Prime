<?php

declare(strict_types=1);

namespace App\AssinaturaEletronica\Service\Cadeia;

/**
 * O validador de cadeia não conferiu: não está configurado, não respondeu, recusou o token, passou do tempo ou está
 * ocupado. Quem chama recusa o envio com "tente de novo em alguns minutos" (E6-4) e nada é gravado como avançada ou
 * qualificada. A mensagem é para o log, nunca para a tela.
 */
final class CadeiaIndisponivel extends \RuntimeException
{
}
