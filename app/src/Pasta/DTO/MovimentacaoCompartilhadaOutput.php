<?php

declare(strict_types=1);

namespace App\Pasta\DTO;

use App\Djen\DTO\PublicacaoDjenListaItem;

/**
 * Uma linha de "Movimentações recebidas" da página pública ([PC] 88-93). O texto é o mesmo título do
 * cartão da aba Push (o trecho curto do teor, texto puro), nunca o teor inteiro nem o documento.
 *
 * O DJEN não dá hora: a coluna da hora do desenho fica vazia (a aba faz o mesmo, N2).
 */
final readonly class MovimentacaoCompartilhadaOutput
{
    private function __construct(
        public string $data,
        public string $tipo,
        public string $cor,
        public string $fonte,
        public string $texto,
    ) {
    }

    public static function fromItem(PublicacaoDjenListaItem $item): self
    {
        $tipo = $item->tipoComunicacao ?? 'Publicação';
        $fonte = implode(' · ', array_filter([$item->siglaTribunal, $item->nomeOrgao], static fn (?string $p): bool => $p !== null && $p !== ''));

        return new self(
            data: $item->dataDisponibilizacao ?? 'Sem data',
            tipo: $tipo,
            cor: self::corDoTipo($tipo),
            fonte: $fonte,
            texto: $item->textoResumo ?: ($item->nomeOrgao ?: $tipo),
        );
    }

    /**
     * Cor da etiqueta do tipo, do mapa `COR` do desenho ([PC] 151): Intimação vermelho; Decisão e
     * Sentença âmbar; Citação e Despacho azul; Juntada e o resto cinza. Compara sem acento e sem
     * caixa, pelo começo da palavra (o DJEN grava "INTIMAÇÃO", "Intimação"...).
     */
    public static function corDoTipo(string $tipo): string
    {
        $normalizado = strtr(mb_strtolower(trim($tipo)), ['ç' => 'c', 'ã' => 'a', 'á' => 'a', 'â' => 'a', 'é' => 'e', 'ê' => 'e', 'í' => 'i', 'ó' => 'o', 'õ' => 'o', 'ú' => 'u']);

        return match (true) {
            str_starts_with($normalizado, 'intima') => 'vermelho',
            str_starts_with($normalizado, 'decis'), str_starts_with($normalizado, 'senten') => 'ambar',
            str_starts_with($normalizado, 'citac'), str_starts_with($normalizado, 'despacho') => 'azul',
            default => 'cinza',
        };
    }
}
