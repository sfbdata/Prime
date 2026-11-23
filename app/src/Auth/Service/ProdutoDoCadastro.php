<?php

declare(strict_types=1);

namespace App\Auth\Service;

use App\Produto\Enum\Produto;
use Symfony\Component\HttpFoundation\Session\SessionInterface;

/**
 * O produto com que o escritório nasce no cadastro (spec docs/specs/multiproduto-contratacao-e-voto.md, §5 e D9).
 *
 * O site do Voto leva a `/cadastro?produto=voto`; o valor fica na sessão, por lista fechada, como o cupom da indicação.
 * Os três caminhos de criação (e-mail, Google e Facebook) leem o produto SÓ daqui, nunca do corpo do POST. Valor fora
 * da lista é ignorado e não apaga o que já estava guardado. Depois de criar a conta, a chave sai da sessão.
 */
final class ProdutoDoCadastro
{
    public const CHAVE_SESSAO = 'cadastro_produto';

    /** Os únicos produtos com que o cadastro público pode nascer. */
    private const PERMITIDOS = ['voto'];

    /** Guarda o valor da URL se estiver na lista; qualquer outro (inclusive lista ou vazio) não muda nada. */
    public static function guardar(SessionInterface $sessao, mixed $valor): void
    {
        $produto = self::daLista($valor);
        if (null !== $produto) {
            $sessao->set(self::CHAVE_SESSAO, $produto->value);
        }
    }

    /** O produto guardado, conferido de novo contra a lista; nulo sem produto (o escritório nasce completo). */
    public static function ler(SessionInterface $sessao): ?Produto
    {
        return self::daLista($sessao->get(self::CHAVE_SESSAO));
    }

    public static function esquecer(SessionInterface $sessao): void
    {
        $sessao->remove(self::CHAVE_SESSAO);
    }

    private static function daLista(mixed $valor): ?Produto
    {
        return is_string($valor) && in_array($valor, self::PERMITIDOS, true) ? Produto::from($valor) : null;
    }
}
