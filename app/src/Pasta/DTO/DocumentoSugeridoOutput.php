<?php

declare(strict_types=1);

namespace App\Pasta\DTO;

/**
 * Um documento do catálogo, já julgado contra o que a pasta tem.
 *
 * `status` é uma das constantes abaixo. "Exigido pelo juízo" (🔴 do desenho) só aparece quando a
 * leitura POR REGRAS do teor das publicações do Push (`DeterminacoesDoJuizo`) achou uma
 * determinação que manda juntar o documento — e aí `origemPublicacaoId`/`origemTexto` dizem de
 * qual publicação veio ("Origem: Decisão de 03/09/2026").
 */
final class DocumentoSugeridoOutput
{
    public const EXIGIDO_PELO_JUIZO = 'juizo';
    public const OBRIGATORIO = 'req';
    public const RECOMENDAVEL = 'rec';
    public const OPCIONAL = 'opc';
    public const JA_EXISTE = 'existe';
    public const NAO_APLICAVEL = 'na';

    /**
     * @param list<string> $localizadoEm rótulos dos documentos da pasta que casaram ("Pasta: X · 01/02/2026")
     */
    public function __construct(
        public readonly string $chave,
        public readonly string $nome,
        public readonly string $status,
        public readonly string $porque,
        public readonly array $localizadoEm,
        public readonly bool $noChecklist,
        public readonly bool $conferidoNoChecklist,
        public readonly ?int $origemPublicacaoId = null,
        public readonly ?string $origemTexto = null,
    ) {
    }

    /** Pode ganhar o botão "+ Checklist": ainda falta, e ninguém pôs no checklist. */
    public function podeIrAoChecklist(): bool
    {
        return !$this->noChecklist
            && !\in_array($this->status, [self::JA_EXISTE, self::NAO_APLICAVEL], true);
    }

    /** Entra no "Adicionar faltantes": exigido pelo juízo, obrigatório e recomendável (dc L4075). */
    public function ehFaltante(): bool
    {
        return !$this->noChecklist
            && \in_array($this->status, [self::EXIGIDO_PELO_JUIZO, self::OBRIGATORIO, self::RECOMENDAVEL], true);
    }
}
