<?php

declare(strict_types=1);

namespace App\Conector\Exception;

/**
 * Recusa de uma ferramenta do conector (`docs/specs/mcp-remoto-oauth.md`, 6.2): vira `isError` na resposta MCP, com a
 * mensagem fixa em português, que pode ir ao app de I.A. O {@see $motivo} é de lista curta e vai ao log `mcp`; a
 * mensagem nunca leva texto, nome ou valor vindo do banco ou do pedido.
 *
 * Alheio, inexistente ou oculto dá a mesma resposta "não encontrado" (6.2, regra 1). As outras recusas também têm
 * mensagem fixa: segredo de justiça (6.2, regra 3), função desligada pelo Master e permissão que falta (6.2, regras 2 e
 * 11) e filtro inválido, que diz só o nome do campo, nunca o valor digitado (6.2, regra 10).
 */
final class ErroDaFerramenta extends \RuntimeException
{
    public const MOTIVO_NAO_ENCONTRADO = 'nao_encontrado';
    public const MOTIVO_ENTRADA_INVALIDA = 'entrada_invalida';
    public const MOTIVO_EM_SEGREDO = 'em_segredo';
    public const MOTIVO_FUNCAO_DESLIGADA = 'funcao_desligada';
    public const MOTIVO_SEM_PERMISSAO = 'sem_permissao';
    public const MOTIVO_FILTRO_INVALIDO = 'filtro_invalido';
    public const MOTIVO_RECUSADA = 'recusada';
    public const MOTIVO_ENVIO_AUTOMATICO = 'envio_automatico';

    public const NAO_ENCONTRADO = 'Não encontrado.';
    public const ENTRADA_INVALIDA = 'Pedido inválido para esta ferramenta.';
    /** A frase da 6.2, regra 3: pasta ou processo em segredo de justiça não existe para o conector. */
    public const EM_SEGREDO = 'Em segredo de justiça: não sai pelo conector.';
    /** A frase da 6.2, regra 11: a chave do Master está desligada para todos. */
    public const FUNCAO_DESLIGADA = 'Função desligada pelo administrador do sistema.';
    public const SEM_PERMISSAO = 'Sem permissão para esta ação no BlueJus. Fale com o administrador do escritório.';
    /** Com o nome do campo do esquema; o valor digitado nunca entra. */
    public const FILTRO_INVALIDO = 'Filtro inválido no campo %s. Confira o valor e tente de novo.';
    /** Quando o nome do campo não é um nome de campo do esquema (só letras minúsculas, números e sublinhado). */
    public const FILTRO_INVALIDO_SEM_CAMPO = 'Filtro inválido. Confira os valores e tente de novo.';
    /** A frase da abertura da 6.6: nenhum caminho do conector estende o envio automático do Push ao cliente. */
    public const ENVIO_AUTOMATICO = 'Este cliente recebe o envio automático do Push: faça isso pela tela do BlueJus.';

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

    /** Pasta ou processo em segredo de justiça, pedido por id, para ler ou gravar (6.2, regra 3). */
    public static function emSegredo(): self
    {
        return new self(self::MOTIVO_EM_SEGREDO, self::EM_SEGREDO);
    }

    /** A chave do Master da tela de onde a informação vem está desligada (6.2, regra 11; vale também para o Master). */
    public static function funcaoDesligada(): self
    {
        return new self(self::MOTIVO_FUNCAO_DESLIGADA, self::FUNCAO_DESLIGADA);
    }

    /** Falta à pessoa o módulo que a ação exige (6.2, regra 2, e 6.6). */
    public static function semPermissao(): self
    {
        return new self(self::MOTIVO_SEM_PERMISSAO, self::SEM_PERMISSAO);
    }

    /**
     * Filtro com valor que a ação não aceita (6.2, regra 10): a mensagem diz só o nome do campo do esquema. Nome que
     * não tem a forma de um campo (só `a-z`, `0-9` e `_`, até 40 caracteres) não entra, para nenhum texto digitado
     * chegar à mensagem.
     */
    public static function filtroInvalido(string $campo): self
    {
        $mensagem = preg_match('/^[a-z][a-z0-9_]{0,39}$/', $campo) === 1
            ? sprintf(self::FILTRO_INVALIDO, $campo)
            : self::FILTRO_INVALIDO_SEM_CAMPO;

        return new self(self::MOTIVO_FILTRO_INVALIDO, $mensagem);
    }

    /**
     * Regra de negócio que recusa a gravação (6.6): campo obrigatório vazio, valor fora da lista, ação que a tela também
     * recusaria. A mensagem é SEMPRE uma constante do código (da ferramenta ou a mensagem fixa da exceção do UseCase,
     * escolhida pela classe da exceção), nunca o `getMessage()` de uma exceção qualquer nem texto vindo do pedido ou
     * do banco: a mensagem vai ao app de I.A.
     */
    public static function recusada(string $mensagemFixa): self
    {
        return new self(self::MOTIVO_RECUSADA, $mensagemFixa);
    }

    /** A gravação estenderia o envio automático do Push a um cliente que o tem ligado (abertura da 6.6, 14.h). */
    public static function envioAutomatico(): self
    {
        return new self(self::MOTIVO_ENVIO_AUTOMATICO, self::ENVIO_AUTOMATICO);
    }
}
