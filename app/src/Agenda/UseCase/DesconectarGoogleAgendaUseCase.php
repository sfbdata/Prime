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
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * História: a pessoa revoga o consentimento (LGPD, art. 8º, § 5º) clicando em "Desconectar" e responde se quer
 * apagar também a agenda do escritório no Google dela ("Não, manter" vem marcado; decisão c do dono). Mantida,
 * a agenda fica lá parada. A autorização é revogada no Google, a menos que a mesma conta esteja ligada em outro
 * escritório (revogar derrubaria a de lá). A conexão sai daqui com as ligações e as exclusões pendentes. Os
 * compromissos já trazidos ficam na Agenda. Pega a trava da conexão: uma sincronia rodando agora regravaria o que
 * está saindo.
 *
 * `todasDaPessoa` e `todasDoEscritorio` desfazem em lote (exclusão de conta, suspensão, purga do escritório); ficam
 * prontas para quando o dono decidir ligá-las nesses fluxos (revisão da b5, item 2).
 */
final class DesconectarGoogleAgendaUseCase
{
    public function __construct(
        private readonly ConexaoGoogleAgendaRepository $conexoes,
        private readonly ExclusaoNoGoogleRepository $exclusoes,
        private readonly GoogleAgendaApi $google,
        private readonly CifradorDeSegredo $cifrador,
        private readonly EntityManagerInterface $em,
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {
    }

    public function executar(Tenant $tenant, User $eu, bool $apagarAgenda): void
    {
        $conexao = $this->conexoes->daPessoa($eu, $tenant);
        if (null === $conexao) {
            return;
        }
        $id = (int) $conexao->getId();
        if (!$this->conexoes->ocupar($id, new \DateTimeImmutable())) {
            throw new GoogleAgendaException('A agenda está sincronizando agora; tente desconectar de novo em instantes.');
        }
        try {
            $this->desfazer($conexao, $apagarAgenda);
        } finally {
            $this->conexoes->liberar($id);
        }
    }

    /** Todas as conexões da pessoa, em todos os escritórios. Em lote não espera a trava: a conexão sai de todo jeito. */
    public function todasDaPessoa(User $pessoa, bool $apagarAgenda): void
    {
        foreach ($this->conexoes->daPessoaEmTodos($pessoa) as $conexao) {
            $this->desfazerSemParar($conexao, $apagarAgenda);
        }
    }

    /** Todas as conexões do escritório. Em lote não espera a trava: a conexão sai de todo jeito. */
    public function todasDoEscritorio(Tenant $tenant, bool $apagarAgenda): void
    {
        foreach ($this->conexoes->doEscritorio($tenant) as $conexao) {
            $this->desfazerSemParar($conexao, $apagarAgenda);
        }
    }

    /** Em lote, uma conexão que falha não segura as outras. */
    private function desfazerSemParar(ConexaoGoogleAgenda $conexao, bool $apagarAgenda): void
    {
        try {
            $this->desfazer($conexao, $apagarAgenda);
        } catch (\Throwable $e) {
            $this->logger->warning('Google Agenda: conexão não desfeita no lote', ['conexao' => $conexao->getId(), 'motivo' => $e->getMessage()]);
        }
    }

    private function desfazer(ConexaoGoogleAgenda $conexao, bool $apagarAgenda): void
    {
        $refresh = $this->refresh($conexao);
        if ($apagarAgenda && null !== $refresh) {
            $this->apagarAgenda($conexao, $refresh);
        }
        if (null !== $refresh && !$this->conexoes->contaLigadaEmOutraConexao($conexao)) {
            $this->google->revogar($refresh);
        }
        foreach ($this->exclusoes->pendentesDaPessoa($conexao->getUsuario(), $conexao->getTenant()) as $exclusao) {
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
