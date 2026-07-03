<?php

declare(strict_types=1);

namespace App\Processo\Exception;

/**
 * Lançada quando não é possível derivar o tribunal a partir do número do processo:
 * o número não está no padrão CNJ de 20 dígitos, ou o par segmento (J) + tribunal (TR)
 * extraído não existe no mapa oficial. É falha de domínio (valor fora do domínio válido
 * de tribunais), tratada pelo controller com mensagem amigável ao usuário — distinta de
 * uma falha de rede/API.
 */
final class TribunalNaoIdentificadoException extends \DomainException
{
}
