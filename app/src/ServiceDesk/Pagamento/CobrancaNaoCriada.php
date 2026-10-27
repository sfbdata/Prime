<?php

declare(strict_types=1);

namespace App\ServiceDesk\Pagamento;

/**
 * O provedor garante que NENHUMA cobrança foi criada na operadora (recusa antes de enviar, validação,
 * credencial ausente). Só com esta exceção o pendente é desfeito. Qualquer outra falha de `iniciar()`
 * (timeout, resposta ilegível) é ambígua: a cobrança pode existir e ser paga, então o pendente fica
 * para o webhook achar.
 */
final class CobrancaNaoCriada extends \RuntimeException
{
}
