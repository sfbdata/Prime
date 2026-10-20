<?php

declare(strict_types=1);

namespace App\Master\UseCase;

use App\Contribua\DTO\DefinirLimiteDeArmazenamentoInput;
use App\Contribua\DTO\DefinirLimiteDeUsuariosInput;
use App\Contribua\DTO\RetratoDoEspaco;
use App\Contribua\UseCase\DefinirLimiteDeArmazenamentoUseCase;
use App\Contribua\UseCase\DefinirLimiteDeUsuariosUseCase;
use App\Entity\Tenant\Tenant;
use App\Master\DTO\LimiteDaContaInput;
use App\Master\Exception\ContaNaoEncontrada;
use App\Master\Exception\OperacaoDeContaRecusada;
use App\Master\Repository\ContasDoSistemaRepository;
use App\Master\Service\RegraDosMasters;
use App\Shared\Audit\EscopoDeAuditoriaDePlataforma;

/**
 * O Master muda o limite de armazenamento ou de usuários de uma conta pelo menu da conta do Painel
 * (desenho 05 - Perfis 1.2, linhas 397-410 e 726-732; spec docs/specs/perfis-1.2-planos-e-limites.md P4).
 *
 * Quem dispara: um Master (primário ou secundário), no modal de limite, depois de escrever a justificativa.
 * O controller exige ROLE_SUPER_ADMIN; a checagem de Master se repete aqui.
 * Objetivo: dar à conta um limite próprio (ou, no armazenamento, deixá-la sem limite) sem apagar nada.
 * Pré-condições: a conta existe e não está em quarentena (excluída: some o que limitar até a purga).
 * Fluxo principal: carrega a conta, monta o pedido da Contribua e o executa no escopo de auditoria DA
 * CONTA ALVO, para a linha do `audit_log` cair no "Histórico de alterações" dela.
 * Fluxos alternativos: conta inexistente ({@see ContaNaoEncontrada}); conta em quarentena
 * ({@see OperacaoDeContaRecusada}); valor vazio ou não inteiro; recusas da Contribua (justificativa vazia,
 * abaixo do uso, "Menor que os usuários já cadastrados."), que chegam como \DomainException com o texto
 * do desenho.
 * Pós-condições: o retrato novo do espaço da conta, para o painel trocar a linha sem recarregar.
 * Regra: as regras de limite moram na Contribua; aqui só a conta alvo e o escopo da auditoria.
 */
final class DefinirLimiteDaContaUseCase
{
    public function __construct(
        private readonly ContasDoSistemaRepository $contas,
        private readonly RegraDosMasters $regraDosMasters,
        private readonly EscopoDeAuditoriaDePlataforma $escopoDeAuditoria,
        private readonly DefinirLimiteDeArmazenamentoUseCase $limiteDeArmazenamento,
        private readonly DefinirLimiteDeUsuariosUseCase $limiteDeUsuarios,
    ) {
    }

    public function executar(LimiteDaContaInput $input): RetratoDoEspaco
    {
        if (!$this->regraDosMasters->ehMaster($input->ator)) {
            throw new OperacaoDeContaRecusada('Só o Master pode mudar os limites de uma conta.');
        }
        $conta = $this->contaQueAceitaLimite($input->contaId);

        return $this->escopoDeAuditoria->executarComoConta(
            (int) $conta->getId(),
            fn (): RetratoDoEspaco => $this->definir($input),
        );
    }

    private function contaQueAceitaLimite(int $contaId): Tenant
    {
        $conta = $this->contas->buscar($contaId);
        if ($conta === null) {
            throw new ContaNaoEncontrada('Conta não encontrada.');
        }
        if ($conta->getExcluidoEm() !== null) {
            throw new OperacaoDeContaRecusada('Esta conta foi excluída. Reative a conta antes de mudar o limite.');
        }

        return $conta;
    }

    private function definir(LimiteDaContaInput $input): RetratoDoEspaco
    {
        if ($input->tipo === LimiteDaContaInput::USUARIOS) {
            // Vazio aqui não é "volta ao plano" (o contrato aceita nulo): o modal do desenho sempre pede um número.
            if ($input->valor === null) {
                throw new \DomainException('Informe um limite de usuários maior que zero.');
            }

            return $this->limiteDeUsuarios->executar(new DefinirLimiteDeUsuariosInput($input->contaId, $input->valor, $input->justificativa), $input->ator);
        }

        if (!$input->semLimite && ($input->valor === null || $input->valor <= 0)) {
            throw new \DomainException('Informe um limite em GB maior que zero.');
        }

        return $this->limiteDeArmazenamento->executar(
            new DefinirLimiteDeArmazenamentoInput($input->contaId, $input->semLimite ? null : $input->valor, $input->semLimite, $input->justificativa),
            $input->ator,
        );
    }
}
