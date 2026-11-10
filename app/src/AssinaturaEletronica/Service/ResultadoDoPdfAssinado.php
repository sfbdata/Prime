<?php

declare(strict_types=1);

namespace App\AssinaturaEletronica\Service;

use App\AssinaturaEletronica\Service\ConferenciaDePdf\ResultadoDaConferencia;

/**
 * O desfecho da {@see ConferenciaDoPdfAssinado} (etapa 5, bloco 5C): aceito, com o resultado do motor, ou recusado,
 * com o código do erro do catálogo público (`assinatura_eletronica_publica.js.certificado.erro.*`, dc:385 e dc:466).
 * Os códigos são os mesmos do motivo do evento `pdf_assinado_recusado` da trilha.
 *
 * Aceito aqui quer dizer só que o arquivo passou no que vem antes do validador de cadeia (motor, regra da E6-6 e CPF):
 * a cadeia ainda não foi conferida.
 */
final readonly class ResultadoDoPdfAssinado
{
    /** O prefixo da chave do texto no catálogo público. */
    public const CHAVE = 'assinatura_eletronica_publica.js.certificado.erro.';

    public const SEM_PDF = 'sem_pdf';
    public const SEM_ASSINATURA = 'sem_assinatura';
    public const ALTERADA = 'alterada';
    public const PREFIXO = 'prefixo';
    public const SEM_ASSINATURA_NOVA = 'sem_assinatura_nova';
    public const ILEGIVEL = 'ilegivel';
    public const SEM_CPF = 'sem_cpf';
    public const CPF_DIFERENTE = 'cpf_diferente';
    public const CPF_NAO_LIDO = 'cpf_nao_lido';
    public const CADEIA_INDISPONIVEL = 'cadeia_indisponivel';
    public const CADEIA_RECUSADA = 'cadeia_recusada';

    private function __construct(
        /** O que o motor conferiu; nulo só quando nem chegou a rodar (documento sem versão base). */
        public ?ResultadoDaConferencia $motor,
        /** O código do erro do catálogo; nulo quando aceito. */
        public ?string $erro,
    ) {
    }

    public static function aceito(ResultadoDaConferencia $motor): self
    {
        return new self($motor, null);
    }

    public static function recusado(string $erro, ?ResultadoDaConferencia $motor): self
    {
        return new self($motor, $erro);
    }

    /** A chave do texto de um código de erro no catálogo público. */
    public static function chave(string $erro): string
    {
        return self::CHAVE . $erro;
    }

    public function aceitou(): bool
    {
        return $this->erro === null;
    }

    /** Quantas assinaturas digitais o arquivo tem (o `n` de dc:466, `ana.assinaturas.length`). */
    public function assinaturas(): int
    {
        return $this->motor === null ? 0 : \count($this->motor->assinaturas);
    }

    /**
     * Se o arquivo traz assinaturas de antes da versão base, preservadas byte a byte (o "com as anteriores preservadas"
     * de dc:466). Só vale com o prefixo conferido: as assinaturas que não são novas estão dentro da versão base.
     */
    public function preservadas(): bool
    {
        return $this->aceitou() && $this->assinaturas() > (int) $this->motor?->assinaturasNovas;
    }
}
