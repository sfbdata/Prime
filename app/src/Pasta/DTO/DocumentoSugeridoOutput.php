<?php

declare(strict_types=1);

namespace App\Pasta\DTO;

/**
 * Um documento do catálogo, já julgado contra o que a pasta tem.
 *
 * `status` é uma das constantes abaixo. "Exigido pelo juízo" (🔴 do desenho) não existe aqui de
 * propósito: ele sai da leitura das decisões do processo, que este sugestor NÃO faz.
 */
final class DocumentoSugeridoOutput
{
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
    ) {
    }

    /** Pode ganhar o botão "+ Checklist": ainda falta, e ninguém pôs no checklist. */
    public function podeIrAoChecklist(): bool
    {
        return !$this->noChecklist
            && !\in_array($this->status, [self::JA_EXISTE, self::NAO_APLICAVEL], true);
    }

    /** Entra no "Adicionar faltantes": só obrigatório e recomendável (bj-docsug L4075, menos o juízo). */
    public function ehFaltante(): bool
    {
        return !$this->noChecklist
            && \in_array($this->status, [self::OBRIGATORIO, self::RECOMENDAVEL], true);
    }
}
