<?php

declare(strict_types=1);

namespace App\Conector\Ferramenta;

use App\Conector\DTO\ResultadoDaConfirmacao;
use App\Conector\Exception\ErroDaFerramenta;
use App\Pasta\DTO\ExploradorDeDocumentosOutput;
use App\Pasta\DTO\ModeloDePecaInput;
use App\Pasta\Entity\ModeloDePeca;
use App\Pasta\Entity\PastaDocumento;
use App\Pasta\Entity\PastaSecao;
use App\Pasta\Enum\MateriaDeModelo;
use App\Pasta\Enum\TipoDePeca;
use App\Pasta\UseCase\AlternarFavoritoDeModeloUseCase;
use App\Pasta\UseCase\AlternarMateriaFavoritaUseCase;
use App\Pasta\UseCase\DefinirDestinoDaPecaUseCase;
use App\Pasta\UseCase\EditarPecaTextoUseCase;
use App\Pasta\UseCase\ExcluirModeloDePecaUseCase;
use App\Pasta\UseCase\PrepararDadosDaPecaUseCase;
use App\Pasta\UseCase\SalvarModeloDePecaUseCase;
use App\Pasta\UseCase\SalvarPecaTextoUseCase;
use App\Pasta\UseCase\UsarModeloNaPastaUseCase;
use App\Sync\Service\SincronizacaoPastaDispatcher;
use Psr\Clock\ClockInterface;

/**
 * Peças e modelos de peça da `documentos_e_pecas` (6.6), pelos UseCases que a tela usa: `escrever_peca`
 * ({@see SalvarPecaTextoUseCase}), `editar_peca` (por proposta: regrava o HTML na mesma chave, sem versão),
 * `destino_da_peca`, `usar_modelo` (preenchido NO SERVIDOR, 6.2, regra 4: o modelo e os dados da pasta nunca passam pelo
 * app de I.A.; a resposta leva só id e título do documento criado), `salvar_modelo` (novo é direto; sobre um que já
 * existe vai por proposta) e `favoritar_modelo`. O modelo é do escritório, não da pasta.
 */
final class DocumentosEPecasNasPecas implements DocumentosEPecasGrupo
{
    public const MOTIVO_NAO_EDITAVEL = 'peca_nao_editavel';
    public const NAO_EDITAVEL = 'Este documento não é uma peça de texto editável.';

    public function __construct(
        private readonly DocumentosEPecasResolvedor $resolvedor,
        private readonly SalvarPecaTextoUseCase $escrever,
        private readonly EditarPecaTextoUseCase $editarPeca,
        private readonly DefinirDestinoDaPecaUseCase $definirDestino,
        private readonly PrepararDadosDaPecaUseCase $prepararDados,
        private readonly UsarModeloNaPastaUseCase $usarModelo,
        private readonly SalvarModeloDePecaUseCase $salvarModelo,
        private readonly ExcluirModeloDePecaUseCase $excluirModelo,
        private readonly AlternarFavoritoDeModeloUseCase $favoritarModelo,
        private readonly AlternarMateriaFavoritaUseCase $favoritarMateria,
        private readonly SincronizacaoPastaDispatcher $sincronizacao,
        private readonly ClockInterface $relogio,
    ) {
    }

    public function chaves(): array
    {
        return ['escrever_peca', 'editar_peca', 'destino_da_peca', 'usar_modelo', 'salvar_modelo', 'favoritar_modelo'];
    }

    public function conferir(string $acao, array $campos, DocumentosEPecasContexto $contexto): array
    {
        return match ($acao) {
            'escrever_peca.escrever' => $this->destinoDaPecaNova($campos, $contexto),
            'editar_peca.editar' => ['documento' => $this->pecaEditavel($campos['documento_id'], $contexto)],
            'destino_da_peca.definir' => ['documento' => $this->resolvedor->documento($campos['documento_id'], $contexto)],
            'usar_modelo.usar', 'salvar_modelo.excluir' => ['modelo' => $this->resolvedor->modeloDePeca($campos['modelo_id'], $contexto)],
            'salvar_modelo.salvar' => $this->modeloParaSalvar($campos, $contexto),
            default => $this->modeloParaFavoritar($campos, $contexto),
        };
    }

    public function resumir(string $acao, array $campos, array $achados, DocumentosEPecasContexto $contexto): string
    {
        $escritorio = $contexto->escritorio;
        $pasta = $this->resolvedor->nome($escritorio, (string) $contexto->pasta->getNup());

        return match ($acao) {
            'editar_peca.editar' => $this->resumoDaEdicaoDaPeca($campos, $achados['documento'], $pasta, $contexto),
            'salvar_modelo.salvar' => $this->resumoDoModelo($campos, $achados['modelo'], $contexto),
            default => sprintf(
                'Excluir o modelo de peça "%s" do escritório. Vale para todos do escritório e não volta.',
                $this->resolvedor->nome($escritorio, $achados['modelo']->getTitulo()),
            ),
        };
    }

    public function gravar(string $acao, array $campos, array $achados, DocumentosEPecasContexto $contexto): ResultadoDaConfirmacao
    {
        return DocumentosEPecasRecusa::traduzindo(fn (): ResultadoDaConfirmacao => match ($acao) {
            'escrever_peca.escrever' => $this->gravarPeca($campos, $achados, $contexto),
            'editar_peca.editar' => $this->gravarEdicaoDaPeca($campos, $achados['documento'], $contexto),
            'destino_da_peca.definir' => $this->gravarDestino($campos, $achados['documento'], $contexto),
            'usar_modelo.usar' => $this->gravarUsoDoModelo($achados['modelo'], $contexto),
            'salvar_modelo.salvar' => $this->gravarModelo($campos, $achados['modelo'], $contexto),
            'salvar_modelo.excluir' => $this->gravarExclusaoDoModelo($achados['modelo'], $contexto),
            default => $this->gravarFavorito($achados, $contexto),
        });
    }

    /**
     * @param array<string, mixed> $campos
     *
     * @return array{subpasta: ?PastaSecao, categoria: string}
     */
    private function destinoDaPecaNova(array $campos, DocumentosEPecasContexto $contexto): array
    {
        $categoria = strtoupper((string) ($campos['categoria'] ?? PastaDocumento::CATEGORIA_PECA));
        if (!\array_key_exists($categoria, ExploradorDeDocumentosOutput::CATEGORIAS)) {
            throw ErroDaFerramenta::entradaInvalida();
        }

        return ['subpasta' => $this->resolvedor->subpastaOuRaiz($campos['subpasta_id'] ?? null, $contexto), 'categoria' => $categoria];
    }

    /** @throws ErroDaFerramenta */
    private function pecaEditavel(int $documentoId, DocumentosEPecasContexto $contexto): PastaDocumento
    {
        $documento = $this->resolvedor->documento($documentoId, $contexto);
        if ($documento->getMimeType() !== 'text/html') {
            throw new ErroDaFerramenta(self::MOTIVO_NAO_EDITAVEL, self::NAO_EDITAVEL);
        }

        return $documento;
    }

    /**
     * @param array<string, mixed> $campos
     *
     * @return array{modelo: ?ModeloDePeca}
     */
    private function modeloParaSalvar(array $campos, DocumentosEPecasContexto $contexto): array
    {
        if (MateriaDeModelo::tryFrom($campos['materia']) === null || TipoDePeca::tryFrom($campos['tipo']) === null) {
            throw ErroDaFerramenta::entradaInvalida();
        }

        return ['modelo' => isset($campos['modelo_id']) ? $this->resolvedor->modeloDePeca($campos['modelo_id'], $contexto) : null];
    }

    /**
     * @param array<string, mixed> $campos
     *
     * @return array{modelo?: ModeloDePeca, materia?: MateriaDeModelo}
     */
    private function modeloParaFavoritar(array $campos, DocumentosEPecasContexto $contexto): array
    {
        if (isset($campos['modelo_id'])) {
            return ['modelo' => $this->resolvedor->modeloDePeca($campos['modelo_id'], $contexto)];
        }

        return ['materia' => MateriaDeModelo::tryFrom((string) $campos['materia']) ?? throw ErroDaFerramenta::entradaInvalida()];
    }

    /** @param array<string, mixed> $campos */
    private function resumoDaEdicaoDaPeca(array $campos, PastaDocumento $documento, string $pasta, DocumentosEPecasContexto $contexto): string
    {
        $titulo = isset($campos['titulo']) ? sprintf(' e trocar o título para "%s"', mb_substr((string) preg_replace('/\s+/', ' ', (string) $campos['titulo']), 0, 120)) : '';

        return sprintf(
            'Substituir o texto da peça "%s" da pasta %s por um texto novo de %d caracteres%s. O texto atual é perdido: a peça não guarda versão anterior.',
            $this->resolvedor->nome($contexto->escritorio, $documento->getTitulo()),
            $pasta,
            mb_strlen((string) $campos['conteudo']),
            $titulo,
        );
    }

    /** @param array<string, mixed> $campos */
    private function resumoDoModelo(array $campos, ModeloDePeca $modelo, DocumentosEPecasContexto $contexto): string
    {
        return sprintf(
            'Gravar por cima o modelo de peça "%s" do escritório: título "%s", matéria %s, tipo %s e um texto novo de %d caracteres. O texto atual do modelo é perdido e vale para todo o escritório.',
            $this->resolvedor->nome($contexto->escritorio, $modelo->getTitulo()),
            mb_substr((string) preg_replace('/\s+/', ' ', (string) $campos['titulo']), 0, 120),
            (string) $campos['materia'],
            (string) $campos['tipo'],
            mb_strlen((string) $campos['corpo']),
        );
    }

    /**
     * @param array<string, mixed> $campos
     * @param array<string, mixed> $achados
     */
    private function gravarPeca(array $campos, array $achados, DocumentosEPecasContexto $contexto): ResultadoDaConfirmacao
    {
        $documento = $this->escrever->executar(
            $contexto->pasta,
            $achados['subpasta'],
            DocumentosEPecasConteudo::emHtml($campos['conteudo']),
            $campos['titulo'],
            $achados['categoria'],
            $contexto->escritorio,
            $contexto->pessoa,
        );
        // Como a tela: quem tem Drive conectado ganha a peça lá (o despacho nunca lança).
        $this->sincronizacao->despachar($contexto->pasta, $contexto->pessoa, $contexto->escritorio);

        return $this->resultadoDoDocumento($documento, $contexto);
    }

    /** @param array<string, mixed> $campos */
    private function gravarEdicaoDaPeca(array $campos, PastaDocumento $documento, DocumentosEPecasContexto $contexto): ResultadoDaConfirmacao
    {
        $this->editarPeca->executar($documento, DocumentosEPecasConteudo::emHtml($campos['conteudo']), $campos['titulo'] ?? null);

        return $this->resultadoDoDocumento($documento, $contexto);
    }

    /** @param array<string, mixed> $campos */
    private function gravarDestino(array $campos, PastaDocumento $documento, DocumentosEPecasContexto $contexto): ResultadoDaConfirmacao
    {
        $this->definirDestino->executar($documento, $campos['vinculo_id'] ?? null);
        $id = (int) $documento->getId();

        return DocumentosEPecasResultado::de($contexto, ['documento_id' => $id, 'vinculo_id' => $campos['vinculo_id'] ?? null], ['documento' => [$id]]);
    }

    /** O modelo é preenchido aqui, no servidor, com os dados da pasta: a resposta leva só o documento criado. */
    private function gravarUsoDoModelo(ModeloDePeca $modelo, DocumentosEPecasContexto $contexto): ResultadoDaConfirmacao
    {
        $dados = $this->prepararDados->executar($modelo, $contexto->pasta, $contexto->pessoa, $contexto->escritorio, \DateTimeImmutable::createFromInterface($this->relogio->now()));
        $documento = $this->usarModelo->executar($modelo, $contexto->pasta, $contexto->escritorio, $contexto->pessoa, $dados['valores']);

        return $this->resultadoDoDocumento($documento, $contexto, ['modelo' => [(int) $modelo->getId()]]);
    }

    /** @param array<string, mixed> $campos */
    private function gravarModelo(array $campos, ?ModeloDePeca $existente, DocumentosEPecasContexto $contexto): ResultadoDaConfirmacao
    {
        $input = new ModeloDePecaInput($campos['titulo'], $campos['materia'], $campos['tipo'], DocumentosEPecasConteudo::emHtml($campos['corpo']));
        $modelo = $this->salvarModelo->executar($input, $contexto->escritorio, $contexto->pessoa, $existente);
        $id = (int) $modelo->getId();
        $dados = ['modelo' => ['id' => $id, 'titulo' => $this->resolvedor->nome($contexto->escritorio, $modelo->getTitulo())], 'criado' => $existente === null];

        return DocumentosEPecasResultado::de($contexto, $dados, ['modelo' => [$id]]);
    }

    private function gravarExclusaoDoModelo(ModeloDePeca $modelo, DocumentosEPecasContexto $contexto): ResultadoDaConfirmacao
    {
        $id = (int) $modelo->getId();
        $this->excluirModelo->executar($modelo, $contexto->escritorio);

        return DocumentosEPecasResultado::de($contexto, ['modelo_excluido' => $id], ['modelo' => [$id]]);
    }

    /** @param array<string, mixed> $achados */
    private function gravarFavorito(array $achados, DocumentosEPecasContexto $contexto): ResultadoDaConfirmacao
    {
        if (isset($achados['modelo'])) {
            $favorito = $this->favoritarModelo->executar($achados['modelo'], $contexto->pessoa, $contexto->escritorio);

            return DocumentosEPecasResultado::de($contexto, ['modelo_id' => (int) $achados['modelo']->getId(), 'favorito' => $favorito], ['modelo' => [(int) $achados['modelo']->getId()]]);
        }
        $favorita = $this->favoritarMateria->executar($achados['materia'], $contexto->pessoa, $contexto->escritorio);

        return DocumentosEPecasResultado::de($contexto, ['materia' => $achados['materia']->value, 'favorita' => $favorita]);
    }

    /**
     * @param array<string, list<int>> $maisIds
     */
    private function resultadoDoDocumento(PastaDocumento $documento, DocumentosEPecasContexto $contexto, array $maisIds = []): ResultadoDaConfirmacao
    {
        $id = (int) $documento->getId();
        $dados = ['documento' => ['id' => $id, 'titulo' => $this->resolvedor->nome($contexto->escritorio, $documento->getTitulo()), 'categoria' => $documento->getCategoria()]];

        return DocumentosEPecasResultado::de($contexto, $dados, ['documento' => [$id]] + $maisIds);
    }
}
