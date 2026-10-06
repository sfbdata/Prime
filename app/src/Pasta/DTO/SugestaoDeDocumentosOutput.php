<?php

declare(strict_types=1);

namespace App\Pasta\DTO;

/**
 * Resultado do `SugestorDeDocumentos` para uma pasta.
 *
 * `temCatalogo = false` quando a pasta não dá base nenhuma para escolher catálogo (sem ação e
 * sem classe processual): a tela diz isso em vez de sugerir a lista genérica.
 *
 * `publicacoesLidas` é quantos teores do Push foram lidos por regras (`DeterminacoesDoJuizo`); 0 =
 * o processo não foi lido, e a tela diz isso. `prazosEmCurso` são as linhas "Prazos em curso" do
 * desenho (DOC-82): só prazos EXPLÍCITOS no teor que certamente ainda correm.
 */
final class SugestaoDeDocumentosOutput
{
    /**
     * @param list<DocumentoSugeridoOutput> $itens já ordenados: exigido pelo juízo, obrigatório, recomendável, opcional, existente, não aplicável
     * @param list<string>                  $prazosEmCurso
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
        public readonly int $publicacoesLidas = 0,
        public readonly array $prazosEmCurso = [],
    ) {
    }

    /** O teor de alguma publicação do processo foi lido por regras. */
    public function leuOProcesso(): bool
    {
        return $this->publicacoesLidas > 0;
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
