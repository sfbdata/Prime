<?php

declare(strict_types=1);

namespace App\Produto\Twig;

use App\Produto\Enum\Produto;
use App\Produto\Service\ProdutosNaTela;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * Funções Twig do multiproduto (spec docs/specs/multiproduto-contratacao-e-voto.md, §7):
 *
 *  - `produto_inclui('expediente')` ou `produto_inclui(['expediente', 'cobrancas'])` (basta um): se o item de menu
 *    aparece. Soma-se a `can_access_module()`, que continua deixando o primário passar. Chave desligada: sempre true.
 *    Produto fora do enum é erro de template e quebra a página (ValueError), em vez de esconder o item em silêncio.
 */
final class ProdutoExtension extends AbstractExtension
{
    public function __construct(
        private readonly ProdutosNaTela $produtosNaTela,
    ) {
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('produto_inclui', $this->inclui(...)),
        ];
    }

    /** @param string|list<string> $produtos */
    public function inclui(string|array $produtos): bool
    {
        $lista = array_map(static fn (string $p): Produto => Produto::from($p), is_array($produtos) ? $produtos : [$produtos]);

        return $this->produtosNaTela->inclui($lista);
    }
}
