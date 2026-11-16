<?php

declare(strict_types=1);

namespace App\Conector\Exception;

/**
 * A concessão (a cadeia de uma autorização) não sustenta mais token nenhum, por um motivo que mora nela mesma e não no
 * portão (`docs/specs/mcp-remoto-oauth.md`, seção 4, regra 3, e 9.2): revogada, vencida ou com o consentimento revogado;
 * app de I.A. desativado; autorizada antes do corte de "Sair de todos os aparelhos"; ou a senha trocada depois da
 * autorização. Quem lança é {@see \App\Conector\Service\ConferenciaDaConcessao}.
 *
 * {@see $motivo} é de lista fechada e vai ao log; a mensagem é uma só, fixa, para o app de I.A.: ele não precisa saber
 * qual item caiu, só que tem de pedir o login de novo.
 */
final class ConcessaoRecusada extends \RuntimeException
{
    public const MOTIVO_CONCESSAO_INVALIDA = 'concessao_invalida';
    public const MOTIVO_CLIENTE_INATIVO = 'cliente_inativo';
    public const MOTIVO_SESSOES_ENCERRADAS = 'sessoes_encerradas';
    public const MOTIVO_SENHA_TROCADA = 'senha_trocada';

    public const MENSAGEM = 'A autorização do aplicativo de I.A. não vale mais. Conecte de novo.';

    private function __construct(public readonly string $motivo)
    {
        parent::__construct(self::MENSAGEM);
    }

    /** Revogada, vencida (7 dias) ou com o consentimento revogado. */
    public static function concessaoInvalida(): self
    {
        return new self(self::MOTIVO_CONCESSAO_INVALIDA);
    }

    /** O app de I.A. da concessão foi desativado no registro de clientes. */
    public static function clienteInativo(): self
    {
        return new self(self::MOTIVO_CLIENTE_INATIVO);
    }

    /** Autorizada até o corte de "Sair de todos os aparelhos", inclusive no mesmo segundo. */
    public static function sessoesEncerradas(): self
    {
        return new self(self::MOTIVO_SESSOES_ENCERRADAS);
    }

    /** A impressão da senha de agora não é a da autorização: a senha foi trocada (ou criada) depois. */
    public static function senhaTrocada(): self
    {
        return new self(self::MOTIVO_SENHA_TROCADA);
    }
}
