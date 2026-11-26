<?php

declare(strict_types=1);

namespace App\Conector\Ferramenta;

use App\Conector\DTO\ResultadoDaConfirmacao;
use App\Conector\Exception\ErroDaFerramenta;
use App\Pasta\DTO\EditarDocumentoDaPastaInput;
use App\Pasta\DTO\ExploradorDeDocumentosOutput;
use App\Pasta\Entity\PastaDocumento;
use App\Pasta\Entity\PastaSecao;
use App\Pasta\UseCase\AlternarFavoritoDeDocumentoUseCase;
use App\Pasta\UseCase\CopiarDocumentosDaPastaUseCase;
use App\Pasta\UseCase\CriarPastaSecaoUseCase;
use App\Pasta\UseCase\EditarDocumentoDaPastaUseCase;
use App\Pasta\UseCase\ExcluirItensDaPastaUseCase;
use App\Pasta\UseCase\ExcluirPastaSecaoUseCase;
use App\Pasta\UseCase\MoverItensDaPastaUseCase;
use App\Pasta\UseCase\RenomearPastaSecaoUseCase;
use App\Pasta\UseCase\ReordenarDocumentosUseCase;
use App\Pasta\UseCase\ReordenarSecoesUseCase;
use App\Pasta\UseCase\RestaurarItensDaPastaUseCase;
use App\Shared\Doctrine\Filter\AcessoALixeira;

/**
 * Documentos, subpastas e lixeira da `documentos_e_pecas` (aba Documentos, 6.6), pelos UseCases que a tela usa:
 * `editar_documento` (editar e excluir para a lixeira), `mover`, `copiar`, `favoritar`, `subpasta` (criar, renomear,
 * excluir), `reordenar` e `restaurar` (da lixeira). Por proposta: editar, excluir e
 * renomear subpasta; o resto é direto. Excluir de vez da lixeira mora no {@see DocumentosEPecasNaLixeira} (PR 14).
 *
 * Mover e copiar vão pelo lote da tela (`MoverItensDaPastaUseCase` e `CopiarDocumentosDaPastaUseCase`); subpasta entra
 * no mover pelo campo `subpastas`. Restaurar roda por inteiro dentro do {@see AcessoALixeira}, como a
 * tela: consulta, UseCase e `flush` com a lixeira visível.
 */
final class DocumentosEPecasNosDocumentos implements DocumentosEPecasGrupo
{
    public const AVISO_DA_COPIA = 'As cópias têm o mesmo conteúdo dos originais: a pasta passa a ter estes arquivos repetidos. Para conferir os repetidos, use ler_documentos_e_modelos com acao duplicados.';

    public function __construct(
        private readonly DocumentosEPecasResolvedor $resolvedor,
        private readonly AcessoALixeira $lixeira,
        private readonly EditarDocumentoDaPastaUseCase $editar,
        private readonly MoverItensDaPastaUseCase $mover,
        private readonly CopiarDocumentosDaPastaUseCase $copiar,
        private readonly AlternarFavoritoDeDocumentoUseCase $favoritar,
        private readonly CriarPastaSecaoUseCase $criarSubpasta,
        private readonly RenomearPastaSecaoUseCase $renomearSubpasta,
        private readonly ReordenarDocumentosUseCase $reordenarDocumentos,
        private readonly ReordenarSecoesUseCase $reordenarSubpastas,
        private readonly RestaurarItensDaPastaUseCase $restaurar,
        private readonly ExcluirItensDaPastaUseCase $excluir,
        private readonly ExcluirPastaSecaoUseCase $excluirSubpasta,
    ) {
    }

    public function chaves(): array
    {
        return ['editar_documento', 'mover', 'copiar', 'favoritar', 'subpasta', 'reordenar', 'restaurar'];
    }

    public function conferir(string $acao, array $campos, DocumentosEPecasContexto $contexto): array
    {
        return match ($acao) {
            'editar_documento.editar' => ['documento' => $this->documentoParaEditar($campos, $contexto)],
            'editar_documento.excluir' => ['documentos' => $this->resolvedor->documentos($campos['documentos'], $contexto)],
            'mover.mover', 'copiar.copiar' => $this->selecaoComDestino($campos, $contexto),
            'favoritar.favoritar' => ['alvo' => $this->alvoDoFavorito($campos, $contexto)],
            'subpasta.criar' => ['pai' => $this->resolvedor->subpastaOuRaiz($campos['pai_id'] ?? null, $contexto)],
            'subpasta.renomear', 'subpasta.excluir' => ['subpasta' => $this->resolvedor->subpasta($campos['subpasta_id'], $contexto)],
            'reordenar.de_documentos' => ['documentos' => $this->resolvedor->documentos($campos['ids'], $contexto)],
            'reordenar.de_subpastas' => ['subpastas' => $this->resolvedor->subpastas($campos['ids'], $contexto)],
            default => $this->daLixeira($campos, $contexto),
        };
    }

    public function resumir(string $acao, array $campos, array $achados, DocumentosEPecasContexto $contexto): string
    {
        $escritorio = $contexto->escritorio;
        $pasta = $this->resolvedor->nome($escritorio, (string) $contexto->pasta->getNup());

        return match ($acao) {
            'editar_documento.editar' => $this->resumoDaEdicao($campos, $achados['documento'], $pasta, $contexto),
            'editar_documento.excluir' => sprintf(
                "Mandar para a lixeira da pasta %s %d documento(s): %s.\nDá para restaurar depois pela lixeira.",
                $pasta,
                \count($achados['documentos']),
                $this->resolvedor->nomesDosDocumentos($escritorio, $achados['documentos']),
            ),
            'subpasta.renomear' => sprintf('Renomear a subpasta "%s" da pasta %s para "%s".', $this->resolvedor->nome($escritorio, $achados['subpasta']->getNome()), $pasta, $this->resolvedor->nome($escritorio, $campos['nome'])),
            'subpasta.excluir' => sprintf('Mandar para a lixeira a subpasta "%s" da pasta %s, com tudo o que está dentro dela. Dá para restaurar depois pela lixeira.', $this->resolvedor->nome($escritorio, $achados['subpasta']->getNome()), $pasta),
            default => throw ErroDaFerramenta::entradaInvalida(),
        };
    }

    public function gravar(string $acao, array $campos, array $achados, DocumentosEPecasContexto $contexto): ResultadoDaConfirmacao
    {
        return DocumentosEPecasRecusa::traduzindo(fn (): ResultadoDaConfirmacao => match ($acao) {
            'editar_documento.editar' => $this->gravarEdicao($campos, $achados['documento'], $contexto),
            'editar_documento.excluir' => $this->gravarExclusao($achados['documentos'], $contexto),
            'mover.mover' => $this->gravarMovimento($achados, $contexto),
            'copiar.copiar' => $this->gravarCopia($achados, $contexto),
            'favoritar.favoritar' => $this->gravarFavorito($campos, $achados['alvo'], $contexto),
            'subpasta.criar' => $this->gravarSubpasta($campos, $achados['pai'], $contexto),
            'subpasta.renomear' => $this->gravarNovoNome($campos, $achados['subpasta'], $contexto),
            'subpasta.excluir' => $this->gravarExclusaoDeSubpasta($achados['subpasta'], $contexto),
            'reordenar.de_documentos' => $this->gravarOrdem($campos, $contexto, true),
            'reordenar.de_subpastas' => $this->gravarOrdem($campos, $contexto, false),
            default => $this->gravarNaLixeira($campos, $contexto),
        });
    }

    /** @param array<string, mixed> $campos */
    private function documentoParaEditar(array $campos, DocumentosEPecasContexto $contexto): PastaDocumento
    {
        if (isset($campos['categoria']) && !\array_key_exists(strtoupper((string) $campos['categoria']), ExploradorDeDocumentosOutput::CATEGORIAS)) {
            throw ErroDaFerramenta::entradaInvalida();
        }

        return $this->resolvedor->documento($campos['documento_id'], $contexto);
    }

    /**
     * @param array<string, mixed> $campos
     *
     * @return array{documentos: list<PastaDocumento>, subpastas: list<PastaSecao>, destino: ?PastaSecao}
     */
    private function selecaoComDestino(array $campos, DocumentosEPecasContexto $contexto): array
    {
        return [
            'documentos' => $this->resolvedor->documentos($campos['documentos'] ?? [], $contexto),
            'subpastas' => $this->resolvedor->subpastas($campos['subpastas'] ?? [], $contexto),
            'destino' => $this->resolvedor->subpastaOuRaiz($campos['destino_id'] ?? null, $contexto),
        ];
    }

    /** @param array<string, mixed> $campos */
    private function alvoDoFavorito(array $campos, DocumentosEPecasContexto $contexto): PastaDocumento|PastaSecao
    {
        return $campos['item'] === 'documento'
            ? $this->resolvedor->documento($campos['alvo_id'], $contexto)
            : $this->resolvedor->subpasta($campos['alvo_id'], $contexto);
    }

    /**
     * Restaurar: os itens têm de estar NA LIXEIRA da pasta. Quem confere roda com a lixeira visível.
     *
     * @param array<string, mixed> $campos
     *
     * @return array{documentos: list<PastaDocumento>, subpastas: list<PastaSecao>}
     */
    private function daLixeira(array $campos, DocumentosEPecasContexto $contexto): array
    {
        return $this->lixeira->comLixeiraVisivel(function () use ($campos, $contexto): array {
            $documentos = $this->resolvedor->documentos($campos['documentos'] ?? [], $contexto, true);
            $subpastas = $this->resolvedor->subpastas($campos['subpastas'] ?? [], $contexto, true);

            return ['documentos' => $documentos, 'subpastas' => $subpastas];
        });
    }

    /**
     * @param array<string, mixed> $campos
     */
    private function resumoDaEdicao(array $campos, PastaDocumento $documento, string $pasta, DocumentosEPecasContexto $contexto): string
    {
        $partes = [];
        foreach (['categoria' => 'categoria', 'descricao' => 'descrição', 'numero' => 'número', 'nome_base' => 'nome do arquivo'] as $campo => $rotulo) {
            if (\array_key_exists($campo, $campos)) {
                $valor = mb_substr((string) preg_replace('/\s+/', ' ', (string) $campos[$campo]), 0, 120);
                $partes[] = $valor === '' ? sprintf('limpar %s', $rotulo) : sprintf('%s para "%s"', $rotulo, $valor);
            }
        }

        return sprintf(
            'Alterar o documento "%s" da pasta %s: %s. Os valores atuais desses campos serão trocados.',
            $this->resolvedor->nome($contexto->escritorio, $documento->getNomeOriginal()),
            $pasta,
            implode('; ', $partes),
        );
    }

    /** @param array<string, mixed> $campos */
    private function gravarEdicao(array $campos, PastaDocumento $documento, DocumentosEPecasContexto $contexto): ResultadoDaConfirmacao
    {
        // O UseCase troca TODOS os campos pelo que recebe: o que a chamada não mandou volta como está.
        $input = new EditarDocumentoDaPastaInput(
            categoria: $campos['categoria'] ?? $documento->getCategoria(),
            descricao: $campos['descricao'] ?? $documento->getDescricao(),
            numero: $campos['numero'] ?? $documento->getNumero(),
            nomeBase: $campos['nome_base'] ?? null,
        );
        $this->editar->executar($documento, $contexto->pessoa, $contexto->escritorio, $input);
        $id = (int) $documento->getId();

        return DocumentosEPecasResultado::de($contexto, ['documento' => ['id' => $id, 'nome' => $this->resolvedor->nome($contexto->escritorio, $documento->getNomeOriginal())]], ['documento' => [$id]]);
    }

    /** @param list<PastaDocumento> $documentos */
    private function gravarExclusao(array $documentos, DocumentosEPecasContexto $contexto): ResultadoDaConfirmacao
    {
        $r = $this->excluir->executar($contexto->pasta, $documentos, [], $contexto->pessoa, $contexto->escritorio);
        $dados = ['para_a_lixeira' => ['documentos' => $r->documentosRemovidos, 'arquivos' => $r->arquivosRemovidos], 'ids' => $r->idsDocumentos];

        return DocumentosEPecasResultado::de($contexto, $dados, ['documento' => $r->idsDocumentos], ['documentos' => $r->documentosRemovidos]);
    }

    /** @param array<string, mixed> $achados */
    private function gravarMovimento(array $achados, DocumentosEPecasContexto $contexto): ResultadoDaConfirmacao
    {
        $r = $this->mover->executar($contexto->pasta, $achados['documentos'], $achados['subpastas'], $achados['destino'], $contexto->pessoa, $contexto->escritorio);
        $dados = ['movidos' => ['documentos' => $r->documentos, 'subpastas' => $r->secoes], 'destino_id' => $r->destinoId];

        return DocumentosEPecasResultado::de($contexto, $dados, [], ['documentos' => $r->documentos, 'subpastas' => $r->secoes]);
    }

    /** @param array<string, mixed> $achados */
    private function gravarCopia(array $achados, DocumentosEPecasContexto $contexto): ResultadoDaConfirmacao
    {
        $r = $this->copiar->executar($contexto->pasta, $achados['documentos'], $achados['destino'], $contexto->pessoa, $contexto->escritorio);
        $copias = [];
        foreach ($r->copias as $copia) {
            $copias[] = ['id' => (int) $copia->getId(), 'nome' => $this->resolvedor->nome($contexto->escritorio, $copia->getNomeOriginal())];
        }

        // A cópia fica na mesma pasta e tem o mesmo conteúdo do original: é um repetido por definição (M9 de 26/11, aviso
        // na carga, não bloqueio). O aviso diz isso e aponta o relatório, para o app de I.A. não copiar à toa.
        $dados = ['copias' => $copias];
        if ($copias !== []) {
            $dados['aviso'] = self::AVISO_DA_COPIA;
        }

        return DocumentosEPecasResultado::de($contexto, $dados, ['documento' => array_column($copias, 'id')], ['copias' => \count($copias)]);
    }

    /** @param array<string, mixed> $campos */
    private function gravarFavorito(array $campos, PastaDocumento|PastaSecao $alvo, DocumentosEPecasContexto $contexto): ResultadoDaConfirmacao
    {
        $marcado = $this->favoritar->executar($contexto->pasta, $alvo, $campos['marcado'], $contexto->pessoa, $contexto->escritorio);
        $tipo = $alvo instanceof PastaDocumento ? 'documento' : 'subpasta';

        return DocumentosEPecasResultado::de($contexto, ['item' => $tipo, 'alvo_id' => (int) $alvo->getId(), 'marcado' => $marcado], [$tipo => [(int) $alvo->getId()]]);
    }

    /** @param array<string, mixed> $campos */
    private function gravarSubpasta(array $campos, ?PastaSecao $pai, DocumentosEPecasContexto $contexto): ResultadoDaConfirmacao
    {
        $subpasta = $this->criarSubpasta->executar($contexto->pasta, $contexto->pessoa, $campos['nome'], $contexto->escritorio, $pai);
        $id = (int) $subpasta->getId();

        return DocumentosEPecasResultado::de($contexto, ['subpasta' => ['id' => $id, 'nome' => $this->resolvedor->nome($contexto->escritorio, $subpasta->getNome())]], ['subpasta' => [$id]]);
    }

    /** @param array<string, mixed> $campos */
    private function gravarNovoNome(array $campos, PastaSecao $subpasta, DocumentosEPecasContexto $contexto): ResultadoDaConfirmacao
    {
        $this->renomearSubpasta->executar($subpasta, $contexto->pessoa, $campos['nome'], $contexto->escritorio);
        $id = (int) $subpasta->getId();

        return DocumentosEPecasResultado::de($contexto, ['subpasta' => ['id' => $id, 'nome' => $this->resolvedor->nome($contexto->escritorio, $subpasta->getNome())]], ['subpasta' => [$id]]);
    }

    private function gravarExclusaoDeSubpasta(PastaSecao $subpasta, DocumentosEPecasContexto $contexto): ResultadoDaConfirmacao
    {
        $r = $this->excluirSubpasta->executar($subpasta, $contexto->pessoa, $contexto->escritorio);
        $dados = ['para_a_lixeira' => ['subpastas' => $r->subpastasRemovidas, 'documentos' => $r->documentosRemovidos, 'arquivos' => $r->arquivosRemovidos]];

        return DocumentosEPecasResultado::de($contexto, $dados, ['subpasta' => $r->idsSecoes, 'documento' => $r->idsDocumentos], ['subpastas' => $r->subpastasRemovidas, 'documentos' => $r->documentosRemovidos]);
    }

    /** @param array<string, mixed> $campos */
    private function gravarOrdem(array $campos, DocumentosEPecasContexto $contexto, bool $documentos): ResultadoDaConfirmacao
    {
        $ids = $campos['ids'];
        if ($documentos) {
            $this->reordenarDocumentos->executar($contexto->pasta, $contexto->escritorio, $ids);
        } else {
            $this->reordenarSubpastas->executar($contexto->pasta, $contexto->escritorio, $ids);
        }

        return DocumentosEPecasResultado::de($contexto, ['reordenados' => \count($ids)], [], ['itens' => \count($ids)]);
    }

    /** @param array<string, mixed> $campos */
    private function gravarNaLixeira(array $campos, DocumentosEPecasContexto $contexto): ResultadoDaConfirmacao
    {
        return $this->lixeira->comLixeiraVisivel(function () use ($campos, $contexto): ResultadoDaConfirmacao {
            $itens = $this->daLixeira($campos, $contexto);
            $ids = [
                'documento' => array_map(static fn (PastaDocumento $d): int => (int) $d->getId(), $itens['documentos']),
                'subpasta' => array_map(static fn (PastaSecao $s): int => (int) $s->getId(), $itens['subpastas']),
            ];

            return $this->restaurarDaLixeira($itens, $ids, $contexto);
        });
    }

    /**
     * @param array{documentos: list<PastaDocumento>, subpastas: list<PastaSecao>} $itens
     * @param array<string, list<int>>                                            $ids
     */
    private function restaurarDaLixeira(array $itens, array $ids, DocumentosEPecasContexto $contexto): ResultadoDaConfirmacao
    {
        $r = $this->restaurar->executar($contexto->pasta, $itens['documentos'], $itens['subpastas'], $contexto->pessoa, $contexto->escritorio);
        $dados = ['restaurados' => ['documentos' => $r->documentos, 'subpastas' => $r->secoes], 'para_a_raiz' => $r->paraARaiz];

        return DocumentosEPecasResultado::de($contexto, $dados, $ids, ['documentos' => $r->documentos, 'subpastas' => $r->secoes]);
    }
}
