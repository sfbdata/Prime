<?php

declare(strict_types=1);

namespace App\Conector\Exception;

/**
 * Recusa de uma ferramenta do conector (`docs/specs/mcp-remoto-oauth.md`, 6.2): vira `isError` na resposta MCP, com a
 * mensagem fixa em português, que pode ir ao app de I.A. O {@see $motivo} é de lista curta e vai ao log `mcp`; a
 * mensagem nunca leva texto, nome ou valor vindo do banco ou do pedido.
 *
 * Alheio, inexistente ou oculto dá a mesma resposta "não encontrado" (6.2, regra 1).
 */
final class ErroDaFerramenta extends \RuntimeException
{
    public const MOTIVO_NAO_ENCONTRADO = 'nao_encontrado';
    public const MOTIVO_ENTRADA_INVALIDA = 'entrada_invalida';

    public const NAO_ENCONTRADO = 'Não encontrado.';
    public const ENTRADA_INVALIDA = 'Pedido inválido para esta ferramenta.';

    public function __construct(public readonly string $motivo, string $mensagem)
    {
        parent::__construct($mensagem);
    }

    public static function naoEncontrado(): self
    {
        return new self(self::MOTIVO_NAO_ENCONTRADO, self::NAO_ENCONTRADO);
    }

    /** Campo fora do esquema, ação fora da lista ou valor que a ação não aceita (6.2, regra 10). */
    public static function entradaInvalida(): self
    {
        return new self(self::MOTIVO_ENTRADA_INVALIDA, self::ENTRADA_INVALIDA);
    }
}
