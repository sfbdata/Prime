<?php

declare(strict_types=1);

namespace App\Inteligencia\Contexto;

use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Inteligencia\DTO\AnexoDeDocumento;
use App\Inteligencia\Exception\ArmazenamentoIndisponivelException;
use App\Inteligencia\Exception\DocumentoIlegivelException;
use App\Inteligencia\Service\RegraDaLeituraDeDocumentos;
use App\Pasta\Armazenamento\ChavesDePasta;
use App\Pasta\Entity\AbaDaPasta;
use App\Pasta\Entity\Pasta;
use App\Pasta\Entity\PastaDocumento;
use App\Pasta\Service\ContadorDePaginasDePdf;
use App\Pasta\Service\VisibilidadeDasAbasDaPasta;
use App\Service\PermissionChecker;
use App\Shared\Armazenamento\ArmazenamentoDeArquivos;
use App\Shared\Armazenamento\Exception\ArquivoNaoEncontrado;
use App\Shared\Armazenamento\Exception\ChaveDeArquivoInvalida;
use App\Shared\Armazenamento\Exception\FalhaDeArmazenamento;
use App\Shared\Armazenamento\MaterializadorDeArquivo;

/**
 * No worker, os arquivos do "Ler N documentos" (PR F da spec ia-anthropic-ligar) como {@see AnexoDeDocumento}.
 * Os ids são os que a solicitação validou e gravou em `contexto_resumo.documentosSelecionados`, relidos com
 * TENANT EXPLÍCITO (o worker não tem sessão; o `TenantFilter` fica inerte). Entre o pedido e o worker tudo pode
 * ter mudado, então tudo é conferido de novo, AGORA:
 *
 * - quem pediu (`solicitante` da análise) ainda acessa a pasta (`PermissionChecker::canAccessResource`, que
 *   recebe pessoa e escritório explícitos e não depende de request);
 * - as abas ocultas valem somadas: as que a solicitação gravou MAIS as de agora
 *   (`VisibilidadeDasAbasDaPasta::abasOcultasPara`, que lê as regras da pasta pelo escritório da pasta, sem
 *   request). Aba Documentos oculta = nada é lido; contrato de honorários some com o Financeiro oculto;
 * - cada documento é da pasta e do escritório da análise e está fora da lixeira;
 * - o aceite do escritório cobre a leitura ({@see RegraDaLeituraDeDocumentos::aceiteCobre});
 * - o conteúdo é PDF ou imagem PELA ASSINATURA (é ele que vai como `media_type`), dentro do teto do tipo, e os
 *   arquivos juntos dentro do teto total;
 * - os PDFs somam no máximo {@see RegraDaLeituraDeDocumentos::PAGINAS_MAXIMAS} páginas. Vale a contagem gravada no
 *   upload; sem ela, conta pelo arquivo com o MESMO `ContadorDePaginasDePdf` do upload, e sem contagem recusa.
 *
 * Recusa do documento é {@see DocumentoIlegivelException} (a análise falha sem chamar o provedor), nunca um anexo a
 * menos em silêncio: a resposta diria que leu o que não leu. Falha de I/O do armazenamento é
 * {@see ArmazenamentoIndisponivelException}, transitória (o handler devolve à fila).
 *
 * O arquivo é lido pela CHAVE do armazenamento (`ChavesDePasta::documento`), nunca por caminho. Nada do conteúdo é
 * logado nem persistido aqui; as mensagens levam o id e o motivo, nunca o nome nem o conteúdo.
 */
final class LeitorDeDocumentosDaPasta
{
    public function __construct(
        private readonly FonteDeDadosDaPasta $dados,
        private readonly ArmazenamentoDeArquivos $armazenamento,
        private readonly MaterializadorDeArquivo $materializador,
        private readonly ContadorDePaginasDePdf $contadorDePaginas,
        private readonly RegraDaLeituraDeDocumentos $regra,
        private readonly PermissionChecker $permissoes,
        private readonly VisibilidadeDasAbasDaPasta $abas,
    ) {
    }

    /**
     * @param list<int>    $ids         na ordem gravada pela solicitação; é a ordem dos anexos
     * @param list<string> $abasOcultas como a solicitação gravou (as de agora entram por cima)
     *
     * @return list<AnexoDeDocumento>
     *
     * @throws DocumentoIlegivelException
     * @throws ArmazenamentoIndisponivelException
     */
    public function anexos(Tenant $tenant, Pasta $pasta, array $ids, array $abasOcultas, ?User $solicitante): array
    {
        $ocultas = $this->conferirPedido($tenant, $pasta, $ids, $abasOcultas, $solicitante);

        $porId = [];
        foreach (VisibilidadeDasAbasDaPasta::documentosVisiveis($this->dados->documentosDaPastaPorIds($tenant, $pasta, $ids), $ocultas) as $documento) {
            $porId[(int) $documento->getId()] = $documento;
        }

        $anexos = [];
        $total = 0;
        $paginas = 0;
        foreach ($ids as $id) {
            [$anexo, $paginasDoAnexo] = $this->anexo($tenant, $pasta, $id, $porId[$id] ?? null);
            $total += $anexo->tamanho();
            $paginas += $paginasDoAnexo;
            if ($total > RegraDaLeituraDeDocumentos::TAMANHO_MAXIMO_TOTAL) {
                throw DocumentoIlegivelException::porque($id, 'os documentos juntos passam do limite de tamanho');
            }
            if ($paginas > RegraDaLeituraDeDocumentos::PAGINAS_MAXIMAS) {
                throw DocumentoIlegivelException::porque($id, 'os PDFs juntos passam do limite de páginas');
            }
            $anexos[] = $anexo;
        }

        return $anexos;
    }

    /**
     * Confere o pedido inteiro e devolve as abas ocultas que valem (gravadas + atuais).
     *
     * @param list<int>    $ids
     * @param list<string> $abasOcultas
     *
     * @return list<string>
     */
    private function conferirPedido(Tenant $tenant, Pasta $pasta, array $ids, array $abasOcultas, ?User $solicitante): array
    {
        if ($ids === [] || \count($ids) > RegraDaLeituraDeDocumentos::MAXIMO_POR_ANALISE) {
            throw DocumentoIlegivelException::porque(0, 'seleção vazia ou acima do limite');
        }
        if ($solicitante === null || !$this->permissoes->canAccessResource($solicitante, $tenant, 'pasta', (int) $pasta->getId(), 'view')) {
            throw DocumentoIlegivelException::porque(0, 'quem pediu não acessa mais a pasta');
        }
        $agora = array_map(static fn (AbaDaPasta $a): string => $a->value, $this->abas->abasOcultasPara($pasta, $solicitante));
        $ocultas = array_values(array_unique([...$abasOcultas, ...$agora]));
        if (\in_array(AbaDaPasta::Documentos->value, $ocultas, true)) {
            throw DocumentoIlegivelException::porque(0, 'aba Documentos oculta para quem pediu');
        }
        if (!$this->regra->aceiteCobre($tenant)) {
            throw DocumentoIlegivelException::porque(0, 'o aceite do escritório não cobre a leitura de documentos');
        }

        return $ocultas;
    }

    /** @return array{AnexoDeDocumento, int} o anexo e as páginas que ele conta (0 para imagem) */
    private function anexo(Tenant $tenant, Pasta $pasta, int $id, ?PastaDocumento $documento): array
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
        $anexo = new AnexoDeDocumento($id, $documento->getNomeOriginal(), $tipo, $bytes);

        return [$anexo, $anexo->ehPdf() ? $this->paginas($documento, $id) : 0];
    }

    /** As páginas do PDF: a contagem do upload ou, sem ela, a do arquivo (o mesmo contador do upload). */
    private function paginas(PastaDocumento $documento, int $id): int
    {
        $paginas = $documento->getPaginas();
        if ($paginas === null) {
            try {
                $paginas = $this->contadorDePaginas->contar($this->materializador->paraLeitura(ChavesDePasta::documento($documento))->caminho());
            } catch (ArquivoNaoEncontrado) {
                throw DocumentoIlegivelException::porque($id, 'arquivo ausente no armazenamento');
            } catch (FalhaDeArmazenamento $e) {
                throw ArmazenamentoIndisponivelException::aoLer($id, $e);
            }
        }
        if ($paginas === null) {
            throw DocumentoIlegivelException::porque($id, 'não foi possível contar as páginas do PDF');
        }

        return $paginas;
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
            throw ArmazenamentoIndisponivelException::aoLer($id, $e);
        }
        if ($bytes === '') {
            throw DocumentoIlegivelException::porque($id, 'arquivo vazio');
        }

        return $bytes;
    }
}
