<?php

declare(strict_types=1);

namespace App\Auditoria\Service;

use App\Auditoria\UseCase\DesfazerAlteracaoAuditLogUseCase;
use App\Entity\Audit\AuditLog;
use App\Entity\Auth\User;
use App\Entity\Tarefa\Tarefa;
use App\Entity\Tenant\Tenant;
use App\Pasta\Entity\Pasta;
use App\Pasta\Entity\PastaChecklistItem;
use App\Pasta\Entity\PastaDocumento;
use App\Pasta\Entity\PastaMensagem;
use App\Pasta\Entity\PastaObservacaoDetalhes;
use App\Pasta\Entity\PastaSecao;
use App\Pasta\Service\AcessoAPasta;
use App\Pasta\UseCase\EditarMensagemPastaUseCase;
use App\Repository\AuditLogRepository;
use App\Service\PermissionChecker;
use App\Shared\Contract\TenantAware;
use App\Tarefa\Service\AcessoAMeta;
use App\Tarefa\Service\AcessoAoModuloTarefas;
use App\Tarefa\Service\QuemCuidaDaMeta;
use App\Tarefa\UseCase\AtualizarPrazoTarefaUseCase;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

/**
 * Desfazer é editar: quem desfaz uma linha da auditoria precisa poder fazer a mesma alteração pela tela. É o ponto
 * único que o `AuditLogController` consulta antes do `executar()` e para mostrar o botão (spec
 * `docs/specs/desfazer-guarda-da-tela.md`, continuação do F19).
 *
 *  - a permissão de edição do mapa do F19 (`DesfazerAlteracaoAuditLogUseCase::permissaoDeEdicao`);
 *  - meta: o módulo `tarefas` e o `AcessoAMeta`, mais a regra do prazo se o diff tem o prazo e a de quem cuida da
 *    meta se tem a conclusão ou a reabertura;
 *  - pasta e filhas: `AcessoAPasta::podeEditar` na pasta-mãe (com o teto do cadeado);
 *  - mensagem da pasta: a regra da tela de edição (`EditarMensagemPastaUseCase::podeEditar`).
 *
 * As guardas são CHAMADAS, nunca copiadas: mudou a regra da tela, mudou a do desfazer. A linha ou a entidade que não
 * existe, está na lixeira ou é de outro escritório não exige nada aqui: o `executar()` a recusa sem tocar em nada.
 */
final class GuardaDaTelaNoDesfazer
{
    private const CAMPO_DO_PRAZO = 'prazo';

    /** A conclusão (`ConcluirMetaUseCase`) e a reabertura (`ReabrirMetaUseCase`) mexem nestes campos. */
    private const CAMPOS_DA_CONCLUSAO = ['status', 'dataConclusao'];

    private const PASTA_E_FILHAS = [
        Pasta::class,
        PastaDocumento::class,
        PastaSecao::class,
        PastaChecklistItem::class,
        PastaObservacaoDetalhes::class,
    ];

    public function __construct(
        private readonly DesfazerAlteracaoAuditLogUseCase $desfazer,
        private readonly AuditLogRepository $auditLogRepository,
        private readonly EntityManagerInterface $em,
        private readonly PermissionChecker $permissionChecker,
        private readonly AcessoAoModuloTarefas $moduloTarefas,
        private readonly AcessoAMeta $acessoAMeta,
        private readonly AtualizarPrazoTarefaUseCase $prazoDaMeta,
        private readonly QuemCuidaDaMeta $quemCuidaDaMeta,
        private readonly AcessoAPasta $acessoAPasta,
        private readonly EditarMensagemPastaUseCase $editarMensagemPasta,
    ) {
    }

    public function permite(AuditLog $log, User $ator, ?Tenant $tenant): bool
    {
        $permissao = $this->desfazer->permissaoDeEdicao($log, $ator);

        if ($permissao !== null && !$this->permissionChecker->canAdminister($ator, $tenant, $permissao)) {
            return false;
        }

        return $this->permiteATelaDaEntidade($log, $ator, $tenant);
    }

    /** Linha de outro escritório ou inexistente: nada a exigir, o `executar()` responde "não encontrado". */
    public function permitePorId(int $auditLogId, User $ator, ?Tenant $tenant): bool
    {
        $log = $this->auditLogRepository->find($auditLogId);

        if ($log === null || $tenant === null || $log->getTenantId() !== $tenant->getId()) {
            return true;
        }

        return $this->permite($log, $ator, $tenant);
    }

    private function permiteATelaDaEntidade(AuditLog $log, User $ator, ?Tenant $tenant): bool
    {
        $classe = $log->getEntityClass();
        if ($classe !== Tarefa::class && $classe !== PastaMensagem::class && !in_array($classe, self::PASTA_E_FILHAS, true)) {
            return true;
        }
        if ($tenant === null) {
            return false;
        }

        $entidade = $log->getEntityId() !== null ? $this->em->find($classe, $log->getEntityId()) : null;
        if (!$entidade instanceof TenantAware || $entidade->getTenant()?->getId() !== $tenant->getId()) {
            return true;
        }

        return match (true) {
            $entidade instanceof Tarefa => $this->permiteAMeta($entidade, $this->camposDoDiff($log), $ator, $tenant),
            $entidade instanceof PastaMensagem => $this->editarMensagemPasta->podeEditar($entidade, $ator, $tenant),
            default => $this->permiteAPasta($entidade, $ator),
        };
    }

    /** @param list<string> $campos */
    private function permiteAMeta(Tarefa $meta, array $campos, User $ator, Tenant $tenant): bool
    {
        if (!$this->moduloTarefas->pode($ator, $tenant) || !$this->temAcessoAMeta($meta, $ator, $tenant)) {
            return false;
        }
        if (in_array(self::CAMPO_DO_PRAZO, $campos, true) && !$this->prazoDaMeta->podeEditarPrazo($meta, $ator, $tenant)) {
            return false;
        }

        return array_intersect(self::CAMPOS_DA_CONCLUSAO, $campos) === []
            || $this->quemCuidaDaMeta->ehResponsavelOuCriador($meta, $ator);
    }

    private function temAcessoAMeta(Tarefa $meta, User $ator, Tenant $tenant): bool
    {
        try {
            $this->acessoAMeta->exigir($meta, $tenant, $ator);
        } catch (NotFoundHttpException|AccessDeniedException) {
            return false;
        }

        return true;
    }

    private function permiteAPasta(object $entidade, User $ator): bool
    {
        $pasta = $this->pastaMae($entidade);

        return $pasta !== null && $this->acessoAPasta->podeEditar($ator, $pasta);
    }

    private function pastaMae(object $entidade): ?Pasta
    {
        if ($entidade instanceof Pasta) {
            return $entidade;
        }
        if ($entidade instanceof PastaDocumento || $entidade instanceof PastaSecao
            || $entidade instanceof PastaChecklistItem || $entidade instanceof PastaObservacaoDetalhes) {
            return $entidade->getPasta();
        }

        return null;
    }

    /** @return list<string> */
    private function camposDoDiff(AuditLog $log): array
    {
        $diff = ($log->getChanges() ?? [])['diff']['changes'] ?? [];

        return is_array($diff) ? array_map('strval', array_keys($diff)) : [];
    }
}
