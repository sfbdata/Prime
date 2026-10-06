<?php

declare(strict_types=1);

namespace App\Pasta\DTO;

/**
 * Resultado do `SugestorDeDocumentos` para uma pasta.
 *
 * `temCatalogo = false` quando a pasta não dá base nenhuma para escolher catálogo (sem ação e
 * sem classe processual): a tela diz isso em vez de sugerir a lista genérica.
 */
final class SugestaoDeDocumentosOutput
{
    /**
     * @param list<DocumentoSugeridoOutput> $itens já ordenados: obrigatório, recomendável, opcional, existente, não aplicável
     */
    public function __construct(
        public readonly bool $temCatalogo,
        public readonly ?string $faseChave,
        public readonly ?string $faseNome,
        public readonly ?string $base,
        public readonly ?string $acao,
        public readonly ?string $classe,
        public readonly ?string $numeroProcesso,
        public readonly array $itens,
    ) {
    }

    public static function semCatalogo(): self
    {
        return new self(false, null, null, null, null, null, null, []);
    }

    /** @return list<DocumentoSugeridoOutput> */
    public function faltantes(): array
    {
        return array_values(array_filter($this->itens, static fn (DocumentoSugeridoOutput $i): bool => $i->ehFaltante()));
    }

    /** @return list<string> nomes dos faltantes, na ordem da tela — o que o botão grava no checklist */
    public function nomesDosFaltantes(): array
    {
        return array_map(static fn (DocumentoSugeridoOutput $i): string => $i->nome, $this->faltantes());
    }

    /** @return list<DocumentoSugeridoOutput> */
    public function comStatus(string $status): array
    {
        return array_values(array_filter($this->itens, static fn (DocumentoSugeridoOutput $i): bool => $i->status === $status));
    }
}
