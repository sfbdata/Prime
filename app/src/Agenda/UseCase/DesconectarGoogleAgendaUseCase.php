<?php

declare(strict_types=1);

namespace App\Agenda\UseCase;

use App\Agenda\Infra\GoogleAgendaApi;
use App\Agenda\Repository\ConexaoGoogleAgendaRepository;
use App\Agenda\Repository\ExclusaoNoGoogleRepository;
use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Sync\Service\CifradorDeSegredo;
use Doctrine\ORM\EntityManagerInterface;

/**
 * História: a pessoa revoga o consentimento (LGPD, art. 8º, § 5º) clicando em "Desconectar". A
 * autorização é revogada também na conta Google e a conexão é apagada daqui, com as exclusões pendentes.
 * Os compromissos já trazidos ficam na Agenda; nada é apagado no Google.
 */
final class DesconectarGoogleAgendaUseCase
{
    public function __construct(
        private readonly ConexaoGoogleAgendaRepository $conexoes,
        private readonly ExclusaoNoGoogleRepository $exclusoes,
        private readonly GoogleAgendaApi $google,
        private readonly CifradorDeSegredo $cifrador,
        private readonly EntityManagerInterface $em,
    ) {
    }

    public function executar(Tenant $tenant, User $eu): void
    {
        $conexao = $this->conexoes->daPessoa($eu, $tenant);
        if (null === $conexao) {
            return;
        }
        $this->revogarNoGoogle($conexao->getRefreshTokenCifrado());
        foreach ($this->exclusoes->pendentesDaPessoa($eu, $tenant) as $exclusao) {
            $this->exclusoes->concluir($exclusao);
        }
        $this->em->remove($conexao);
        $this->em->flush();
    }

    private function revogarNoGoogle(string $cifrado): void
    {
        try {
            $this->google->revogar($this->cifrador->decifrar($cifrado));
        } catch (\Throwable) {
            // Token ilegível: não há o que revogar lá; a conexão sai daqui mesmo assim.
        }
    }
}
