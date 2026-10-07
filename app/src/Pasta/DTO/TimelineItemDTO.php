<?php

namespace App\Pasta\DTO;

final readonly class TimelineItemDTO
{
    public function __construct(
        public TimelineItemType $tipo,
        public \DateTimeImmutable $dataHora,
        public string $titulo,
        public ?string $detalhe,
        public ?string $autorNome,
        public ?string $autorEmail,
        public string $icone,
        public string $badgeCss,
        public ?string $arquivoAnexo = null,
        public ?int $mensagemId = null,
        public ?\DateTimeImmutable $editadoEm = null,
        public ?int $usuarioId = null,
        public ?int $metaId = null,
        public ?string $metaTitulo = null,
        // ── "Responder" (só mensagens da pasta) ──
        /** @var TimelineItemDTO[] respostas penduradas nesta raiz, da mais antiga à mais nova */
        public array $respostas = [],
        public bool $ehResposta = false,
        public ?int $respostaAId = null,
        // Autor da raiz respondida; null numa resposta cuja original foi excluída.
        public ?string $respostaANome = null,
        // De onde veio o evento do audit_log ('pasta', 'documento', 'meta', 'processo'); null nas
        // mensagens. Quem lê é a Timeline inteligente, para agrupar por categoria sem adivinhar
        // pelo texto do título.
        public ?string $origem = null,
    ) {}
}
