<?php

declare(strict_types=1);

namespace App\Inteligencia\DTO;

/**
 * UM arquivo da pasta que vai ao provedor junto do pedido (PR F da spec ia-anthropic-ligar, "Ler N
 * documentos"): o conteúdo inteiro, em memória, só durante a montagem do pedido no worker. Imutável.
 *
 * O `mediaType` é o tipo medido pelo CONTEÚDO (assinatura do arquivo), não o que o upload declarou:
 * só PDF e as quatro imagens que a API da Anthropic lê. Fora disso o construtor recusa.
 *
 * NUNCA é persistido nem logado: o que fica gravado da leitura é o id e o {@see sha256()} de cada
 * anexo (`contexto_resumo.documentosLidos`).
 */
final readonly class AnexoDeDocumento
{
    public const PDF = 'application/pdf';
    public const IMAGENS = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];

    public function __construct(
        public int $documentoId,
        public string $nome,
        public string $mediaType,
        public string $bytes,
    ) {
        if ($mediaType !== self::PDF && !\in_array($mediaType, self::IMAGENS, true)) {
            throw new \InvalidArgumentException('Anexo só pode ser PDF ou imagem (JPEG, PNG, GIF ou WEBP).');
        }
        if ($bytes === '') {
            throw new \InvalidArgumentException('Anexo vazio.');
        }
    }

    public function ehPdf(): bool
    {
        return $this->mediaType === self::PDF;
    }

    public function base64(): string
    {
        return base64_encode($this->bytes);
    }

    public function sha256(): string
    {
        return hash('sha256', $this->bytes);
    }

    public function tamanho(): int
    {
        return \strlen($this->bytes);
    }
}
