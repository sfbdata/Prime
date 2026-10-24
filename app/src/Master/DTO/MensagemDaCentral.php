<?php

declare(strict_types=1);

namespace App\Master\DTO;

/**
 * Uma mensagem da Central de Notificações do Master, igual para toda origem (spec master-central-de-notificacoes.md,
 * D4). Cada leitor ({@see \App\Master\Notificacoes\OrigemDaCentral}) monta a sua a partir da tabela de origem.
 *
 * `tipo` é a chave visual do desenho (`sug`, `prob`, `falta`, `sup`, `fale`, `alerta`); `deEmail` é para onde a
 * resposta vai por e-mail (nulo = sem e-mail); `escritorio` é o texto ao lado do remetente ("Conta Nº 000123").
 */
final class MensagemDaCentral
{
    public function __construct(
        public readonly string $origem,
        public readonly int $idOrigem,
        public readonly string $tipo,
        public readonly string $rotuloDoTipo,
        public readonly string $deNome,
        public readonly ?string $deEmail,
        public readonly string $escritorio,
        public readonly ?int $tenantId,
        public readonly string $assunto,
        public readonly string $texto,
        public readonly \DateTimeImmutable $em,
        public readonly bool $podeResponder,
        // Já respondida na própria origem, fora da Central (a resposta dada pela página do Contribua).
        public readonly bool $respondidaNaOrigem = false,
    ) {
    }

    public function chave(): string
    {
        return $this->origem . ':' . $this->idOrigem;
    }

    /** Busca do desenho (D9): nome, e-mail, assunto e texto, sem diferenciar maiúsculas. */
    public function contem(string $termo): bool
    {
        $alvo = mb_strtolower($this->deNome . ' ' . ($this->deEmail ?? '') . ' ' . $this->assunto . ' ' . $this->texto);

        return str_contains($alvo, mb_strtolower($termo));
    }
}
