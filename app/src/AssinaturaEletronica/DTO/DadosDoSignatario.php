<?php

declare(strict_types=1);

namespace App\AssinaturaEletronica\DTO;

use App\AssinaturaEletronica\Enum\NivelDeAssinatura;
use App\AssinaturaEletronica\Exception\AcaoRecusada;

/**
 * Um signatário do rascunho, já conferido nos limites do contrato (seção 4.1). Nome obrigatório e dígito do CPF ficam
 * para o envio (etapa 2), como o desenho.
 */
final readonly class DadosDoSignatario
{
    public const NOME_MAXIMO = 200;
    public const PAPEL_MAXIMO = 120;
    public const EMAIL_MAXIMO = 180;

    private function __construct(
        public string $nome,
        public ?string $email,
        public ?string $cpf,
        public string $papel,
        public NivelDeAssinatura $nivel,
    ) {
    }

    /**
     * @param mixed $json  o objeto do signatário no `Rascunho`
     * @param int   $numero a posição na lista, a partir de 1, para a mensagem
     *
     * @throws AcaoRecusada
     */
    public static function deJson(mixed $json, int $numero): self
    {
        if (!\is_array($json)) {
            throw new AcaoRecusada('assinatura_eletronica.erro.dados_invalidos');
        }

        $nome = LeitorDeCampos::texto($json, 'nome');
        $papel = LeitorDeCampos::texto($json, 'papel');
        LeitorDeCampos::exigirTamanho($nome, self::NOME_MAXIMO, 'assinatura_eletronica.erro.signatario_nome_longo', ['numero' => $numero]);
        LeitorDeCampos::exigirTamanho($papel, self::PAPEL_MAXIMO, 'assinatura_eletronica.erro.signatario_papel_longo', ['numero' => $numero]);

        $nivel = NivelDeAssinatura::tryFrom(LeitorDeCampos::texto($json, 'nivel', NivelDeAssinatura::Simples->value))
            ?? throw new AcaoRecusada('assinatura_eletronica.erro.signatario_nivel_invalido', ['numero' => $numero]);

        return new self($nome, self::email($json, $numero), self::cpf($json, $numero), $papel, $nivel);
    }

    /**
     * @param array<mixed> $json
     *
     * @throws AcaoRecusada
     */
    private static function email(array $json, int $numero): ?string
    {
        $email = LeitorDeCampos::textoOuNulo($json, 'email');
        if ($email === null) {
            return null;
        }

        if (mb_strlen($email) > self::EMAIL_MAXIMO || filter_var($email, \FILTER_VALIDATE_EMAIL) === false) {
            throw new AcaoRecusada('assinatura_eletronica.erro.signatario_email_invalido', ['numero' => $numero]);
        }

        return $email;
    }

    /**
     * Pontuação da máscara sai; o que sobra precisa ser 11 dígitos.
     *
     * @param array<mixed> $json
     *
     * @throws AcaoRecusada
     */
    private static function cpf(array $json, int $numero): ?string
    {
        $cpf = LeitorDeCampos::textoOuNulo($json, 'cpf');
        if ($cpf === null) {
            return null;
        }

        $digitos = preg_replace('/[\s.\-]/', '', $cpf) ?? '';
        if (preg_match('/^\d{11}$/', $digitos) !== 1) {
            throw new AcaoRecusada('assinatura_eletronica.erro.signatario_cpf_invalido', ['numero' => $numero]);
        }

        return $digitos;
    }
}
