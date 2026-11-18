<?php

declare(strict_types=1);

namespace App\Processo\UseCase;

use App\Entity\Auth\User;
use App\Entity\Permission\AccessRequest;
use App\Entity\Tenant\Tenant;
use App\Pasta\Repository\PastaRepository;
use App\Pasta\Service\AcessoAPasta;
use App\Processo\DTO\NotaTecnicaOutput;
use App\Processo\DTO\ProcessoLidoOutput;
use App\Processo\Entity\MovimentacaoProcesso;
use App\Processo\Entity\ParteProcesso;
use App\Processo\Entity\Processo;
use App\Processo\Exception\ProcessoEmSegredoDeJusticaException;
use App\Processo\Exception\ProcessoNaoEncontradoException;
use App\Processo\Repository\MovimentacaoProcessoRepository;
use App\Processo\Repository\NotaTecnicaRepository;
use App\Processo\Repository\ProcessoRepository;
use App\Service\PermissionChecker;

/**
 * Lê um processo por id sem sessão: a regra do `ProcessoController::show` (a conferência, as notas técnicas e as pastas
 * vinculadas com o cadeado) com a pessoa e o escritório na assinatura, para o conector de I.A. A tela não muda.
 *
 * Quem: uma pessoa do escritório. O quê: os dados do processo, as partes, uma página das movimentações (a mais
 * recente primeiro), as últimas notas técnicas e as pastas vinculadas.
 * Pré-condições: o processo é do escritório; a pessoa não o tem oculto pelo cadeado das pastas
 * ({@see AcessoAPasta::idsDosProcessosOcultos()}) e pode ver o recurso `processo`, nesta ordem, como a tela. O módulo
 * `processos` quem chama confere antes.
 * Erros: {@see ProcessoNaoEncontradoException} (inexistente, de outro escritório, oculto ou sem permissão: a mesma
 * resposta) e {@see ProcessoEmSegredoDeJusticaException}, só depois de a pessoa poder abrir o processo.
 */
final class LerProcessoUseCase
{
    public const MOVIMENTACOES_POR_PAGINA = 20;
    public const LIMITE_DE_NOTAS = 20;
    public const LIMITE_DE_PARTES = 30;

    public function __construct(
        private readonly ProcessoRepository $processos,
        private readonly MovimentacaoProcessoRepository $movimentacoes,
        private readonly NotaTecnicaRepository $notas,
        private readonly PastaRepository $pastas,
        private readonly AcessoAPasta $acessoAPasta,
        private readonly PermissionChecker $permissoes,
    ) {
    }

    /**
     * @throws ProcessoNaoEncontradoException
     * @throws ProcessoEmSegredoDeJusticaException
     */
    public function executar(int $processoId, int $pagina, User $pessoa, Tenant $escritorio): ProcessoLidoOutput
    {
        $processo = $this->processos->findOneByIdDoTenant($processoId, $escritorio);
        if (!$processo instanceof Processo || !$this->podeAbrir($processo, $pessoa, $escritorio)) {
            throw new ProcessoNaoEncontradoException('Processo não encontrado.');
        }
        if ($processo->emSegredoDeJustica()) {
            throw new ProcessoEmSegredoDeJusticaException('Processo em segredo de justiça.');
        }

        $pagina = max(1, $pagina);
        $lidas = $this->movimentacoes->paginaDoProcesso($processo, $escritorio, $pagina, self::MOVIMENTACOES_POR_PAGINA);

        return $this->saida($processo, $lidas, $pagina, $pessoa, $escritorio);
    }

    /** @param list<MovimentacaoProcesso> $lidas até uma linha a mais que a página: a que sobra só sinaliza a próxima */
    private function saida(Processo $processo, array $lidas, int $pagina, User $pessoa, Tenant $escritorio): ProcessoLidoOutput
    {
        $partes = $processo->getPartes()->toArray();

        return new ProcessoLidoOutput(
            id: (int) $processo->getId(),
            numero: $processo->getNumeroProcesso(),
            tribunal: trim($processo->getSiglaTribunal()),
            classe: trim($processo->getClasseProcessual()),
            assunto: trim($processo->getAssuntoProcessual()),
            orgaoJulgador: trim($processo->getOrgaoJulgador()),
            instancia: $processo->getInstanciaRotulo(),
            situacao: trim($processo->getSituacaoProcesso()),
            distribuidoEm: $processo->getDataDistribuicao()?->format('Y-m-d'),
            baixadoEm: $processo->getDataBaixa()?->format('Y-m-d'),
            partes: array_map(
                static fn (ParteProcesso $p): array => ['tipo' => $p->getTipo(), 'papel' => $p->getPapel(), 'nome' => $p->getNome()],
                \array_slice($partes, 0, self::LIMITE_DE_PARTES),
            ),
            totalDePartes: \count($partes),
            movimentacoes: array_map(static fn (MovimentacaoProcesso $m): array => [
                'data' => $m->getDataMovimentacao()?->format('Y-m-d'),
                'descricao' => $m->getDescricao(),
                'tipo' => $m->getTipo(),
                'orgao' => $m->getOrgao(),
                'complementos' => $m->getComplementosResumo(),
            ], \array_slice($lidas, 0, self::MOVIMENTACOES_POR_PAGINA)),
            paginaDasMovimentacoes: $pagina,
            haMaisMovimentacoes: \count($lidas) > self::MOVIMENTACOES_POR_PAGINA,
            notas: array_map(NotaTecnicaOutput::fromEntity(...), $this->notas->listarPorProcesso($processo, $escritorio, self::LIMITE_DE_NOTAS)),
            pastas: $this->pastas->listarVinculadasAoProcesso($processo, $escritorio, $pessoa),
        );
    }

    /** A ordem da tela: o cadeado das pastas (K3), depois o recurso `processo` de ver. */
    private function podeAbrir(Processo $processo, User $pessoa, Tenant $escritorio): bool
    {
        $id = (int) $processo->getId();
        if (\in_array($id, $this->acessoAPasta->idsDosProcessosOcultos($pessoa, $escritorio), true)) {
            return false;
        }

        return $this->permissoes->canAccessResource($pessoa, $escritorio, AccessRequest::RESOURCE_PROCESSO, $id, AccessRequest::ACTION_VIEW);
    }
}
