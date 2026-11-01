<?php

declare(strict_types=1);

namespace App\Auth\DTO;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * O que o "Falar com suporte" da tela de entrada (desenho 10) manda. Chega já aparado e cortado no tamanho das
 * colunas (`doPayload`); a validação tem os textos exatos do desenho.
 */
final class EnviarMensagemSuporteInput
{
    /** Os assuntos do desenho; qualquer outro valor vira nulo. */
    public const ASSUNTOS = ['Não consigo entrar', 'Esqueci a senha', 'Criar conta', 'Outro assunto'];

    public function __construct(
        public readonly string $nome,
        #[Assert\NotBlank(message: 'Informe um e-mail válido para a resposta.')]
        // Modo estrito (RFC, via egulias): o `\S+@\S+\.\S+` aceitava "a@b.com?cc=c@d.com" e "a@b.com,c@d.com".
        #[Assert\Email(mode: Assert\Email::VALIDATION_MODE_STRICT, message: 'Informe um e-mail válido para a resposta.')]
        public readonly string $email,
        public readonly ?string $assunto,
        #[Assert\NotBlank(message: 'Escreva a sua mensagem.')]
        #[Assert\Length(min: 5, minMessage: 'Escreva a sua mensagem.')]
        public readonly string $mensagem,
        public readonly string $ip,
        public readonly ?string $userAgent = null,
        /** 'login' (tela de entrada) ou 'site' (Contato do site institucional, {@see ContatoDoSiteInput}). */
        public readonly string $origem = 'login',
    ) {
    }

    /** @param array<mixed> $dados o corpo JSON da requisição */
    public static function doPayload(array $dados, string $ip, ?string $userAgent): self
    {
        $assunto = self::texto($dados, 'assunto');

        return new self(
            nome: mb_substr(self::texto($dados, 'nome'), 0, 120),
            email: mb_substr(self::texto($dados, 'email'), 0, 180),
            assunto: in_array($assunto, self::ASSUNTOS, true) ? $assunto : null,
            mensagem: mb_substr(self::texto($dados, 'mensagem'), 0, 5000),
            ip: mb_substr($ip, 0, 45),
            userAgent: $userAgent,
        );
    }

    /** @param array<mixed> $dados */
    private static function texto(array $dados, string $campo): string
    {
        $valor = $dados[$campo] ?? '';

        return is_scalar($valor) ? trim((string) $valor) : '';
    }
}
