<?php

declare(strict_types=1);

namespace App\Agenda\UseCase;

use App\Agenda\Entity\ConexaoGoogleAgenda;
use App\Agenda\Exception\GoogleAgendaException;
use App\Agenda\Infra\GoogleAgendaApi;
use App\Agenda\Repository\ConexaoGoogleAgendaRepository;
use App\Agenda\Repository\ExclusaoNoGoogleRepository;
use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Sync\Service\CifradorDeSegredo;
use Doctrine\ORM\EntityManagerInterface;

/**
 * História: a pessoa revoga o consentimento (LGPD, art. 8º, § 5º) clicando em "Desconectar" e responde se quer
 * apagar também a agenda do escritório no Google dela ("Não, manter" vem marcado; decisão c do dono). Mantida,
 * a agenda fica lá parada. A autorização é revogada no Google, a menos que a mesma conta esteja ligada em outro
 * escritório (revogar derrubaria a de lá). A conexão sai daqui com as ligações e as exclusões pendentes. Os
 * compromissos já trazidos ficam na Agenda.
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

    public function executar(Tenant $tenant, User $eu, bool $apagarAgenda): void
    {
        $conexao = $this->conexoes->daPessoa($eu, $tenant);
        if (null === $conexao) {
            return;
        }
        $refresh = $this->refresh($conexao);
        if ($apagarAgenda && null !== $refresh) {
            $this->apagarAgenda($conexao, $refresh);
        }
        if (null !== $refresh && !$this->conexoes->contaLigadaEmOutraConexao($conexao)) {
            $this->google->revogar($refresh);
        }
        foreach ($this->exclusoes->pendentesDaPessoa($eu, $tenant) as $exclusao) {
            $this->exclusoes->concluir($exclusao);
        }
        $this->em->remove($conexao);
        $this->em->flush();
    }

    /** Token ilegível: não há o que revogar nem apagar lá; a conexão sai daqui mesmo assim. */
    private function refresh(ConexaoGoogleAgenda $conexao): ?string
    {
        try {
            return $this->cifrador->decifrar($conexao->getRefreshTokenCifrado());
        } catch (\Throwable) {
            return null;
        }
    }

    /** Falha no Google não impede desconectar aqui (a agenda fica lá, parada). */
    private function apagarAgenda(ConexaoGoogleAgenda $conexao, string $refresh): void
    {
        $calendario = $conexao->getCalendarioId();
        if (null === $calendario) {
            return;
        }
        try {
            $this->google->apagarAgenda($this->google->renovar($refresh), $calendario);
        } catch (GoogleAgendaException) {
        }
    }
}
