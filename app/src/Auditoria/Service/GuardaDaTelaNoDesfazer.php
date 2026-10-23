<?php

declare(strict_types=1);

namespace App\Auditoria\Service;

use App\Auditoria\UseCase\DesfazerAlteracaoAuditLogUseCase;
use App\Entity\Audit\AuditLog;
use App\Entity\Auth\User;
use App\Entity\Tarefa\Tarefa;
use App\Entity\Tenant\Tenant;
use App\Pasta\Entity\Pasta;
use App\Pasta\EventListener\BarreiraDoCadeadoListener;
use App\Pasta\Entity\PastaChecklistItem;
use App\Pasta\Entity\PastaDocumento;
use App\Pasta\Entity\PastaMensagem;
use App\Pasta\Entity\PastaObservacaoDetalhes;
use App\Pasta\Entity\PastaSecao;
use App\Pasta\Service\AcessoAPasta;
use App\Pasta\UseCase\EditarMensagemPastaUseCase;
use App\Pasta\UseCase\EditarObservacaoDetalhesUseCase;
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
 *  - meta: o que a rota de cada campo pede (mapa abaixo). Para qualquer campo, o módulo `tarefas`, o `AcessoAMeta` e
 *    a barreira do cadeado para escrita na pasta da meta;
 *  - pasta e filhas: `AcessoAPasta::podeEditar` na pasta-mãe (com o teto do cadeado); a observação dos Detalhes,
 *    também a regra da tela de edição dela (`EditarObservacaoDetalhesUseCase::podeEditar`: autor e janela);
 *  - mensagem da pasta: o que a rota de edição pede. Ver a pasta (`AcessoAPasta::podeVer`), a barreira do cadeado
 *    para escrita e a regra da edição (`EditarMensagemPastaUseCase::podeEditar`).
 *
 * As guardas são CHAMADAS, nunca copiadas: mudou a regra da tela, mudou a do desfazer. A linha ou a entidade que não
 * existe, está na lixeira ou é de outro escritório não exige nada aqui: o `executar()` a recusa sem tocar em nada.
 */
final class GuardaDaTelaNoDesfazer
{
    /** A escrita que a barreira do cadeado julga: o próprio POST do desfazer, que não é rota de leitura. */
    private const METODO_DO_DESFAZER = 'POST';
    private const ROTA_DO_DESFAZER = 'audit_log_desfazer';

    /** O prazo: `tarefa_atualizar_prazo`, só quem criou a meta (`AtualizarPrazoTarefaUseCase::podeEditarPrazo`). */
    private const CAMPOS_DO_CRIADOR = ['prazo'];

    /**
     * Quem cuida da meta (`QuemCuidaDaMeta`): a conclusão (`tarefa_concluir`), a reabertura (`tarefa_reabrir`) e a
     * etapa (`tarefa_passar`).
     */
    private const CAMPOS_DE_QUEM_CUIDA = ['status', 'dataConclusao', 'etapa', 'etapasSaltadas'];

    /**
     * Editar a pasta da meta (`canAccessResource('pasta', edit)`, o `AcessoAPasta::podeEditar`): o nome
     * (`tarefa_renomear`) e a reabertura (`tarefa_reabrir`), no `MetaNaListaController`. O concluir, o prazo, a
     * passagem e a devolução não pedem.
     */
    private const CAMPOS_DE_QUEM_EDITA_A_PASTA = ['titulo', 'status', 'dataConclusao'];

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
        private readonly EditarObservacaoDetalhesUseCase $editarObservacaoDetalhes,
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
            $entidade instanceof PastaMensagem => $this->permiteAMensagem($entidade, $ator, $tenant),
            $entidade instanceof PastaObservacaoDetalhes => $this->permiteAPasta($entidade, $ator)
                && $this->editarObservacaoDetalhes->podeEditar($entidade, $ator, $tenant),
            default => $this->permiteAPasta($entidade, $ator),
        };
    }

    /** @param list<string> $campos */
    private function permiteAMeta(Tarefa $meta, array $campos, User $ator, Tenant $tenant): bool
    {
        if (!$this->moduloTarefas->pode($ator, $tenant) || !$this->temAcessoAMeta($meta, $ator, $tenant)
            || !$this->passaPelaBarreira($meta->getPasta(), $ator)) {
            return false;
        }

        return (!self::temAlgum(self::CAMPOS_DO_CRIADOR, $campos) || $this->prazoDaMeta->podeEditarPrazo($meta, $ator, $tenant))
            && (!self::temAlgum(self::CAMPOS_DE_QUEM_CUIDA, $campos) || $this->quemCuidaDaMeta->ehResponsavelOuCriador($meta, $ator))
            && (!self::temAlgum(self::CAMPOS_DE_QUEM_EDITA_A_PASTA, $campos) || $this->acessoAPasta->podeEditar($ator, $meta->getPasta()));
    }

    /**
     * @param list<string> $daRegra
     * @param list<string> $doDiff
     */
    private static function temAlgum(array $daRegra, array $doDiff): bool
    {
        return array_intersect($daRegra, $doDiff) !== [];
    }

    /** A `BarreiraDoCadeadoListener` recusa a escrita (POST) na meta ou na pasta a quem o cadeado deixa só ver. */
    private function passaPelaBarreira(Pasta $pasta, User $ator): bool
    {
        return !BarreiraDoCadeadoListener::recusaria($this->acessoAPasta->nivel($ator, $pasta), self::METODO_DO_DESFAZER, self::ROTA_DO_DESFAZER);
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

    /** A rota de edição pede ver a pasta, e a barreira do cadeado recusa a escrita de quem só visualiza. */
    private function permiteAMensagem(PastaMensagem $mensagem, User $ator, Tenant $tenant): bool
    {
        $pasta = $mensagem->getPasta();

        return $pasta !== null
            && $this->acessoAPasta->podeVer($ator, $pasta)
            && $this->passaPelaBarreira($pasta, $ator)
            && $this->editarMensagemPasta->podeEditar($mensagem, $ator, $tenant);
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
