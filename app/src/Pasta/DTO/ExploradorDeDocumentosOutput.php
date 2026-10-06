<?php

declare(strict_types=1);

namespace App\Pasta\DTO;

use App\Pasta\Entity\PastaDocumento;
use App\Pasta\Entity\PastaSecao;

/**
 * O que o explorador da aba Documentos recebe do servidor, já resolvido — pastas, arquivos, rótulos
 * de categoria e os endereços/tokens de cada ação, prontos para virar o JSON de `#pexDados`.
 *
 * O explorador (`public/js/pasta-explorador.js`) renderiza SÓ o nível aberto a partir deste JSON;
 * é o que deixa a pasta de produção com 1.128 documentos abrir sem 1.128 linhas e 1.128 modais no
 * HTML. Tudo que a tela mostra ou envia sai daqui: a tela não decide nada e não consulta nada.
 *
 * As contagens recursivas das pastas (subpastas e arquivos da árvore inteira) são calculadas em
 * memória a partir das duas listas — zero consultas a mais, contra as N consultas que
 * `contarConteudoRecursivo()` faria por pasta. Alimentam o aviso de exclusão ("contém 3 subpastas
 * e 127 arquivos"), que precisa do número ANTES do clique.
 *
 * A montagem recebe dois geradores (URL e CSRF) em vez de depender do roteador e do gerenciador de
 * tokens: o DTO continua sem serviço do framework dentro, e a conversão fica testável sem kernel.
 *
 * A forma de UM arquivo ({@see arquivo()}) é pública e estática de propósito: a resposta do upload
 * e a da edição devolvem o documento nessa MESMA forma, e o explorador insere/atualiza a linha
 * sem recarregar (L5) — um só lugar decide o que é "um arquivo" para a tela.
 *
 * As ações em lote (D4) usam UM token por pasta (`pex_lote_<pastaId>`) com os ids no corpo, e a
 * posse é provada no servidor — em vez de três tokens por documento.
 */
final readonly class ExploradorDeDocumentosOutput
{
    /**
     * As categorias que a aba Documentos exibe e edita, com o rótulo da tela — o mesmo mapa que o
     * `PastaController::DOCUMENT_TYPES` (privado) passa ao `montar()`; `CONTRATO` fica de fora de
     * propósito (é da aba Financeiro). Pública para o upload e a edição responderem com o mesmo
     * rótulo que a listagem mostra. `CategoriasDaAbaDocumentosTest` prova que os dois mapas são
     * iguais.
     *
     * @var array<string, string>
     */
    public const CATEGORIAS = [
        PastaDocumento::CATEGORIA_PECA                   => 'Peça',
        PastaDocumento::CATEGORIA_PROCURACAO             => 'Procuração',
        PastaDocumento::CATEGORIA_IDENTIFICACAO          => 'Identificação',
        PastaDocumento::CATEGORIA_COMPROVANTE_RESIDENCIA => 'Comprovante de residência',
        PastaDocumento::CATEGORIA_GRATUIDADE_JUSTICA     => 'Gratuidade de justiça',
        PastaDocumento::CATEGORIA_DEMAIS                 => 'Demais documentos',
    ];

    private const FLAGS_JSON = JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
        | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR;

    /**
     * @param list<array<string, mixed>> $pastas
     * @param list<array<string, mixed>> $arquivos
     * @param array<string, string>      $categorias chave da categoria => rótulo exibido
     */
    private function __construct(
        public array $pastas,
        public array $arquivos,
        public array $categorias,
        public int $totalArquivos,
        public int $totalPastas,
        public string $urlMoverLote,
        public string $urlExcluirLote,
        public string $csrfLote,
    ) {
    }

    /**
     * @param PastaSecao[]                                              $secoes           todas as seções da pasta, em qualquer ordem
     * @param PastaDocumento[]                                          $documentos       todos os documentos da pasta, com a seção já carregada
     * @param array<string, string>                                     $rotulosCategoria chave => rótulo exibido
     * @param callable(string $rota, array<string, mixed> $params): string $url
     * @param callable(string $idDoToken): string                       $csrf
     * @param int                                                       $pastaId          para as URLs e o token das ações em lote
     */
    public static function montar(array $secoes, array $documentos, array $rotulosCategoria, callable $url, callable $csrf, int $pastaId): self
    {
        $filhasPor = [];
        foreach ($secoes as $secao) {
            $filhasPor[$secao->getPai()?->getId() ?? 0][] = (int) $secao->getId();
        }

        $documentosPor = [];
        foreach ($documentos as $documento) {
            $secaoId = $documento->getSecao()?->getId();
            if ($secaoId !== null) {
                $documentosPor[$secaoId] = ($documentosPor[$secaoId] ?? 0) + 1;
            }
        }

        $pastas = [];
        foreach ($secoes as $secao) {
            $id       = (int) $secao->getId();
            $contagem = self::contarArvore($id, $filhasPor, $documentosPor, [$id => true]);

            $pastas[] = [
                'id'           => $id,
                'nome'         => $secao->getNome(),
                'paiId'        => $secao->getPai()?->getId(),
                'ordem'        => $secao->getOrdem(),
                'subpastas'    => $contagem['subpastas'],
                'arquivos'     => $contagem['arquivos'],
                'urlRenomear'  => $url('pasta_secao_renomear', ['secaoId' => $id]),
                'csrfRenomear' => $csrf('pasta_secao_renomear_' . $id),
                'urlExcluir'   => $url('pasta_secao_excluir', ['secaoId' => $id]),
                'csrfExcluir'  => $csrf('pasta_secao_excluir_' . $id),
                'urlMover'     => $url('pasta_secao_mover', ['secaoId' => $id]),
                'csrfMover'    => $csrf('pasta_secao_mover_' . $id),
            ];
        }

        $arquivos = [];
        foreach ($documentos as $documento) {
            $arquivos[] = self::arquivo($documento, $rotulosCategoria, $url, $csrf);
        }

        return new self(
            pastas: $pastas,
            arquivos: $arquivos,
            categorias: $rotulosCategoria,
            totalArquivos: count($arquivos),
            totalPastas: count($pastas),
            urlMoverLote: $url('pasta_documentos_mover_lote', ['id' => $pastaId]),
            urlExcluirLote: $url('pasta_documentos_excluir_lote', ['id' => $pastaId]),
            csrfLote: $csrf(self::idDoTokenDeLote($pastaId)),
        );
    }

    /** O id do token CSRF das ações em lote de uma pasta (mover-lote / excluir-lote). */
    public static function idDoTokenDeLote(int $pastaId): string
    {
        return 'pex_lote_' . $pastaId;
    }

    /**
     * UM arquivo na forma que a tela consome — a mesma na listagem (`#pexDados`), no upload e na
     * edição. Datas em `Y-m-d H:i:s` (a tela formata); `modificadoEm` NULL = nunca editado desde o
     * upload; `enviadoPor` é o nome (nunca o e-mail) ou NULL no acervo anterior à coluna;
     * `paginas` NULL = não é PDF ou não foi contado.
     *
     * @param array<string, string>                                     $rotulosCategoria chave => rótulo exibido
     * @param callable(string $rota, array<string, mixed> $params): string $url
     * @param callable(string $idDoToken): string                       $csrf
     *
     * @return array<string, mixed>
     */
    public static function arquivo(PastaDocumento $documento, array $rotulosCategoria, callable $url, callable $csrf): array
    {
        $id        = (int) $documento->getId();
        $categoria = $documento->getCategoria();

        return [
            'id'              => $id,
            'secaoId'         => $documento->getSecao()?->getId(),
            'nome'            => $documento->getNomeOriginal(),
            'tamanho'         => $documento->getTamanhoBytes(),
            'mime'            => $documento->getMimeType(),
            'carregadoEm'     => $documento->getCarregadoEm()->format('Y-m-d H:i:s'),
            'modificadoEm'    => $documento->getModificadoEm()?->format('Y-m-d H:i:s'),
            'enviadoPor'      => $documento->getEnviadoPor()?->getFullName(),
            'paginas'         => $documento->getPaginas(),
            'ordem'           => $documento->getOrdem(),
            'categoria'       => $categoria,
            // O RÓTULO, não a chave: ordenar pela chave agruparia certo e listaria numa
            // ordem que a tela não exibe — o usuário veria o alfabeto errado.
            'categoriaRotulo' => $rotulosCategoria[$categoria] ?? $categoria,
            'numero'          => $documento->getNumero(),
            'descricao'       => $documento->getDescricao(),
            'sha256'          => $documento->getSha256(),
            'viewUrl'         => $url('pasta_documento_view', ['id' => $id]),
            'downloadUrl'     => $url('pasta_documento_download', ['id' => $id]),
            'urlMover'        => $url('pasta_documento_mover_secao', ['docId' => $id]),
            'csrfMover'       => $csrf('pasta_doc_mover_' . $id),
            'csrfEditar'      => $csrf('edit_documento_' . $id),
            'csrfExcluir'     => $csrf('delete_documento_' . $id),
        ];
    }

    /**
     * O JSON de `#pexDados`. Os HEX_* escapam `<`, `>`, `&`, `'` e `"` como \uXXXX: dentro de um
     * `<script type="application/json">` um nome de arquivo com `</script>` fecharia a tag e
     * executaria o que viesse depois. Com os flags, isso é impossível por construção.
     */
    public function json(): string
    {
        return json_encode([
            'pastas'         => $this->pastas,
            'arquivos'       => $this->arquivos,
            'categorias'     => $this->categorias,
            'totalArquivos'  => $this->totalArquivos,
            'totalPastas'    => $this->totalPastas,
            'urlMoverLote'   => $this->urlMoverLote,
            'urlExcluirLote' => $this->urlExcluirLote,
            'csrfLote'       => $this->csrfLote,
        ], self::FLAGS_JSON);
    }

    /**
     * Subpastas DESCENDENTES (a própria não conta) e arquivos da própria mais os da descendência —
     * os mesmos dois escopos de `PastaSecaoRepository::contarConteudoRecursivo()`, agora sem
     * consulta. `$visitados` carrega o caminho percorrido: um ciclo gravado no banco (o teto de
     * produto é validado nos UseCases, mas o desfazer da auditoria grava o pai direto) vira
     * galho ignorado, não recursão infinita.
     *
     * @param array<int, list<int>> $filhasPor
     * @param array<int, int>       $documentosPor
     * @param array<int, true>      $visitados
     *
     * @return array{subpastas: int, arquivos: int}
     */
    private static function contarArvore(int $id, array $filhasPor, array $documentosPor, array $visitados): array
    {
        $subpastas = 0;
        $arquivos  = $documentosPor[$id] ?? 0;

        foreach ($filhasPor[$id] ?? [] as $filhaId) {
            if (isset($visitados[$filhaId]) || count($visitados) >= PastaSecao::LIMITE_SEGURANCA) {
                continue;
            }
            $daFilha    = self::contarArvore($filhaId, $filhasPor, $documentosPor, $visitados + [$filhaId => true]);
            $subpastas += 1 + $daFilha['subpastas'];
            $arquivos  += $daFilha['arquivos'];
        }

        return ['subpastas' => $subpastas, 'arquivos' => $arquivos];
    }
}
