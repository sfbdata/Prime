<?php

declare(strict_types=1);

namespace App\Conector\Ferramenta;

use App\Conector\DTO\ResultadoDaConfirmacao;
use App\Conector\Exception\ErroDaFerramenta;
use App\Pasta\Entity\MotivoDesativacaoChecklist;
use App\Pasta\Entity\PastaChecklistItem;
use App\Pasta\Entity\PastaChecklistModelo;
use App\Pasta\UseCase\AdicionarChecklistItemUseCase;
use App\Pasta\UseCase\AlterarEstadoDoChecklistUseCase;
use App\Pasta\UseCase\AplicarChecklistModeloUseCase;
use App\Pasta\UseCase\EditarChecklistItemUseCase;
use App\Pasta\UseCase\ExcluirChecklistItemUseCase;
use App\Pasta\UseCase\ExcluirChecklistModeloUseCase;
use App\Pasta\UseCase\RenomearChecklistModeloUseCase;
use App\Pasta\UseCase\ReordenarChecklistItensUseCase;
use App\Pasta\UseCase\SalvarChecklistComoModeloUseCase;
use App\Pasta\UseCase\ToggleChecklistItemUseCase;

/**
 * O checklist de documentação da `documentos_e_pecas` (aba Documentos, 6.6): `checklist_item` (adicionar, marcar,
 * reordenar direto; editar o texto e excluir por proposta), `checklist_estado` (ligar ou desligar com motivo) e
 * `checklist_modelo` (salvar como modelo e aplicar direto; renomear e excluir por proposta). O item é da pasta da
 * chamada; o modelo de checklist é do escritório.
 *
 * Marcar recebe o estado final (`concluido`), não "alterna": chamar duas vezes não desfaz. Salvar como modelo nunca
 * substitui um que já existe (a tela pergunta antes; aqui o nome repetido é recusado, e trocar os itens de um modelo
 * existente seria gravar por cima).
 */
final class DocumentosEPecasNoChecklist implements DocumentosEPecasGrupo
{
    public function __construct(
        private readonly DocumentosEPecasResolvedor $resolvedor,
        private readonly AdicionarChecklistItemUseCase $adicionar,
        private readonly ToggleChecklistItemUseCase $marcar,
        private readonly EditarChecklistItemUseCase $editar,
        private readonly ReordenarChecklistItensUseCase $reordenar,
        private readonly ExcluirChecklistItemUseCase $excluir,
        private readonly AlterarEstadoDoChecklistUseCase $alterarEstado,
        private readonly SalvarChecklistComoModeloUseCase $salvarModelo,
        private readonly AplicarChecklistModeloUseCase $aplicarModelo,
        private readonly RenomearChecklistModeloUseCase $renomearModelo,
        private readonly ExcluirChecklistModeloUseCase $excluirModelo,
    ) {
    }

    public function chaves(): array
    {
        return ['checklist_item', 'checklist_estado', 'checklist_modelo'];
    }

    public function conferir(string $acao, array $campos, DocumentosEPecasContexto $contexto): array
    {
        return match ($acao) {
            'checklist_item.adicionar', 'checklist_modelo.salvar' => [],
            'checklist_estado.alterar' => $this->conferirEstado($campos),
            'checklist_item.marcar', 'checklist_item.editar', 'checklist_item.excluir' => ['item' => $this->resolvedor->itemDoChecklist($campos['item_id'], $contexto)],
            'checklist_item.reordenar' => $this->conferirLista($campos['ids'], $contexto),
            default => ['modelo' => $this->resolvedor->modeloDeChecklist($campos['modelo_checklist_id'], $contexto)],
        };
    }

    public function resumir(string $acao, array $campos, array $achados, DocumentosEPecasContexto $contexto): string
    {
        $escritorio = $contexto->escritorio;
        $pasta = $this->resolvedor->nome($escritorio, (string) $contexto->pasta->getNup());

        return match ($acao) {
            'checklist_item.editar' => sprintf(
                'Trocar o texto do item do checklist da pasta %s de "%s" para "%s".',
                $pasta,
                $this->resolvedor->nome($escritorio, $achados['item']->getTitulo()),
                $this->resolvedor->nome($escritorio, $campos['titulo']),
            ),
            'checklist_item.excluir' => sprintf('Excluir o item "%s" do checklist da pasta %s.', $this->resolvedor->nome($escritorio, $achados['item']->getTitulo()), $pasta),
            'checklist_modelo.renomear' => sprintf(
                'Renomear o modelo de checklist "%s" para "%s". Vale para todo o escritório.',
                $this->resolvedor->nome($escritorio, $achados['modelo']->getNome()),
                $this->resolvedor->nome($escritorio, $campos['nome']),
            ),
            default => sprintf(
                'Excluir o modelo de checklist "%s" (%d itens) do escritório. Vale para todos do escritório e não volta.',
                $this->resolvedor->nome($escritorio, $achados['modelo']->getNome()),
                $achados['modelo']->totalItens(),
            ),
        };
    }

    public function gravar(string $acao, array $campos, array $achados, DocumentosEPecasContexto $contexto): ResultadoDaConfirmacao
    {
        return DocumentosEPecasRecusa::traduzindo(fn (): ResultadoDaConfirmacao => match ($acao) {
            'checklist_item.adicionar' => $this->gravarItemNovo($campos, $contexto),
            'checklist_item.marcar' => $this->gravarMarca($campos, $achados['item'], $contexto),
            'checklist_item.reordenar' => $this->gravarOrdem($campos, $contexto),
            'checklist_item.editar' => $this->gravarTexto($campos, $achados['item'], $contexto),
            'checklist_item.excluir' => $this->gravarExclusaoDoItem($achados['item'], $contexto),
            'checklist_estado.alterar' => $this->gravarEstado($campos, $contexto),
            'checklist_modelo.salvar' => $this->gravarModeloNovo($campos, $contexto),
            'checklist_modelo.aplicar' => $this->gravarAplicacao($achados['modelo'], $contexto),
            'checklist_modelo.renomear' => $this->gravarNovoNomeDoModelo($campos, $achados['modelo'], $contexto),
            default => $this->gravarExclusaoDoModelo($achados['modelo'], $contexto),
        });
    }

    /**
     * Desligar pede um dos motivos da tela; ligar não pede nada.
     *
     * @param array<string, mixed> $campos
     *
     * @return array<string, mixed>
     */
    private function conferirEstado(array $campos): array
    {
        if ($campos['ativo'] === false && MotivoDesativacaoChecklist::tryFrom((string) ($campos['motivo'] ?? '')) === null) {
            throw ErroDaFerramenta::entradaInvalida();
        }

        return [];
    }

    /**
     * Todos os ids da lista são itens do checklist desta pasta (o UseCase ignoraria em silêncio os que não são).
     *
     * @param list<int> $ids
     *
     * @return array<string, mixed>
     */
    private function conferirLista(array $ids, DocumentosEPecasContexto $contexto): array
    {
        $daPasta = array_map(static fn (PastaChecklistItem $i): int => (int) $i->getId(), $this->resolvedor->itensDoChecklist($contexto));
        if (array_diff($ids, $daPasta) !== []) {
            throw ErroDaFerramenta::naoEncontrado();
        }

        return [];
    }

    /** @param array<string, mixed> $campos */
    private function gravarItemNovo(array $campos, DocumentosEPecasContexto $contexto): ResultadoDaConfirmacao
    {
        $item = $this->adicionar->executar($contexto->pasta, $contexto->pessoa, $campos['titulo'], $contexto->escritorio);

        return $this->resultadoDoItem($item, $contexto);
    }

    /** @param array<string, mixed> $campos */
    private function gravarMarca(array $campos, PastaChecklistItem $item, DocumentosEPecasContexto $contexto): ResultadoDaConfirmacao
    {
        if ($item->isConcluido() !== $campos['concluido']) {
            $this->marcar->executar($item, $contexto->pessoa);
        }

        return $this->resultadoDoItem($item, $contexto);
    }

    /** @param array<string, mixed> $campos */
    private function gravarOrdem(array $campos, DocumentosEPecasContexto $contexto): ResultadoDaConfirmacao
    {
        $this->reordenar->executar($contexto->pasta, $contexto->escritorio, $campos['ids']);

        return DocumentosEPecasResultado::de($contexto, ['reordenados' => \count($campos['ids'])], ['item' => $campos['ids']], ['itens' => \count($campos['ids'])]);
    }

    /** @param array<string, mixed> $campos */
    private function gravarTexto(array $campos, PastaChecklistItem $item, DocumentosEPecasContexto $contexto): ResultadoDaConfirmacao
    {
        $this->editar->executar($item, $campos['titulo']);

        return $this->resultadoDoItem($item, $contexto);
    }

    private function gravarExclusaoDoItem(PastaChecklistItem $item, DocumentosEPecasContexto $contexto): ResultadoDaConfirmacao
    {
        $id = (int) $item->getId();
        $this->excluir->executar($item);

        return DocumentosEPecasResultado::de($contexto, ['item_excluido' => $id], ['item' => [$id]]);
    }

    /** @param array<string, mixed> $campos */
    private function gravarEstado(array $campos, DocumentosEPecasContexto $contexto): ResultadoDaConfirmacao
    {
        $mudou = $this->alterarEstado->executar($contexto->pasta, $campos['ativo'], $campos['motivo'] ?? null, $contexto->pessoa, $contexto->escritorio);

        return DocumentosEPecasResultado::de($contexto, ['ativo' => $contexto->pasta->isChecklistAtivo(), 'mudou' => $mudou]);
    }

    /** @param array<string, mixed> $campos */
    private function gravarModeloNovo(array $campos, DocumentosEPecasContexto $contexto): ResultadoDaConfirmacao
    {
        $modelo = $this->salvarModelo->executar($contexto->pasta, $contexto->pessoa, $campos['nome'], $contexto->escritorio);

        return $this->resultadoDoModelo($modelo, $contexto);
    }

    private function gravarAplicacao(PastaChecklistModelo $modelo, DocumentosEPecasContexto $contexto): ResultadoDaConfirmacao
    {
        $r = $this->aplicarModelo->executar($contexto->pasta, $modelo, $contexto->escritorio);
        $criados = array_map(static fn (array $c): int => (int) $c['id'], $r->criados);
        $dados = ['modelo_checklist_id' => (int) $modelo->getId(), 'criados' => $r->totalCriados(), 'ignorados' => $r->totalIgnorados(), 'ids_dos_itens_criados' => $criados];

        return DocumentosEPecasResultado::de($contexto, $dados, ['item' => $criados, 'modelo_de_checklist' => [(int) $modelo->getId()]], ['criados' => $r->totalCriados()]);
    }

    /** @param array<string, mixed> $campos */
    private function gravarNovoNomeDoModelo(array $campos, PastaChecklistModelo $modelo, DocumentosEPecasContexto $contexto): ResultadoDaConfirmacao
    {
        $this->renomearModelo->executar($modelo, $campos['nome'], $contexto->escritorio);

        return $this->resultadoDoModelo($modelo, $contexto);
    }

    private function gravarExclusaoDoModelo(PastaChecklistModelo $modelo, DocumentosEPecasContexto $contexto): ResultadoDaConfirmacao
    {
        $id = (int) $modelo->getId();
        $this->excluirModelo->executar($modelo, $contexto->escritorio);

        return DocumentosEPecasResultado::de($contexto, ['modelo_de_checklist_excluido' => $id], ['modelo_de_checklist' => [$id]]);
    }

    private function resultadoDoItem(PastaChecklistItem $item, DocumentosEPecasContexto $contexto): ResultadoDaConfirmacao
    {
        $id = (int) $item->getId();
        $dados = ['item' => ['id' => $id, 'titulo' => $this->resolvedor->nome($contexto->escritorio, $item->getTitulo()), 'concluido' => $item->isConcluido()]];

        return DocumentosEPecasResultado::de($contexto, $dados, ['item' => [$id]]);
    }

    private function resultadoDoModelo(PastaChecklistModelo $modelo, DocumentosEPecasContexto $contexto): ResultadoDaConfirmacao
    {
        $id = (int) $modelo->getId();
        $dados = ['modelo_de_checklist' => ['id' => $id, 'nome' => $this->resolvedor->nome($contexto->escritorio, $modelo->getNome()), 'itens' => $modelo->totalItens()]];

        return DocumentosEPecasResultado::de($contexto, $dados, ['modelo_de_checklist' => [$id]]);
    }
}
