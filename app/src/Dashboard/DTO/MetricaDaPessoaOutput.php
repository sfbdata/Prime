<?php

declare(strict_types=1);

namespace App\Dashboard\DTO;

/**
 * Um número do perfil da pessoa (Recebidas, Concluídas, Pendentes, Atrasadas, Prazos próximos, Taxa).
 *
 * `valor` nulo = sem base (a tela mostra "Sem dado"). `statusDoLink` é o `status` da lista /tarefas/equipe
 * que conta o MESMO número (regra B12); nulo = o número não tem lista equivalente, então não é link.
 * `comPeriodo` diz se o link leva o período do painel (Atrasadas e Prazos são de hoje, sem período).
 */
final readonly class MetricaDaPessoaOutput
{
    public function __construct(
        public string $chave,
        public ?int $valor,
        public bool $porcentagem = false,
        public ?string $statusDoLink = null,
        public bool $comPeriodo = false,
    ) {
    }

    public function temLink(): bool
    {
        return $this->statusDoLink !== null && ($this->valor ?? 0) > 0;
    }
}
