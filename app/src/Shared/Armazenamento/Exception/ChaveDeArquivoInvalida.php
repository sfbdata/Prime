<?php

declare(strict_types=1);

namespace App\Shared\Armazenamento\Exception;

/**
 * A chave (ou o escopo) não pode existir — é defeito de programação, não estado do mundo.
 *
 * Nasce no construtor dos VOs. Note o que ela NÃO significa: "o nome estava sujo e eu limpei".
 * A fronteira de storage **recusa**, nunca corrige (D8) — corrigir tornaria inalcançáveis as
 * chaves legadas que dependem do byte exato.
 */
final class ChaveDeArquivoInvalida extends \InvalidArgumentException
{
}
