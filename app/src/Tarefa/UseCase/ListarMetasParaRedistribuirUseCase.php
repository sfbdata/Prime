<?php

declare(strict_types=1);

namespace App\Tarefa\UseCase;

use App\Entity\Auth\User;
use App\Entity\Tarefa\Tarefa;
use App\Entity\Tenant\Tenant;
use App\Tarefa\DTO\DestinoParaRedistribuirOutput;
use App\Tarefa\DTO\ListagemParaRedistribuirOutput;
use App\Tarefa\DTO\MetaParaRedistribuirOutput;
use App\Tarefa\Exception\RedistribuicaoForaDoEscritorioException;
use App\Tarefa\Exception\RedistribuicaoNaoPermitidaException;
use App\Tarefa\Exception\RedistribuicaoRecusadaException;
use App\Tarefa\Repository\TarefaRepository;
use App\Tarefa\Service\GuardaDaRedistribuicao;

/**
 * A tela do "Redistribuir" do perfil da pessoa (spec painel-acompanhamento-redistribuir, fatia B): as metas ATIVAS em
 * que ela é responsável, de pasta visível a quem olha (cadeado), cada uma com o destino sugerido por REGRAS FIXAS
 * (LGPD art. 20: nenhuma IA, ninguém é avaliado): quem tem MENOS metas ativas e vê a pasta; empate pelo menor id; a
 * cada sugestão a contagem do escolhido sobe 1, para não empilhar tudo em uma pessoa. Só lê e nada é marcado: o
 * gestor escolhe e confirma ({@see RedistribuirMetasDaPessoaUseCase}).
 */
final class ListarMetasParaRedistribuirUseCase
{
    public function __construct(
        private readonly GuardaDaRedistribuicao $guarda,
        private readonly TarefaRepository $tarefas,
    ) {
    }

    /**
     * @param list<int> $pastasOcultas pastas ocultas pelo cadeado para quem olha
     *
     * @throws RedistribuicaoNaoPermitidaException
     * @throws RedistribuicaoForaDoEscritorioException
     * @throws RedistribuicaoRecusadaException
     */
    public function executar(Tenant $tenant, User $ator, int $origemId, array $pastasOcultas): ListagemParaRedistribuirOutput
    {
        $origem    = $this->guarda->exigirOrigem($tenant, $ator, $origemId);
        $elegiveis = $this->guarda->elegiveis($tenant, $origem);
        $carga     = $this->tarefas->countAtivasPorResponsavel($tenant);
        $cargaInicial = array_map(static fn (User $u): int => $carga[(int) $u->getId()] ?? 0, $elegiveis);

        $metas = [];
        foreach ($this->tarefas->ativasDaPessoa($tenant, $origem, $pastasOcultas) as $meta) {
            $metas[] = $this->linha($meta, $elegiveis, $cargaInicial);
        }

        $destinos = array_map(
            static fn (User $u): DestinoParaRedistribuirOutput => new DestinoParaRedistribuirOutput((int) $u->getId(), self::nome($u), $carga[(int) $u->getId()] ?? 0),
            array_values($elegiveis),
        );

        return new ListagemParaRedistribuirOutput((int) $origem->getId(), self::nome($origem), $metas, $destinos);
    }

    /**
     * @param array<int, User> $elegiveis
     * @param array<int, int>  $carga      id => metas ativas, subindo a cada sugestão (por referência)
     */
    private function linha(Tarefa $meta, array $elegiveis, array &$carga): MetaParaRedistribuirOutput
    {
        $veem = array_keys(array_filter($elegiveis, fn (User $u): bool => $this->guarda->veAPasta($meta, $u)));
        $sugerido = null;
        foreach ($veem as $id) {
            if ($sugerido === null || $carga[$id] < $carga[$sugerido]) {
                $sugerido = $id;
            }
        }
        if ($sugerido !== null) {
            ++$carga[$sugerido];
        }

        $pasta = $meta->getPasta();

        return new MetaParaRedistribuirOutput((int) $meta->getId(), $meta->getTitulo(), $pasta->getNup() ?? '#' . $pasta->getId(), $meta->getPrazo(), $sugerido, $veem);
    }

    private static function nome(User $pessoa): string
    {
        return trim((string) ($pessoa->getFullName() ?? $pessoa->getEmail()));
    }
}
