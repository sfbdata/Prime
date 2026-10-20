<?php

declare(strict_types=1);

namespace App\Pasta\Service;

use App\Entity\Audit\AuditLog;
use App\Entity\Auth\User;
use App\Entity\Tarefa\Tarefa;
use App\Entity\Tarefa\TarefaMensagem;
use App\Entity\Tenant\Tenant;
use App\Inteligencia\Entity\AnaliseDeInteligencia;
use App\Pasta\Entity\Pasta;
use Doctrine\ORM\EntityManagerInterface;

/**
 * A auditoria do escritório e o cadeado (spec pasta-cadeado D4, padrão do `ConteudoParticular`): as linhas
 * ligadas a uma pasta oculta para quem lê a auditoria aparecem sem o conteúdo (a ação, quem e quando
 * continuam) e não podem ser desfeitas. O Master e o primário veem tudo (para eles nenhuma pasta é oculta).
 *
 * Entidades cobertas (as auditáveis ligadas a uma pasta):
 *  - `App\Pasta\Entity\*` (a própria `Pasta`, documento, seção, mensagem, checklist, observações,
 *    pagamento, prazo, andamento, réu, cadeado, lista e pedido);
 *  - `Tarefa` (a meta, pela pasta dela) e `TarefaMensagem` (a resposta da meta, pela meta);
 *  - `AnaliseDeInteligencia` com alvo `pasta` (as análises da BlueJus IA da pasta).
 * Ficam de fora: `CasoCobranca` (é da Cobrança, que tem o próprio acesso), `NotaTecnica` (é do processo)
 * e `Notificacao` (é de uma pessoa).
 *
 * A pasta da linha sai, nesta ordem: do próprio id (linha da `Pasta`); do que ficou gravado no estado
 * (`after`/`before`: `pasta`, `tarefa`, ou `alvoTipo`/`alvoId`); e, por fim, da entidade ainda existente.
 */
final class AuditoriaDoCadeado
{
    private const PREFIXO = 'App\\Pasta\\Entity\\';

    public function __construct(
        private readonly AcessoAPasta $acessoAPasta,
        private readonly EntityManagerInterface $em,
    ) {
    }

    /**
     * @param iterable<AuditLog> $linhas
     *
     * @return array<int, true> ids das linhas cujo conteúdo não aparece para a pessoa
     */
    public function linhasOcultas(User $pessoa, Tenant $tenant, iterable $linhas): array
    {
        $ocultas = array_flip($this->acessoAPasta->idsDasPastasOcultas($pessoa, $tenant));
        if ($ocultas === []) {
            return [];
        }

        $resultado = [];
        foreach ($linhas as $linha) {
            $pastaId = $this->pastaDaLinha($linha);
            if ($pastaId !== null && isset($ocultas[$pastaId])) {
                $resultado[(int) $linha->getId()] = true;
            }
        }

        return $resultado;
    }

    /** O "desfazer" de uma linha oculta é recusado: desfazer seria escrever na pasta que a pessoa não vê. */
    public function linhaOcultaPorId(User $pessoa, Tenant $tenant, int $auditLogId): bool
    {
        $linha = $this->em->find(AuditLog::class, $auditLogId);

        return $linha instanceof AuditLog && $this->linhasOcultas($pessoa, $tenant, [$linha]) !== [];
    }

    private function pastaDaLinha(AuditLog $linha): ?int
    {
        $classe = $linha->getEntityClass();
        $id     = is_numeric($linha->getEntityId()) ? (int) $linha->getEntityId() : null;
        $estado = $linha->getChanges()['diff'] ?? [];

        return match (true) {
            $classe === Pasta::class                 => $id,
            str_starts_with($classe, self::PREFIXO),
            $classe === Tarefa::class                => self::idDoEstado($estado, 'pasta') ?? $this->pastaDaEntidade($classe, $id),
            $classe === TarefaMensagem::class        => $this->pastaDaMeta(self::idDoEstado($estado, 'tarefa')) ?? $this->pastaDaEntidade($classe, $id),
            $classe === AnaliseDeInteligencia::class => $this->pastaDaAnalise($estado, $id),
            default                                  => null,
        };
    }

    /** @param array<string, mixed> $estado */
    private static function idDoEstado(array $estado, string $campo): ?int
    {
        foreach (['after', 'before'] as $momento) {
            $id = $estado[$momento][$campo]['id'] ?? null;
            if (is_numeric($id)) {
                return (int) $id;
            }
        }

        return null;
    }

    private function pastaDaEntidade(string $classe, ?int $id): ?int
    {
        $entidade = $id !== null && class_exists($classe) ? $this->em->find($classe, $id) : null;
        if (is_object($entidade) && !method_exists($entidade, 'getPasta') && method_exists($entidade, 'getTarefa')) {
            $entidade = $entidade->getTarefa();
        }
        $pasta = is_object($entidade) && method_exists($entidade, 'getPasta') ? $entidade->getPasta() : null;

        return $pasta instanceof Pasta ? $pasta->getId() : null;
    }

    private function pastaDaMeta(?int $tarefaId): ?int
    {
        return $tarefaId !== null ? $this->pastaDaEntidade(Tarefa::class, $tarefaId) : null;
    }

    /** @param array<string, mixed> $estado */
    private function pastaDaAnalise(array $estado, ?int $id): ?int
    {
        foreach (['after', 'before'] as $momento) {
            if (($estado[$momento]['alvoTipo'] ?? null) === AnaliseDeInteligencia::ALVO_PASTA && is_numeric($estado[$momento]['alvoId'] ?? null)) {
                return (int) $estado[$momento]['alvoId'];
            }
        }
        $analise = $id !== null ? $this->em->find(AnaliseDeInteligencia::class, $id) : null;

        return $analise instanceof AnaliseDeInteligencia && $analise->getAlvoTipo() === AnaliseDeInteligencia::ALVO_PASTA ? $analise->getAlvoId() : null;
    }
}
