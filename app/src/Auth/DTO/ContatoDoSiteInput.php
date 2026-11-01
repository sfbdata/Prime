<?php

declare(strict_types=1);

namespace App\Auth\DTO;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * O formulário de Contato do site institucional (repositório sites-bluejus, no mesmo domínio), enviado para
 * POST /contato. Vira uma mensagem ao suporte com origem 'site' ({@see paraMensagemSuporte()}), que cai na Central de
 * Notificações do Master como "Fale conosco". O consentimento só é validado, não é gravado.
 */
final class ContatoDoSiteInput
{
    /** Os assuntos do formulário do site. */
    public const ASSUNTOS = ['Suporte', 'Dúvidas comerciais', 'Parcerias institucionais', 'Sugestão de funcionalidade', 'Problema técnico'];

    public function __construct(
        #[Assert\NotBlank(message: 'Informe o seu nome.')]
        public readonly string $nome,
        #[Assert\NotBlank(message: 'Informe um e-mail válido para a resposta.')]
        #[Assert\Email(mode: Assert\Email::VALIDATION_MODE_STRICT, message: 'Informe um e-mail válido para a resposta.')]
        public readonly string $email,
        #[Assert\Choice(choices: self::ASSUNTOS, message: 'Escolha o assunto.')]
        public readonly string $assunto,
        #[Assert\NotBlank(message: 'Escreva a sua mensagem.')]
        #[Assert\Length(min: 5, minMessage: 'Escreva a sua mensagem.')]
        public readonly string $mensagem,
        #[Assert\IsTrue(message: 'Marque que concorda com o uso dos seus dados para a resposta.')]
        public readonly bool $consentimento,
        public readonly string $ip,
        public readonly ?string $userAgent = null,
    ) {
    }

    /** @param array<mixed> $dados os campos do formulário (form-urlencoded) */
    public static function doFormulario(array $dados, string $ip, ?string $userAgent): self
    {
        return new self(
            nome: mb_substr(self::texto($dados, 'nome'), 0, 120),
            email: mb_substr(self::texto($dados, 'email'), 0, 180),
            assunto: self::texto($dados, 'assunto'),
            mensagem: mb_substr(self::texto($dados, 'mensagem'), 0, 5000),
            consentimento: self::texto($dados, 'consentimento') === '1',
            ip: mb_substr($ip, 0, 45),
            userAgent: $userAgent,
        );
    }

    public function paraMensagemSuporte(): EnviarMensagemSuporteInput
    {
        return new EnviarMensagemSuporteInput(
            nome: $this->nome,
            email: $this->email,
            assunto: $this->assunto,
            mensagem: $this->mensagem,
            ip: $this->ip,
            userAgent: $this->userAgent,
            origem: 'site',
        );
    }

    /** @param array<mixed> $dados */
    private static function texto(array $dados, string $campo): string
    {
        $valor = $dados[$campo] ?? '';

        return is_scalar($valor) ? trim((string) $valor) : '';
    }
}
