<?php

declare(strict_types=1);

namespace App\Cobranca\Exception;

/**
 * O cálculo de encargos passou do que cabe num inteiro de 64 bits e não pode ser concluído sem
 * perder precisão. Alcançável apenas no regime de juros COMPOSTO, onde o montante cresce
 * exponencialmente: taxa alta somada a atraso longo (ex.: 20% a.m. por ~13 anos) estoura
 * PHP_INT_MAX. No regime simples (default TOPLIFE, o único provado contra dados reais) a conta é
 * linear e fica ordens de grandeza abaixo do teto.
 *
 * É lançada em vez de degradar para float porque dinheiro não pode virar aproximação silenciosa:
 * `strict_types` derrubaria a operação adiante de qualquer forma, e um valor capado seria um
 * número ERRADO apresentado como certo. Quem processa em lote (o cron de atualização) deve
 * capturar POR OBRIGAÇÃO, registrar e seguir — um caso patológico não pode matar a rodada inteira.
 */
final class EncargosInexequiveisException extends \DomainException
{
    public function __construct(int $principalCentavos, int $dias, int $taxaMensalBp)
    {
        parent::__construct(sprintf(
            'Encargos inexequíveis: principal %d centavos, %d dias de atraso e %d bp ao mês no '
            . 'regime composto ultrapassam o limite de precisão inteira. Revise a taxa configurada.',
            $principalCentavos,
            $dias,
            $taxaMensalBp,
        ));
    }
}
