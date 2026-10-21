<?php

declare(strict_types=1);

namespace App\Comercial\Twig;

use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * `rastreio_da_leitura_disponivel()`: se a chave "Confirmação de leitura" da barra de envio pode ser ligada (F3g; as duas
 * chaves globais do rastreio). Vem da configuração e não muda com a tela aberta, por isso sai do servidor, no parcial.
 * O serviço só é criado quando a função é chamada ({@see RastreioDaLeituraRuntime}).
 */
final class RastreioDaLeituraExtension extends AbstractExtension
{
    public function getFunctions(): array
    {
        return [new TwigFunction('rastreio_da_leitura_disponivel', [RastreioDaLeituraRuntime::class, 'disponivel'])];
    }
}
