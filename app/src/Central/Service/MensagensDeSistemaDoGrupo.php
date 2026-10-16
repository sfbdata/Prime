<?php

declare(strict_types=1);

namespace App\Central\Service;

use App\Central\Entity\Conversa;
use App\Central\Entity\Mensagem;
use App\Central\Repository\MensagemRepository;
use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;

/**
 * As linhas de sistema do grupo ("Ana adicionou Bruno" — desenho `sistemaGrupo`, L346), gravadas na própria conversa:
 * quem participa vê o que mudou, pelo mesmo polling. Texto montado aqui, nunca vindo do pedido. Só persiste.
 */
final class MensagensDeSistemaDoGrupo
{
    public function __construct(
        private readonly MensagemRepository $mensagens,
    ) {
    }

    public function criou(Conversa $grupo, User $quem, Tenant $tenant, \DateTimeImmutable $em): void
    {
        $this->registrar($grupo, $quem, $tenant, sprintf('%s criou o grupo', PrimeiroNome::de($quem)), $em);
    }

    public function adicionou(Conversa $grupo, User $quem, User $adicionado, Tenant $tenant, \DateTimeImmutable $em): void
    {
        $this->registrar($grupo, $quem, $tenant, sprintf('%s adicionou %s', PrimeiroNome::de($quem), PrimeiroNome::de($adicionado)), $em);
    }

    public function removeu(Conversa $grupo, User $quem, User $removido, Tenant $tenant, \DateTimeImmutable $em): void
    {
        $this->registrar($grupo, $quem, $tenant, sprintf('%s removeu %s', PrimeiroNome::de($quem), PrimeiroNome::de($removido)), $em);
    }

    public function saiu(Conversa $grupo, User $quem, Tenant $tenant, \DateTimeImmutable $em): void
    {
        $this->registrar($grupo, $quem, $tenant, sprintf('%s saiu do grupo', PrimeiroNome::de($quem)), $em);
    }

    public function renomeou(Conversa $grupo, User $quem, Tenant $tenant, \DateTimeImmutable $em): void
    {
        $this->registrar($grupo, $quem, $tenant, sprintf('%s renomeou o grupo para "%s"', PrimeiroNome::de($quem), (string) $grupo->getNome()), $em);
    }

    private function registrar(Conversa $grupo, User $quem, Tenant $tenant, string $texto, \DateTimeImmutable $em): void
    {
        $this->mensagens->salvar(Mensagem::deSistema($tenant, $grupo, $quem, $texto, $em));
        $grupo->registrarMensagem($em);
    }
}
