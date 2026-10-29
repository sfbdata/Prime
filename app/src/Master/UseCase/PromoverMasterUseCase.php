<?php

declare(strict_types=1);

namespace App\Master\UseCase;

use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Master\DTO\MasterAlteradoOutput;
use App\Master\DTO\PromoverMasterInput;
use App\Master\Exception\OperacaoDeMasterRecusada;
use App\Master\Service\RegraDosMasters;
use App\Repository\UserRepository;
use App\Shared\Audit\EscopoDeAuditoriaDePlataforma;
use App\Repository\UserTenantRepository;

/**
 * Promove um usuário da conta (secundário ou primário do escritório) a Master secundário (spec docs/specs/perfis-1.2-masters.md, M1 a M7).
 *
 * Quem dispara: o Master primário, no bloco "Masters do sistema" da aba Permissões de /master. O
 * controller exige ROLE_SUPER_ADMIN; a regra de que só o PRIMÁRIO promove (M3) mora aqui, para o
 * Master secundário ser recusado mesmo forjando o POST.
 * Objetivo: dar a outra pessoa os poderes do sistema (o bypass total do ROLE_SUPER_ADMIN).
 * Pré-condições (M5): o alvo existe, a conta dele está ativa, ele ainda não é Master e tem vínculo
 * ATIVO com o escritório em que o Master primário está. Qualquer perfil serve, inclusive o de sistema
 * (o primário do escritório): decisão do dono em 29/10, que revogou a recusa antiga.
 * Fluxo principal: carrega o alvo, aplica as recusas, acrescenta ROLE_SUPER_ADMIN aos papéis dele
 * (os demais ficam como estão) e grava.
 * Fluxos alternativos: ator não é o primário; sem escritório na sessão; alvo inexistente, inativo,
 * já Master ou sem vínculo ativo com o escritório. Todos recusados com mensagem.
 * Pós-condições: o alvo é Master secundário; o vínculo com o escritório não muda.
 * Auditoria (M7): User é Auditavel; o AuditLogSubscriber grava a troca de `roles` no flush, dentro do
 * EscopoDeAuditoriaDePlataforma: sem escritório, visível só na trilha do Master.
 * Regra não óbvia: a busca do vínculo é pelo par (alvo, escritório da sessão). Usuário de outro
 * escritório não encontra vínculo e para aqui, mesmo com um id forjado.
 */
final class PromoverMasterUseCase
{
    public function __construct(
        private readonly UserRepository $userRepository,
        private readonly UserTenantRepository $userTenantRepository,
        private readonly RegraDosMasters $regraDosMasters,
        private readonly EscopoDeAuditoriaDePlataforma $escopoDePlataforma,
    ) {
    }

    public function executar(PromoverMasterInput $input): MasterAlteradoOutput
    {
        $this->exigirMasterPrimario($input->ator);
        $escritorio = $this->exigirEscritorio($input->escritorio);
        $alvo = $this->carregarAlvo($input->alvoId);
        $this->exigirUsuarioDoEscritorio($alvo, $escritorio);

        RegraDosMasters::darPapeis($alvo, RegraDosMasters::PAPEL_MASTER);
        // Ato de plataforma: a troca de roles vai para o audit_log sem escritório (M7).
        $this->escopoDePlataforma->executar(fn () => $this->userRepository->salvar($alvo, flush: true));

        return MasterAlteradoOutput::fromEntity($alvo);
    }

    private function exigirMasterPrimario(User $ator): void
    {
        if (!$this->regraDosMasters->ehMasterPrimario($ator)) {
            throw new OperacaoDeMasterRecusada(OperacaoDeMasterRecusada::RECUSA_SO_PRIMARIO);
        }
    }

    private function exigirEscritorio(?Tenant $escritorio): Tenant
    {
        if ($escritorio === null) {
            throw new OperacaoDeMasterRecusada(OperacaoDeMasterRecusada::RECUSA_OUTRO_ESCRITORIO);
        }

        return $escritorio;
    }

    private function carregarAlvo(int $alvoId): User
    {
        $alvo = $alvoId > 0 ? $this->userRepository->find($alvoId) : null;
        if ($alvo === null) {
            throw new OperacaoDeMasterRecusada(OperacaoDeMasterRecusada::RECUSA_SEM_ESCOLHA);
        }

        if (!$alvo->isActive()) {
            throw new OperacaoDeMasterRecusada(OperacaoDeMasterRecusada::RECUSA_CONTA_DESATIVADA);
        }

        if ($this->regraDosMasters->ehMaster($alvo)) {
            throw new OperacaoDeMasterRecusada(OperacaoDeMasterRecusada::RECUSA_JA_MASTER);
        }

        return $alvo;
    }

    private function exigirUsuarioDoEscritorio(User $alvo, Tenant $escritorio): void
    {
        $vinculo = $this->userTenantRepository->findAtivoPorUserETenant($alvo, $escritorio);
        if ($vinculo === null || !$vinculo->isActive() || !$this->mesmoEscritorio($vinculo->getTenant(), $escritorio)) {
            throw new OperacaoDeMasterRecusada(OperacaoDeMasterRecusada::RECUSA_SO_USUARIO_ATIVO);
        }
    }

    /** Mesma conta: identidade, ou id igual quando os dois têm id (proxy x entidade carregada). */
    private function mesmoEscritorio(?Tenant $a, Tenant $b): bool
    {
        return $a === $b || ($a !== null && $a->getId() !== null && $a->getId() === $b->getId());
    }
}
