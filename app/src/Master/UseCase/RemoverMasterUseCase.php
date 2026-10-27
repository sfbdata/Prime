<?php

declare(strict_types=1);

namespace App\Master\UseCase;

use App\Entity\Auth\User;
use App\Master\DTO\MasterAlteradoOutput;
use App\Master\DTO\RemoverMasterInput;
use App\Master\Exception\OperacaoDeMasterRecusada;
use App\Master\Service\RegraDosMasters;
use App\Repository\UserRepository;
use App\Shared\Audit\EscopoDeAuditoriaDePlataforma;

/**
 * Remove um Master secundário: ele deixa de ser Master (spec docs/specs/perfis-1.2-masters.md, M3, M4, M6).
 *
 * Quem dispara: o Master primário, na lixeira da linha do Master secundário em /master (aba
 * Permissões). O controller exige ROLE_SUPER_ADMIN; a regra de que só o PRIMÁRIO remove mora aqui.
 * Objetivo: tirar de alguém os poderes do sistema.
 * Pré-condições: o alvo existe, é Master e não é o Master primário.
 * Fluxo principal: carrega o alvo, aplica as recusas, tira só o ROLE_SUPER_ADMIN e grava.
 * Fluxos alternativos: ator não é o primário; alvo inexistente, que não é Master ou que é o
 * primário (cadeado: trocar o primário é do comando app:master:definir). Todos recusados com mensagem.
 * Pós-condições (M6): o alvo perde só o ROLE_SUPER_ADMIN; o vínculo dele com o escritório e os
 * demais papéis ficam como estão.
 * Auditoria (M7): User é Auditavel; o AuditLogSubscriber grava a troca de `roles` no flush, dentro do
 * EscopoDeAuditoriaDePlataforma: sem escritório, visível só na trilha do Master.
 */
final class RemoverMasterUseCase
{
    public function __construct(
        private readonly UserRepository $userRepository,
        private readonly RegraDosMasters $regraDosMasters,
        private readonly EscopoDeAuditoriaDePlataforma $escopoDePlataforma,
    ) {
    }

    public function executar(RemoverMasterInput $input): MasterAlteradoOutput
    {
        if (!$this->regraDosMasters->ehMasterPrimario($input->ator)) {
            throw new OperacaoDeMasterRecusada(OperacaoDeMasterRecusada::RECUSA_SO_PRIMARIO);
        }

        $alvo = $this->carregarMasterSecundario($input->alvoId);

        RegraDosMasters::tirarPapeis($alvo, RegraDosMasters::PAPEL_MASTER);
        // Ato de plataforma: a troca de roles vai para o audit_log sem escritório (M7).
        $this->escopoDePlataforma->executar(fn () => $this->userRepository->salvar($alvo, flush: true));

        return MasterAlteradoOutput::fromEntity($alvo);
    }

    private function carregarMasterSecundario(int $alvoId): User
    {
        $alvo = $alvoId > 0 ? $this->userRepository->find($alvoId) : null;
        if ($alvo === null || !$this->regraDosMasters->ehMaster($alvo)) {
            throw new OperacaoDeMasterRecusada(OperacaoDeMasterRecusada::RECUSA_NAO_E_MASTER);
        }

        if ($this->regraDosMasters->ehMasterPrimario($alvo)) {
            throw new OperacaoDeMasterRecusada(OperacaoDeMasterRecusada::RECUSA_EXCLUIR_PRIMARIO);
        }

        return $alvo;
    }
}
