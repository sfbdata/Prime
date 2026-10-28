<?php

declare(strict_types=1);

namespace App\Tenant\UseCase;

use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Shared\Armazenamento\RemocaoAposTransacao;
use App\Tenant\Armazenamento\ChavesDeTenant;
use App\Tenant\Exception\AcessoAoEscritorioNegado;
use App\Tenant\Service\GuardaDaEdicaoDoEscritorio;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;

/** "Remover logo" da tela Meu Escritório. Só o primário ou o Master; o arquivo sai do armazenamento DEPOIS do COMMIT. */
final class RemoverLogoDoEscritorioUseCase
{
    public function __construct(
        private readonly GuardaDaEdicaoDoEscritorio $guarda,
        private readonly EntityManagerInterface $em,
        private readonly RemocaoAposTransacao $remocao,
        private readonly ClockInterface $relogio,
    ) {
    }

    /** @throws AcessoAoEscritorioNegado */
    public function executar(User $ator, Tenant $tenant): void
    {
        $this->guarda->exigir($ator, $tenant);

        $chave = ChavesDeTenant::logo($tenant);
        if ($chave === null) {
            return;
        }
        $tenant->setLogoChave(null)->carimbarAlteracao($ator, \DateTimeImmutable::createFromInterface($this->relogio->now()));
        $this->em->flush();

        $this->remocao->remover([$chave], 'tenant_logo_removida');
    }
}
