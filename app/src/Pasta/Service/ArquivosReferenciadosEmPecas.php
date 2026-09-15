<?php

declare(strict_types=1);

namespace App\Pasta\Service;

use App\Entity\Tenant\Tenant;
use App\Pasta\Repository\PastaDocumentoRepository;
use App\Shared\Service\ArquivoStorageInterface;

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
        private readonly ArquivoStorageInterface $storage,
        private readonly ReferenciasDePecaHtml $referencias,
        private readonly string $uploadsDir,
    ) {
    }

    /**
     * Nomes de arquivo citados pelo conteúdo das peças do escritório, sem repetição.
     *
     * Peça cujo arquivo não existe mais em disco é ignorada em silêncio: ela não consegue
     * referenciar nada, e estourar aqui transformaria uma inconsistência velha num erro novo.
     *
     * @return string[]
     */
    public function doTenant(Tenant $tenant): array
    {
        $referenciados = [];

        foreach ($this->documentos->chavesDePecasHtmlDoTenant($tenant) as $chave) {
            $caminho = $this->storage->caminho($this->uploadsDir, $chave);

            if (!$this->storage->existe($caminho)) {
                continue;
            }

            $html = (string) file_get_contents($caminho);

            foreach ($this->referencias->extrair($html) as $nome) {
                $referenciados[$nome] = true;
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
