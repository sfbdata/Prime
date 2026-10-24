<?php

declare(strict_types=1);

namespace App\Inteligencia\Contexto;

use App\Entity\Tenant\Tenant;
use App\Inteligencia\DTO\AnexoDeDocumento;
use App\Inteligencia\Exception\DocumentoIlegivelException;
use App\Inteligencia\Service\RegraDaLeituraDeDocumentos;
use App\Pasta\Armazenamento\ChavesDePasta;
use App\Pasta\Entity\AbaDaPasta;
use App\Pasta\Entity\Pasta;
use App\Pasta\Entity\PastaDocumento;
use App\Pasta\Service\VisibilidadeDasAbasDaPasta;
use App\Shared\Armazenamento\ArmazenamentoDeArquivos;
use App\Shared\Armazenamento\Exception\ArquivoNaoEncontrado;
use App\Shared\Armazenamento\Exception\ChaveDeArquivoInvalida;
use App\Shared\Armazenamento\Exception\FalhaDeArmazenamento;

/**
 * No worker, os arquivos do "Ler N documentos" (PR F da spec ia-anthropic-ligar) como {@see AnexoDeDocumento}:
 * os ids que a solicitação validou e gravou em `contexto_resumo.documentosSelecionados`, relidos com TENANT
 * EXPLÍCITO (o worker não tem sessão; o `TenantFilter` fica inerte) e conferidos de novo, porque entre o pedido e
 * o worker o documento pode ter saído da pasta, ido para a lixeira, ficado oculto ou o aceite mudado.
 *
 * Qualquer recusa é {@see DocumentoIlegivelException} (a análise falha sem chamar o provedor), nunca um anexo a
 * menos em silêncio: a resposta diria que leu o que não leu.
 *
 * O arquivo é lido pela CHAVE do armazenamento (`ChavesDePasta::documento`), nunca por caminho, e o tipo que vai
 * como `media_type` é o do conteúdo ({@see RegraDaLeituraDeDocumentos::tipoPeloConteudo}), não o declarado no upload.
 * Nada do conteúdo é logado nem persistido aqui.
 */
final class LeitorDeDocumentosDaPasta
{
    public function __construct(
        private readonly FonteDeDadosDaPasta $dados,
        private readonly ArmazenamentoDeArquivos $armazenamento,
        private readonly RegraDaLeituraDeDocumentos $regra,
    ) {
    }

    /**
     * @param list<int>    $ids         na ordem gravada pela solicitação; é a ordem dos anexos
     * @param list<string> $abasOcultas como a solicitação gravou
     *
     * @return list<AnexoDeDocumento>
     *
     * @throws DocumentoIlegivelException
     */
    public function anexos(Tenant $tenant, Pasta $pasta, array $ids, array $abasOcultas): array
    {
        $this->conferirPedido($tenant, $ids, $abasOcultas);

        $porId = [];
        foreach (VisibilidadeDasAbasDaPasta::documentosVisiveis($this->dados->documentosDaPastaPorIds($tenant, $pasta, $ids), $abasOcultas) as $documento) {
            $porId[(int) $documento->getId()] = $documento;
        }

        $anexos = [];
        $total = 0;
        foreach ($ids as $id) {
            $anexo = $this->anexo($tenant, $pasta, $id, $porId[$id] ?? null);
            $total += $anexo->tamanho();
            if ($total > RegraDaLeituraDeDocumentos::TAMANHO_MAXIMO_TOTAL) {
                throw DocumentoIlegivelException::porque($id, 'os documentos juntos passam do limite');
            }
            $anexos[] = $anexo;
        }

        return $anexos;
    }

    /**
     * @param list<int>    $ids
     * @param list<string> $abasOcultas
     */
    private function conferirPedido(Tenant $tenant, array $ids, array $abasOcultas): void
    {
        if ($ids === [] || \count($ids) > RegraDaLeituraDeDocumentos::MAXIMO_POR_ANALISE) {
            throw DocumentoIlegivelException::porque(0, 'seleção vazia ou acima do limite');
        }
        if (\in_array(AbaDaPasta::Documentos->value, $abasOcultas, true)) {
            throw DocumentoIlegivelException::porque(0, 'aba Documentos oculta para quem pediu');
        }
        if (!$this->regra->aceiteCobre($tenant)) {
            throw DocumentoIlegivelException::porque(0, 'o aceite do escritório não cobre a leitura de documentos');
        }
    }

    private function anexo(Tenant $tenant, Pasta $pasta, int $id, ?PastaDocumento $documento): AnexoDeDocumento
    {
        // A fonte já filtra por pasta e tenant; a conferência repetida é a defesa se a fonte mudar.
        if ($documento === null
            || $documento->getTenant()?->getId() !== $tenant->getId()
            || $documento->getPasta()?->getId() !== $pasta->getId()) {
            throw DocumentoIlegivelException::porque($id, 'fora da pasta, do escritório ou da lista visível');
        }
        if ($documento->estaNaLixeira()) {
            throw DocumentoIlegivelException::porque($id, 'na lixeira');
        }

        $bytes = $this->ler($documento, $id);
        $tipo = RegraDaLeituraDeDocumentos::tipoPeloConteudo($bytes);
        if ($tipo === null) {
            throw DocumentoIlegivelException::porque($id, 'o conteúdo não é PDF nem imagem');
        }
        if (\strlen($bytes) > RegraDaLeituraDeDocumentos::limiteDe($tipo)) {
            throw DocumentoIlegivelException::porque($id, 'passa do tamanho máximo');
        }

        return new AnexoDeDocumento($id, $documento->getNomeOriginal(), $tipo, $bytes);
    }

    private function ler(PastaDocumento $documento, int $id): string
    {
        try {
            $bytes = $this->armazenamento->ler(ChavesDePasta::documento($documento));
        } catch (ArquivoNaoEncontrado) {
            throw DocumentoIlegivelException::porque($id, 'arquivo ausente no armazenamento');
        } catch (ChaveDeArquivoInvalida) {
            throw DocumentoIlegivelException::porque($id, 'chave do arquivo inválida');
        } catch (FalhaDeArmazenamento $e) {
            throw DocumentoIlegivelException::porque($id, 'falha ao ler o armazenamento: ' . (new \ReflectionClass($e))->getShortName());
        }
        if ($bytes === '') {
            throw DocumentoIlegivelException::porque($id, 'arquivo vazio');
        }

        return $bytes;
    }
}
