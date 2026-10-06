<?php

declare(strict_types=1);

namespace App\Pasta\DTO;

use App\Pasta\Entity\PastaDocumento;
use App\Pasta\Entity\PastaSecao;
use App\Pasta\Service\SugestoesDeLimpeza;

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
 *
 * Favoritos (D2, DOC-23): cada arquivo e cada pasta traz `favorito` — a estrela DO USUÁRIO
 * LOGADO, nunca a de um colega —, e o topo traz `urlFavorito`/`csrfFavorito` (um token por pasta,
 * `pex_favorito_<pastaId>`, com tipo e id do alvo no corpo).
 *
 * Duplicados e limpeza (L9, DOC-62..66), POR NÍVEL como o desenho: cada arquivo traz `identicoA`
 * (id de um arquivo com o MESMO sha256 na MESMA seção, ou NULL), `nomeParecidoCom` (`{id,
 * percentual}` do nome ≥ 85% parecido na mesma seção, ou NULL — só informa), `regraLimpeza` (a
 * regra que sugere excluí-lo, ou NULL) e `doDrive` (veio do Drive: 0 byte aí não é "vazio"); o
 * topo traz `limpeza`, as sugestões da RAIZ por regra (`[{regra, ids, bytes, rotulo}]`, ver
 * {@see SugestoesDeLimpeza}). Tudo calculado em memória sobre as listas que já estão aqui:
 * nenhuma consulta a mais. O upload e a edição (`arquivo()` sozinho) não sabem dos outros
 * arquivos e devolvem os três campos calculados NULL — a tela preserva os que já tinha.
 *
 * Lixeira (L7, D7): o topo traz `urlRestaurar` (POST, o MESMO token do lote, `csrfLote`) — o
 * "Desfazer" do toast e o Restaurar do modal — e `urlLixeira` (GET, JSON) — a lista da lixeira.
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
     * @param list<array{regra: string, ids: list<int>, bytes: int, rotulo: string}> $limpeza
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
        public string $urlFavorito,
        public string $csrfFavorito,
        public array $limpeza = [],
        public string $urlRestaurar = '',
        public string $urlLixeira = '',
    ) {
    }

    /**
     * @param PastaSecao[]                                              $secoes           todas as seções da pasta, em qualquer ordem
     * @param PastaDocumento[]                                          $documentos       todos os documentos da pasta, com a seção já carregada
     * @param array<string, string>                                     $rotulosCategoria chave => rótulo exibido
     * @param callable(string $rota, array<string, mixed> $params): string $url
     * @param callable(string $idDoToken): string                       $csrf
     * @param int                                                       $pastaId          para as URLs e o token das ações em lote
     * @param array{documentos?: array<int, string|true>, secoes?: array<int, string|true>} $favoritos  os do usuário logado, id => hora em que marcou (`PastaDocumentoFavoritoRepository::idsFavoritosDaPasta`); `true` = favorito sem hora conhecida
     */
    public static function montar(array $secoes, array $documentos, array $rotulosCategoria, callable $url, callable $csrf, int $pastaId, array $favoritos = []): self
    {
        $documentosFavoritos = $favoritos['documentos'] ?? [];
        $secoesFavoritas     = $favoritos['secoes'] ?? [];

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
                'favorito'     => isset($secoesFavoritas[$id]),
                'favoritoEm'   => is_string($secoesFavoritas[$id] ?? null) ? $secoesFavoritas[$id] : null,
            ];
        }

        $limpeza = SugestoesDeLimpeza::avaliar(array_map(static fn (PastaDocumento $d): array => [
            'id'          => (int) $d->getId(),
            'secaoId'     => $d->getSecao()?->getId(),
            'nome'        => $d->getNomeOriginal(),
            'tamanho'     => $d->getTamanhoBytes(),
            'sha256'      => $d->getSha256(),
            'paginas'     => $d->getPaginas(),
            'carregadoEm' => $d->getCarregadoEm()->format('Y-m-d H:i:s'),
            'doDrive'     => $d->getDriveFileId() !== null,
        ], array_values($documentos)));

        $arquivos = [];
        foreach ($documentos as $documento) {
            $id         = (int) $documento->getId();
            $favoritoEm = $documentosFavoritos[$id] ?? null;
            $arquivo    = self::arquivo($documento, $rotulosCategoria, $url, $csrf, $favoritoEm !== null, is_string($favoritoEm) ? $favoritoEm : null);
            $arquivo['identicoA']       = $limpeza->identicoA[$id] ?? null;
            $arquivo['nomeParecidoCom'] = $limpeza->nomeParecidoCom[$id] ?? null;
            $arquivo['regraLimpeza']    = $limpeza->regraDe[$id] ?? null;
            $arquivos[] = $arquivo;
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
            urlFavorito: $url('pasta_documentos_favorito', ['id' => $pastaId]),
            csrfFavorito: $csrf(self::idDoTokenDeFavorito($pastaId)),
            limpeza: $limpeza->grupos,
            urlRestaurar: $url('pasta_documentos_restaurar', ['id' => $pastaId]),
            urlLixeira: $url('pasta_documentos_lixeira', ['id' => $pastaId]),
        );
    }

    /** O id do token CSRF das ações em lote de uma pasta (mover-lote / excluir-lote). */
    public static function idDoTokenDeLote(int $pastaId): string
    {
        return 'pex_lote_' . $pastaId;
    }

    /** O id do token CSRF da estrela (favorito) de arquivo/subpasta de uma pasta. */
    public static function idDoTokenDeFavorito(int $pastaId): string
    {
        return 'pex_favorito_' . $pastaId;
    }

    /**
     * UM arquivo na forma que a tela consome — a mesma na listagem (`#pexDados`), no upload e na
     * edição. Datas em `Y-m-d H:i:s` (a tela formata); `modificadoEm` NULL = nunca editado desde o
     * upload; `enviadoPor` é o nome (nunca o e-mail) ou NULL no acervo anterior à coluna;
     * `paginas` NULL = não é PDF ou não foi contado. `favorito` é a estrela do usuário logado: quem
     * não sabe (o upload — documento recém-criado nunca é favorito) deixa o padrão `false`.
     * `favoritoEm` é a hora em que ele marcou (ordena o topo, dc L4440); NULL sem favorito.
     * `identicoA`/`nomeParecidoCom`/`regraLimpeza` dependem dos OUTROS arquivos da pasta: aqui
     * nascem NULL e só o `montar()` os preenche (L9). `doDrive` é do próprio documento.
     *
     * @param array<string, string>                                     $rotulosCategoria chave => rótulo exibido
     * @param callable(string $rota, array<string, mixed> $params): string $url
     * @param callable(string $idDoToken): string                       $csrf
     *
     * @return array<string, mixed>
     */
    public static function arquivo(PastaDocumento $documento, array $rotulosCategoria, callable $url, callable $csrf, bool $favorito = false, ?string $favoritoEm = null): array
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
            'favorito'        => $favorito,
            'favoritoEm'      => $favorito ? $favoritoEm : null,
            'doDrive'         => $documento->getDriveFileId() !== null,
            'identicoA'       => null,
            'nomeParecidoCom' => null,
            'regraLimpeza'    => null,
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
            'urlFavorito'    => $this->urlFavorito,
            'csrfFavorito'   => $this->csrfFavorito,
            'limpeza'        => $this->limpeza,
            'urlRestaurar'   => $this->urlRestaurar,
            'urlLixeira'     => $this->urlLixeira,
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
