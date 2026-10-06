<?php

declare(strict_types=1);

namespace App\Pasta\Service;

use App\Entity\Tenant\Tenant;
use App\Pasta\Entity\PastaChecklistItem;
use App\Pasta\Entity\PastaDocumento;

/**
 * Selo "sem anexo" do checklist (DOC-74) e a pendência da aba Documentos que sai dele (DOC-76):
 * para cada item do checklist, diz se ALGUM arquivo da pasta corresponde a ele.
 *
 * Serviço puro: nome dos arquivos × título do item, nada de banco, nada de leitura do conteúdo.
 * É a regra de `ckAuditar` do desenho (02 - EXPEDIENTES 1.2.3, dc L4141-4146), copiada sem
 * enfeite:
 *
 *  1. Normalização: minúsculas e sem acento (a mesma do `SugestorDeDocumentos`). Os setters
 *     gravam em MAIÚSCULAS; a normalização torna isso irrelevante.
 *  2. Padrão do item, pelo título normalizado, na ordem:
 *       contém "procura"    → /procura/
 *       contém "identidade" → /\brg\b|cnh|identidade|_rg|rg_/
 *       contém "resid"      → /resid|endereco/
 *       contém "contrato"   → /contrato/
 *       contém "hipossuf"   → /hipossuf|gratuidade|pobreza/
 *       senão               → os 6 primeiros caracteres da 1ª palavra com 5+ letras
 *                             (ou do título inteiro, se não houver), como texto literal.
 *  3. Tem anexo = o padrão casa o TÍTULO ou o NOME ORIGINAL de algum documento da pasta (cada
 *     um isoladamente, como o JS testa cada nome).
 *
 * "Marcado sem anexo" = item CONCLUÍDO sem arquivo correspondente. Item pendente sem arquivo
 * é o esperado (por isso está pendente) e não acusa nada.
 *
 * É regra de nome, não prova: "Contrato" casa qualquer arquivo com "contrato" no nome. A tela
 * diz isso no title do selo ("conferência por regras"). A notificação à controladoria e a
 * "auditoria" do desenho NÃO existem aqui (DOC-75, decisão S-6 do dono).
 */
final readonly class ConferenciaDeAnexosDoChecklist
{
    /** dc L4144: as cinco chaves fixas, na ordem em que o JS as testa. */
    private const PADROES_FIXOS = [
        ['/procura/u', '/procura/u'],
        ['/identidade/u', '/\brg\b|cnh|identidade|_rg|rg_/u'],
        ['/resid/u', '/resid|endereco/u'],
        ['/contrato/u', '/contrato/u'],
        ['/hipossuf/u', '/hipossuf|gratuidade|pobreza/u'],
    ];

    /**
     * @param array<int, bool> $temAnexoPorItem  id do item => algum arquivo corresponde
     * @param list<int>        $marcadosSemAnexo ids dos itens concluídos sem arquivo correspondente, na ordem do checklist
     */
    private function __construct(
        public array $temAnexoPorItem,
        public array $marcadosSemAnexo,
    ) {
    }

    /**
     * @param list<array{id: int, titulo: string, concluido: bool}> $itens
     * @param list<string>                                         $nomesDosArquivos títulos e nomes originais dos documentos da pasta
     */
    public static function conferir(array $itens, array $nomesDosArquivos): self
    {
        $nomes = [];
        foreach ($nomesDosArquivos as $nome) {
            $nome = SugestorDeDocumentos::normalizar($nome);
            if ($nome !== '') {
                $nomes[] = $nome;
            }
        }

        $temAnexo  = [];
        $semAnexo  = [];
        foreach ($itens as $item) {
            $padrao = self::padraoDoItem($item['titulo']);
            $achou  = false;
            foreach ($nomes as $nome) {
                if (preg_match($padrao, $nome) === 1) {
                    $achou = true;
                    break;
                }
            }

            $temAnexo[$item['id']] = $achou;
            if ($item['concluido'] && !$achou) {
                $semAnexo[] = $item['id'];
            }
        }

        return new self($temAnexo, $semAnexo);
    }

    /**
     * Traduz as entidades para os dados simples de `conferir()`. Sem escritório na sessão não
     * há conferência; item ou documento de outro escritório é descartado, ainda que chegue pela
     * coleção (mesma guarda do `DocumentosSugeridosExtension`).
     *
     * @param iterable<PastaChecklistItem> $checklistItens
     * @param iterable<PastaDocumento>     $documentos
     */
    public static function daPasta(iterable $checklistItens, iterable $documentos, ?Tenant $tenant): self
    {
        $tenantId = $tenant?->getId();
        if ($tenantId === null) {
            return new self([], []);
        }

        $itens = [];
        foreach ($checklistItens as $item) {
            if ($item->getId() === null || $item->getTenant()?->getId() !== $tenantId) {
                continue;
            }
            $itens[] = ['id' => $item->getId(), 'titulo' => $item->getTitulo(), 'concluido' => $item->isConcluido()];
        }

        $nomes = [];
        foreach ($documentos as $documento) {
            if ($documento->getTenant()?->getId() !== $tenantId) {
                continue;
            }
            $nomes[] = $documento->getTitulo();
            $nomes[] = $documento->getNomeOriginal();
        }

        return self::conferir($itens, $nomes);
    }

    /**
     * Item que a conferência não conhece (criado depois da renderização) não acusa nada:
     * sem regra aplicada não há "sem anexo".
     */
    public function temAnexo(int $itemId): bool
    {
        return $this->temAnexoPorItem[$itemId] ?? true;
    }

    public function marcadoSemAnexo(int $itemId): bool
    {
        return \in_array($itemId, $this->marcadosSemAnexo, true);
    }

    public function totalMarcadosSemAnexo(): int
    {
        return \count($this->marcadosSemAnexo);
    }

    /** O padrão PCRE que o item procura nos nomes dos arquivos (regra 2 do cabeçalho). */
    public static function padraoDoItem(string $titulo): string
    {
        $s = SugestorDeDocumentos::normalizar(trim($titulo));

        foreach (self::PADROES_FIXOS as [$gatilho, $padrao]) {
            if (preg_match($gatilho, $s) === 1) {
                return $padrao;
            }
        }

        $base = $s;
        foreach (preg_split('/\s+/u', $s) ?: [] as $palavra) {
            if (mb_strlen($palavra) > 4) {
                $base = $palavra;
                break;
            }
        }

        // No JS o trecho vira `new RegExp` sem escape; aqui é literal — "(cópia)" no título não
        // pode virar grupo de regex nem erro de compilação.
        return '/' . preg_quote(mb_substr($base, 0, 6), '/') . '/u';
    }
}
