<?php

declare(strict_types=1);

namespace App\Master\UseCase;

use App\Master\DTO\AlternarRecursoInput;
use App\Master\DTO\EstadoDoRecursoOutput;
use App\Master\Entity\RecursoEstado;
use App\Master\Exception\RecursoRecusado;
use App\Master\Repository\RecursoEstadoRepository;
use App\Master\Service\CatalogoDeRecursos;
use Psr\Clock\ClockInterface;

/**
 * Liga ou desliga uma tela ou função para todas as contas (spec docs/specs/perfis-1.2-telas-e-recursos.md).
 *
 * Quem dispara: o Master, na aba "Telas e recursos" de /master (o controller exige ROLE_SUPER_ADMIN, R7).
 * Objetivo: tirar do ar, para quem não é Master, uma tela que não está pronta, que está em revisão
 * ética ou com falha, e devolvê-la depois.
 * Pré-condições: a chave existe no catálogo (R1) e não é essencial (R3).
 * Fluxo principal: carrega a linha da chave (ou cria uma nova), desliga com motivo e data ou religa
 * limpando os dois, grava, e devolve o estado efetivo novo.
 * Fluxos alternativos: chave desconhecida e tela essencial são recusadas (422 no controller);
 * religar o que já está ativo (ou sem linha, que já é ativo) não grava nada, para a trilha não
 * ganhar um "Reativou" que não mudou coisa alguma.
 * Pós-condições: o menu, o guarda de rota e a faixa do Master passam a ler o estado novo.
 * Auditoria (R7): RecursoEstado é Auditavel; o AuditLogSubscriber grava a mudança no flush.
 */
final class AlternarRecursoUseCase
{
    public function __construct(
        private readonly RecursoEstadoRepository $repositorio,
        private readonly ClockInterface $relogio,
    ) {
    }

    public function executar(AlternarRecursoInput $input): EstadoDoRecursoOutput
    {
        $this->exigirAlteravel($input->chave);
        $agora = $this->relogio->now();
        $estado = $this->repositorio->buscarPorChave($input->chave);

        if ($input->ativo && ($estado === null || $estado->isAtivo())) {
            return EstadoDoRecursoOutput::ativo($input->chave);
        }

        $estado ??= new RecursoEstado($input->chave);
        $this->aplicar($estado, $input, $agora);
        $this->repositorio->salvar($estado, flush: true);

        return EstadoDoRecursoOutput::fromEntity($estado, $agora);
    }

    private function exigirAlteravel(string $chave): void
    {
        if (!CatalogoDeRecursos::existe($chave)) {
            throw new RecursoRecusado('Tela ou função desconhecida.');
        }

        if (CatalogoDeRecursos::ehEssencial($chave)) {
            throw new RecursoRecusado('Tela essencial: não pode ser desativada.');
        }
    }

    private function aplicar(RecursoEstado $estado, AlternarRecursoInput $input, \DateTimeImmutable $agora): void
    {
        if ($input->ativo) {
            $estado->reativar($input->ator, $agora);

            return;
        }

        $estado->desativar($input->motivo, $input->ate, $input->ator, $agora);
    }
}
