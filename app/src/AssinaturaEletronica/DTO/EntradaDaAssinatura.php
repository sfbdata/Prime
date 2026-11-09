<?php

declare(strict_types=1);

namespace App\AssinaturaEletronica\DTO;

use App\AssinaturaEletronica\Exception\AcaoRecusada;
use App\Shared\Service\DigitoVerificador;

/**
 * O corpo do "Assinar" da tela pública (contrato da etapa 2, seção 5.2): `{nome, cpf, codigo, modo, rubrica, aceite}`.
 * Cada conferência é um método, para o UseCase seguir a ordem do desenho (inventário H21): nome, CPF válido, CPF do
 * cadastro, rubrica, código pedido, código certo e, por último, o aceite.
 *
 * A rubrica chega sempre como PNG em data URL (a digitada também: o navegador desenha o nome na fonte escolhida).
 */
final readonly class EntradaDaAssinatura
{
    public const NOME_MAXIMO = 200;
    public const RUBRICA_MAXIMO_EM_BYTES = 200 * 1024;
    public const MODOS = ['desenhada', 'digitada'];
    private const PREFIXO_DA_RUBRICA = 'data:image/png;base64,';
    private const ASSINATURA_PNG = "\x89PNG\r\n\x1a\n";

    private function __construct(
        public string $nome,
        public string $cpf,
        #[\SensitiveParameter]
        public string $codigo,
        public string $modo,
        private string $rubrica,
        public bool $aceite,
    ) {
    }

    /**
     * @param array<mixed> $json
     *
     * @throws AcaoRecusada
     */
    public static function deJson(array $json): self
    {
        $modo = LeitorDeCampos::texto($json, 'modo', self::MODOS[0]);
        if (!\in_array($modo, self::MODOS, true)) {
            throw new AcaoRecusada('assinatura_eletronica.erro.dados_invalidos');
        }

        return new self(
            (string) preg_replace('/\s+/u', ' ', LeitorDeCampos::texto($json, 'nome')),
            (string) preg_replace('/\D/', '', LeitorDeCampos::texto($json, 'cpf')),
            LeitorDeCampos::texto($json, 'codigo'),
            $modo,
            LeitorDeCampos::texto($json, 'rubrica'),
            ($json['aceite'] ?? null) === true,
        );
    }

    /** @throws AcaoRecusada "Informe o nome completo." (menos de duas palavras) */
    public function exigirNome(): void
    {
        if (\count(explode(' ', $this->nome)) < 2) {
            throw new AcaoRecusada('assinatura_eletronica.erro.assinar_nome');
        }

        LeitorDeCampos::exigirTamanho($this->nome, self::NOME_MAXIMO, 'assinatura_eletronica.erro.assinar_nome_longo');
    }

    /** @throws AcaoRecusada "Informe um CPF válido." */
    public function exigirCpf(): void
    {
        if (!DigitoVerificador::cpfValido($this->cpf)) {
            throw new AcaoRecusada('assinatura_eletronica.erro.assinar_cpf');
        }
    }

    /** O código como o formulário manda: 6 dígitos. Outra coisa nem é conferida (e não conta tentativa). */
    public function codigoBemFormado(): bool
    {
        return preg_match('/^\d{6}$/', $this->codigo) === 1;
    }

    /**
     * Os bytes do PNG da rubrica, conferido pela assinatura do arquivo e pelo tamanho.
     *
     * @throws AcaoRecusada
     */
    public function rubricaPng(): string
    {
        if ($this->rubrica === '') {
            throw new AcaoRecusada('assinatura_eletronica.erro.assinar_sem_rubrica');
        }

        // Base64 tem 4 caracteres para cada 3 bytes: corta antes de decodificar o que já passa do teto.
        $limite = \strlen(self::PREFIXO_DA_RUBRICA) + 4 * (int) ceil(self::RUBRICA_MAXIMO_EM_BYTES / 3);
        if (\strlen($this->rubrica) > $limite) {
            throw new AcaoRecusada('assinatura_eletronica.erro.rubrica_grande');
        }

        $png = str_starts_with($this->rubrica, self::PREFIXO_DA_RUBRICA)
            ? base64_decode(substr($this->rubrica, \strlen(self::PREFIXO_DA_RUBRICA)), true)
            : false;
        if ($png === false || !str_starts_with($png, self::ASSINATURA_PNG)) {
            throw new AcaoRecusada('assinatura_eletronica.erro.rubrica_invalida');
        }
        if (\strlen($png) > self::RUBRICA_MAXIMO_EM_BYTES) {
            throw new AcaoRecusada('assinatura_eletronica.erro.rubrica_grande');
        }

        return $png;
    }
}
