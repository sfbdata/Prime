<?php

declare(strict_types=1);

namespace App\Master\DTO;

use App\Entity\Auth\User;

/**
 * "Editar limite de armazenamento" ou "Definir limite de usuários" no menu da conta do Painel do Master
 * (desenho 05 - Perfis 1.2, linhas 397-410 e 726-732; spec docs/specs/perfis-1.2-planos-e-limites.md P4).
 *
 * `valor` é GB (armazenamento) ou pessoas (usuários); nulo quando o campo veio vazio ou não é um número
 * inteiro. `semLimite` só vale para o armazenamento (contrato com a Contribua: libera espaço e usuários).
 */
final readonly class LimiteDaContaInput
{
    public const ARMAZENAMENTO = 'armazenamento';
    public const USUARIOS = 'usuarios';

    public function __construct(
        /** Quem confirma no painel: um Master (primário ou secundário). */
        public User $ator,
        /** Id do escritório (rota /master/contas/{id}/...). */
        public int $contaId,
        /** {@see self::ARMAZENAMENTO} ou {@see self::USUARIOS}. */
        public string $tipo,
        public ?int $valor,
        public bool $semLimite,
        public string $justificativa,
    ) {
    }
}
