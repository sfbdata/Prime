<?php

declare(strict_types=1);

namespace App\Conector\Ferramenta;

use App\ChatIa\Service\ProtecaoDoTextoDoChat;
use App\Conector\Exception\ErroDaFerramenta;
use App\Entity\Tenant\Tenant;
use App\Pasta\Entity\ModeloDePeca;
use App\Pasta\Entity\PastaChecklistItem;
use App\Pasta\Entity\PastaChecklistModelo;
use App\Pasta\Entity\PastaDocumento;
use App\Pasta\Entity\PastaSecao;
use App\Pasta\Repository\ModeloDePecaRepository;
use App\Pasta\Repository\PastaChecklistItemRepository;
use App\Pasta\Repository\PastaChecklistModeloRepository;
use App\Pasta\Repository\PastaDocumentoRepository;
use App\Pasta\Repository\PastaSecaoRepository;
use App\Pasta\Service\VisibilidadeDasAbasDaPasta;

/**
 * Acha, por id e escritório, o que a `documentos_e_pecas` vai tocar, e nada além: documento e subpasta têm de ser DA PASTA
 * da chamada, o checklist é o dela e o modelo é do escritório. Tudo o que não bate (inexistente, de outra pasta, de outro
 * escritório, na lixeira quando devia estar viva ou o contrário, contrato de honorários com o Financeiro oculto) dá a
 * mesma resposta "não encontrado" (6.2, regra 1). É a segunda barreira: os UseCases também conferem o escritório.
 *
 * Também dá o nome curto, neutralizado e mascarado, que vai para resumos e respostas ({@see nome()}, 6.2, regra 6).
 */
final class DocumentosEPecasResolvedor
{
    public const TAMANHO_DO_NOME = 150;

    public function __construct(
        private readonly PastaDocumentoRepository $documentos,
        private readonly PastaSecaoRepository $secoes,
        private readonly PastaChecklistItemRepository $itens,
        private readonly PastaChecklistModeloRepository $modelosDeChecklist,
        private readonly ModeloDePecaRepository $modelosDePeca,
        private readonly ProtecaoDoTextoDoChat $protecao,
    ) {
    }

    /**
     * Os documentos da pasta, todos ou nenhum. Com `$naLixeira` (dentro do `AcessoALixeira`), só os que estão nela.
     *
     * @param list<int> $ids
     *
     * @return list<PastaDocumento>
     *
     * @throws ErroDaFerramenta
     */
    public function documentos(array $ids, DocumentosEPecasContexto $contexto, bool $naLixeira = false): array
    {
        if ($ids === []) {
            return [];
        }
        $achados = $naLixeira
            ? $this->documentos->findNaLixeiraDaPasta($ids, $contexto->pasta, $contexto->escritorio)
            : $this->documentos->findTodosDaPasta($ids, $contexto->pasta, $contexto->escritorio);
        if (\count($achados) !== \count($ids)) {
            throw ErroDaFerramenta::naoEncontrado();
        }
        foreach ($achados as $documento) {
            if ($documento->estaNaLixeira() !== $naLixeira || !VisibilidadeDasAbasDaPasta::documentoVisivel($documento->getCategoria(), $contexto->abasOcultas)) {
                throw ErroDaFerramenta::naoEncontrado();
            }
        }

        return $achados;
    }

    /** @throws ErroDaFerramenta */
    public function documento(int $id, DocumentosEPecasContexto $contexto): PastaDocumento
    {
        return $this->documentos([$id], $contexto)[0];
    }

    /**
     * @param list<int> $ids
     *
     * @return list<PastaSecao>
     *
     * @throws ErroDaFerramenta
     */
    public function subpastas(array $ids, DocumentosEPecasContexto $contexto, bool $naLixeira = false): array
    {
        if ($ids === []) {
            return [];
        }
        $achadas = $naLixeira
            ? $this->secoes->findNaLixeiraDaPasta($ids, $contexto->pasta, $contexto->escritorio)
            : $this->secoes->findTodasDaPasta($ids, $contexto->pasta, $contexto->escritorio);
        if (\count($achadas) !== \count($ids)) {
            throw ErroDaFerramenta::naoEncontrado();
        }
        foreach ($achadas as $subpasta) {
            if ($subpasta->estaNaLixeira() !== $naLixeira) {
                throw ErroDaFerramenta::naoEncontrado();
            }
        }

        return $achadas;
    }

    /** @throws ErroDaFerramenta */
    public function subpasta(int $id, DocumentosEPecasContexto $contexto): PastaSecao
    {
        return $this->subpastas([$id], $contexto)[0];
    }

    /**
     * A subpasta de destino ou de origem opcional: sem id, é a raiz da pasta (null).
     *
     * @throws ErroDaFerramenta
     */
    public function subpastaOuRaiz(mixed $id, DocumentosEPecasContexto $contexto): ?PastaSecao
    {
        return \is_int($id) ? $this->subpasta($id, $contexto) : null;
    }

    /** @throws ErroDaFerramenta */
    public function itemDoChecklist(int $id, DocumentosEPecasContexto $contexto): PastaChecklistItem
    {
        return $this->itens->findOneBy(['id' => $id, 'pasta' => $contexto->pasta, 'tenant' => $contexto->escritorio]) ?? throw ErroDaFerramenta::naoEncontrado();
    }

    /**
     * Todos os itens do checklist da pasta, para conferir uma lista de ids (reordenar).
     *
     * @return list<PastaChecklistItem>
     */
    public function itensDoChecklist(DocumentosEPecasContexto $contexto): array
    {
        return $this->itens->findByPasta($contexto->pasta, $contexto->escritorio);
    }

    /** @throws ErroDaFerramenta */
    public function modeloDeChecklist(int $id, DocumentosEPecasContexto $contexto): PastaChecklistModelo
    {
        return $this->modelosDeChecklist->buscarDoTenant($id, $contexto->escritorio) ?? throw ErroDaFerramenta::naoEncontrado();
    }

    /** @throws ErroDaFerramenta */
    public function modeloDePeca(int $id, DocumentosEPecasContexto $contexto): ModeloDePeca
    {
        return $this->modelosDePeca->buscarDoTenant($id, $contexto->escritorio) ?? throw ErroDaFerramenta::naoEncontrado();
    }

    /** Um nome de uma linha, neutralizado, mascarado e cortado. */
    public function nome(Tenant $escritorio, string $texto): string
    {
        $valor = $this->protecao->rotulo($escritorio, $texto, 'sem nome');

        return mb_strlen($valor) > self::TAMANHO_DO_NOME ? mb_substr($valor, 0, self::TAMANHO_DO_NOME) . '…' : $valor;
    }

    /**
     * Os nomes dos documentos para o resumo: `"a.pdf", "b.pdf"`.
     *
     * @param list<PastaDocumento> $documentos
     */
    public function nomesDosDocumentos(Tenant $escritorio, array $documentos): string
    {
        return implode(', ', array_map(fn (PastaDocumento $d): string => '"' . $this->nome($escritorio, $d->getNomeOriginal()) . '"', $documentos));
    }

    /**
     * Os nomes das subpastas para o resumo.
     *
     * @param list<PastaSecao> $subpastas
     */
    public function nomesDasSubpastas(Tenant $escritorio, array $subpastas): string
    {
        return implode(', ', array_map(fn (PastaSecao $s): string => '"' . $this->nome($escritorio, $s->getNome()) . '"', $subpastas));
    }
}
