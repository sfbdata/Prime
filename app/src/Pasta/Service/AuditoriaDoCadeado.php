<?php

declare(strict_types=1);

namespace App\Pasta\Service;

use App\Entity\Audit\AuditLog;
use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Pasta\Entity\Pasta;
use Doctrine\ORM\EntityManagerInterface;

/**
 * A auditoria do escritório e o cadeado (spec pasta-cadeado D4, padrão do `ConteudoParticular`): as linhas
 * de entidades da pasta (`App\Pasta\Entity\*`) de uma pasta oculta para quem lê a auditoria aparecem sem o
 * conteúdo (a ação, quem e quando continuam) e não podem ser desfeitas. O Master e o primário veem tudo
 * (para eles nenhuma pasta é oculta).
 *
 * A pasta da linha sai, nesta ordem: do próprio id (linha da `Pasta`); do `pasta` gravado no estado
 * (`after`/`before`); e, por fim, da entidade ainda existente (`getPasta()`).
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
        if (!str_starts_with($classe, self::PREFIXO)) {
            return null;
        }
        if ($classe === Pasta::class) {
            return is_numeric($linha->getEntityId()) ? (int) $linha->getEntityId() : null;
        }

        return $this->pastaDoEstado($linha->getChanges() ?? []) ?? $this->pastaDaEntidade($classe, $linha->getEntityId());
    }

    /** @param array<string, mixed> $changes */
    private function pastaDoEstado(array $changes): ?int
    {
        foreach (['after', 'before'] as $estado) {
            $id = $changes['diff'][$estado]['pasta']['id'] ?? null;
            if (is_numeric($id)) {
                return (int) $id;
            }
        }

        return null;
    }

    private function pastaDaEntidade(string $classe, ?string $id): ?int
    {
        if (!is_numeric($id) || !class_exists($classe)) {
            return null;
        }
        $entidade = $this->em->find($classe, (int) $id);
        $pasta    = is_object($entidade) && method_exists($entidade, 'getPasta') ? $entidade->getPasta() : null;

        return $pasta instanceof Pasta ? $pasta->getId() : null;
    }
}
