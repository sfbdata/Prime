<?php

declare(strict_types=1);

namespace App\Recompensa\UseCase;

use App\Entity\Auth\User;
use App\Indicacao\Repository\IndicacaoRepository;
use App\Recompensa\DTO\BloquearOuDesbloquearRecompensaInput;
use App\Recompensa\Entity\CondicaoDeRecompensa;
use App\Recompensa\Exception\IndicadorNaoEncontrado;
use App\Recompensa\Repository\CondicaoDeRecompensaRepository;
use App\Shared\Audit\EscopoDeAuditoriaDePlataforma;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;

/**
 * "Bloquear recompensa" e "Desbloquear recompensa" (menu ⋮; spec perfis-1.2-vs-25-10-recompensas-e-planos, F3). Bloqueado
 * mostra a situação "Bloqueado" e sai do filtro "A pagar"; os números não mudam.
 *
 * Quem dispara: o Master (ROLE_SUPER_ADMIN no controller). Dado de plataforma, sem tenant: o indicador é qualquer usuário
 * que já indicou; o id vem da URL e é conferido contra as indicações.
 * Fluxo principal: grava o bloqueio na linha da condição (criando-a se preciso); ao desbloquear, a linha some quando não
 * sobra nada nela (sem condição individual nem apagado).
 * Idempotência: a trava das indicações do usuário serializa pedidos simultâneos (sem duas linhas); repetir o mesmo pedido não muda nada nem regrava (a data do primeiro bloqueio fica).
 * Fluxos alternativos: indicador inexistente (404).
 * Auditoria: CondicaoDeRecompensa é Auditavel; cada mudança entra na trilha do Master.
 */
final class BloquearOuDesbloquearRecompensaUseCase
{
    public function __construct(
        private readonly CondicaoDeRecompensaRepository $condicoes,
        private readonly IndicacaoRepository $indicacoes,
        private readonly EntityManagerInterface $em,
        private readonly ClockInterface $relogio,
        private readonly EscopoDeAuditoriaDePlataforma $escopoDePlataforma,
    ) {
    }

    /** @throws IndicadorNaoEncontrado */
    public function executar(BloquearOuDesbloquearRecompensaInput $input): void
    {
        if ($this->indicacoes->contarDoUsuario($input->userId) === 0) {
            throw new IndicadorNaoEncontrado('Indicador não encontrado.');
        }
        $usuario = $this->em->find(User::class, $input->userId) ?? throw new IndicadorNaoEncontrado('Indicador não encontrado.');

        $this->escopoDePlataforma->executar(fn () => $this->indicacoes->emTransacaoTravada($input->userId, function () use ($input, $usuario): void {
            $condicao = $this->condicoes->buscarDoIndicador($input->userId);
            if (($condicao?->isBloqueada() ?? false) === $input->bloqueado) {
                return;
            }
            $condicao ??= new CondicaoDeRecompensa($usuario);
            $agora = $this->relogio->now();
            $input->bloqueado ? $condicao->bloquear($agora) : $condicao->desbloquear();
            $condicao->registrarAlteracao($input->ator, $agora);
            $condicao->semEfeito() ? $this->condicoes->remover($condicao, flush: true) : $this->condicoes->salvar($condicao, flush: true);
        }));
    }
}
