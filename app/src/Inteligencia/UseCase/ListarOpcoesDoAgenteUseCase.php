<?php

declare(strict_types=1);

namespace App\Inteligencia\UseCase;

use App\Entity\Tenant\Tenant;
use App\Expediente\Repository\MarcadorRepository;
use App\Inteligencia\DTO\OpcoesDoAgenteOutput;
use App\Pasta\Entity\Pasta;
use App\Pasta\Entity\PrioridadePasta;
use App\Repository\UserRepository;

/**
 * O vocabulário que o agente precisa para montar um filtro: os responsáveis possíveis (colaboradores
 * ativos do escritório — a mesma lista do filtro da Lista), os marcadores do escritório e os valores
 * válidos de situação e prioridade. Só dados que a Lista já mostra a quem tem o módulo.
 */
final class ListarOpcoesDoAgenteUseCase
{
    public function __construct(
        private readonly UserRepository $userRepository,
        private readonly MarcadorRepository $marcadorRepository,
    ) {
    }

    public function executar(Tenant $tenant): OpcoesDoAgenteOutput
    {
        $responsaveis = [];
        foreach ($this->userRepository->findColaboradoresAtivosPorTenant($tenant) as $usuario) {
            $responsaveis[] = ['id' => (int) $usuario->getId(), 'nome' => (string) $usuario->getFullName()];
        }

        $marcadores = [];
        foreach ($this->marcadorRepository->findTodosPorTenant($tenant) as $marcador) {
            $marcadores[] = [
                'id'    => (int) $marcador->getId(),
                'nome'  => $marcador->getNome(),
                'cor'   => $marcador->getCor(),
                'paiId' => $marcador->getPai()?->getId(),
            ];
        }

        return new OpcoesDoAgenteOutput(
            responsaveis: $responsaveis,
            marcadores: $marcadores,
            situacoes: [
                ['valor' => Pasta::SITUACAO_ATIVA, 'rotulo' => 'Ativo'],
                ['valor' => Pasta::SITUACAO_ARQUIVADA, 'rotulo' => 'Arquivado'],
            ],
            prioridades: array_map(
                static fn (PrioridadePasta $p): array => ['valor' => $p->value, 'rotulo' => $p->label()],
                PrioridadePasta::cases(),
            ),
        );
    }
}
