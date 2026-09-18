<?php

declare(strict_types=1);

namespace App\Pasta\Service;

use App\Entity\Tenant\Tenant;
use App\Pasta\Armazenamento\ChavesDePasta;
use App\Pasta\Repository\PastaDocumentoRepository;
use App\Shared\Armazenamento\ArmazenamentoDeArquivos;
use App\Shared\Armazenamento\Exception\ArquivoNaoEncontrado;
use App\Shared\Armazenamento\Exception\ChaveDeArquivoInvalida;
use App\Shared\Armazenamento\Exception\FalhaDeArmazenamento;

/**
 * Responde à única pergunta que uma rotina de limpeza precisa fazer antes de apagar qualquer
 * coisa em `uploads/pastas/`: **este arquivo está referenciado dentro do conteúdo de alguma peça?**
 *
 * A regra que este serviço torna executável:
 *
 *   > "Arquivo sem linha própria no banco" NÃO significa "arquivo órfão".
 *
 * Imagens do editor não têm linha no banco (`UploadImagemEditorUseCase` devolve o nome e não
 * persiste). Elas vivem apenas dentro do HTML da peça. A auditoria E0 encontrou em produção uma
 * imagem exatamente nessa condição — viva, e invisível para qualquer critério banco × disco.
 *
 * **Nesta E1 nenhuma rotina de limpeza é escrita.** Este serviço existe para que a primeira a ser
 * escrita já nasça obrigada a consultá-lo; `LimpezaDeArquivosArquiteturaTest` é o que cobra isso.
 */
final class ArquivosReferenciadosEmPecas
{
    public function __construct(
        private readonly PastaDocumentoRepository $documentos,
        private readonly ArmazenamentoDeArquivos $armazenamento,
        private readonly ReferenciasDePecaHtml $referencias,
    ) {
    }

    /**
     * Nomes de arquivo citados pelo conteúdo das peças do escritório, sem repetição.
     *
     * Peça cujo arquivo não existe mais em disco é ignorada em silêncio: ela não consegue
     * referenciar nada, e estourar aqui transformaria uma inconsistência velha num erro novo.
     * Vale a mesma regra para um nome que o armazenamento se recusa a endereçar (medido em prod
     * 15/09: zero em 22.750 chaves) — a peça também não referencia nada, e a rotina de limpeza
     * não pode estourar por causa dela.
     *
     * **Pane do storage NÃO é ignorada** (E2.4B): uma peça que existe e não pôde ser lida
     * pareceria "sem imagens", e a limpeza que confiasse nesta resposta apagaria imagem em uso.
     * `FalhaDeArmazenamento` propaga e a rotina inteira para — é o lado seguro. Antes da E2.4B o
     * `file_get_contents` cru devolvia `''` com um warning em produção: falha aberta.
     *
     * @return string[]
     *
     * @throws FalhaDeArmazenamento quando alguma peça não pôde ser lida
     */
    public function doTenant(Tenant $tenant): array
    {
        $referenciados = [];
        $tenantId      = $tenant->getId() ?? throw new ChaveDeArquivoInvalida(
            'Escritório sem id: não há como montar a chave de armazenamento das peças dele.',
        );

        foreach ($this->documentos->chavesDePecasHtmlDoTenant($tenant) as $nomeDaPeca) {
            try {
                $chave = ChavesDePasta::documentoPorNome($tenantId, $nomeDaPeca);
            } catch (ChaveDeArquivoInvalida) {
                continue;
            }

            // `ler()` só responde "não encontrado" depois de provar que dava para olhar; diretório
            // ou arquivo ilegível é `FalhaDeArmazenamento` e não passa por este catch.
            try {
                $html = $this->armazenamento->ler($chave);
            } catch (ArquivoNaoEncontrado) {
                continue;
            }

            foreach ($this->referencias->extrair($html) as $nomeReferenciado) {
                $referenciados[$nomeReferenciado] = true;
            }
        }

        return array_keys($referenciados);
    }

    /**
     * Atalho de intenção para quem for escrever limpeza: a pergunta é sempre esta, nunca
     * "existe linha no banco?".
     */
    public function estaReferenciado(string $nomeArquivo, Tenant $tenant): bool
    {
        return in_array($nomeArquivo, $this->doTenant($tenant), true);
    }
}
