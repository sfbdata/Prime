<?php

declare(strict_types=1);

namespace App\Cobranca\Twig;

use App\Cobranca\Service\ResolvedorIdentificadorDaPasta;
use App\Pasta\Entity\Pasta;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * `identificador_pasta(pasta)` — o nome pelo qual a pasta é conhecida na tela.
 *
 * Existe como função Twig, e não como parâmetro passado pelos controllers, porque a mesma coluna é
 * desenhada em quatro lugares (tabela do Expediente, cartão do modo lista, resultado de Demandas e
 * cabeçalho da pasta) e cada tela que esquecesse de receber o mapa ficaria com o identificador errado
 * **em silêncio** — o tipo de falha que já mordeu esta frente três vezes num dia.
 *
 * Quem resolve é o {@see ResolvedorIdentificadorDaPasta}; ver lá a regra e a memória por requisição.
 */
final class IdentificadorDaPastaExtension extends AbstractExtension
{
    public function __construct(
        private readonly ResolvedorIdentificadorDaPasta $resolvedor,
    ) {
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('identificador_pasta', $this->identificador(...)),
        ];
    }

    public function identificador(Pasta $pasta): ?string
    {
        return $this->resolvedor->para($pasta);
    }
}
