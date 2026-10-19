<?php

declare(strict_types=1);

namespace App\Agenda\UseCase;

use App\Agenda\Entity\ConexaoGoogleAgenda;
use App\Agenda\Entity\Evento;
use App\Agenda\Enum\TipoDeCompromisso;
use App\Agenda\Exception\AutorizacaoDoGoogleRecusadaException;
use App\Agenda\Exception\EventoRecusadoPeloGoogleException;
use App\Agenda\Exception\GoogleAgendaException;
use App\Agenda\Exception\SyncTokenVencidoException;
use App\Agenda\Infra\GoogleAgendaApi;
use App\Agenda\Repository\ConexaoGoogleAgendaRepository;
use App\Agenda\Repository\EventoDaAgendaRepository;
use App\Agenda\Repository\ExclusaoNoGoogleRepository;
use App\Agenda\Service\CompromissoNoGoogle;
use App\Agenda\Service\SilencioDaSincronia;
use App\Service\PermissionChecker;
use App\Sync\Service\CifradorDeSegredo;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * História: a pessoa conectou o Google Agenda e quer os compromissos iguais nos dois lados, sem fazer
 * nada. A cada 5 min (e logo depois de cada mudança na Agenda) o worker sincroniza a agenda dela:
 * 1. traz do Google o que mudou desde a última vez (syncToken); cancelado lá vira "cancelado" aqui, o
 *    registro fica; o que é novo entra como "Só eu". O eco do nosso próprio envio não sobrescreve nada
 *    (a pessoa pode ter editado aqui depois de enviar); mudança de verdade no Google vale (edição auditada);
 * 2. leva ao Google as exclusões pendentes e os compromissos que ela criou e mudaram desde o último envio.
 *    Um evento que o Google recusa fica de fora até mudar; os outros seguem.
 * Uma sincronia por pessoa de cada vez (trava no banco). Sem acesso à Agenda no escritório (saiu, perdeu a
 * permissão), a conexão é desfeita. Autorização revogada na conta Google: o painel volta a "Não conectado".
 */
final class SincronizarGoogleAgendaUseCase
{
    private const PRIMEIRA_VEZ = '-30 days';
    private const RECUSADO = 'x:';
    /** Folga ao procurar compromissos antigos mudados desde a última sincronia (relógios e transações). */
    private const FOLGA = '-1 hour';

    public function __construct(
        private readonly ConexaoGoogleAgendaRepository $conexoes,
        private readonly EventoDaAgendaRepository $eventos,
        private readonly ExclusaoNoGoogleRepository $exclusoes,
        private readonly GoogleAgendaApi $google,
        private readonly CompromissoNoGoogle $traducao,
        private readonly CifradorDeSegredo $cifrador,
        private readonly SilencioDaSincronia $silencio,
        private readonly PermissionChecker $permissoes,
        private readonly DesconectarGoogleAgendaUseCase $desconectar,
        private readonly EntityManagerInterface $em,
        private readonly LoggerInterface $logger,
    ) {
    }

    /** @return array{trazidos: int, enviados: int, apagados: int}|null nulo: outra sincronia da pessoa está rodando */
    public function executar(int $conexaoId, \DateTimeImmutable $agora): ?array
    {
        $nada = ['trazidos' => 0, 'enviados' => 0, 'apagados' => 0];
        $conexao = $this->conexoes->find($conexaoId);
        if (null === $conexao || !$this->google->ligado()) {
            return $nada;
        }
        if (!$this->permissoes->canAccessModule($conexao->getUsuario(), $conexao->getTenant(), 'agenda')) {
            $this->desconectar->executar($conexao->getTenant(), $conexao->getUsuario());

            return $nada;
        }
        if (!$this->conexoes->ocupar($conexaoId, $agora)) {
            return null;
        }

        return $this->sincronizarComTrava($conexao, $agora) ?? $nada;
    }

    /** @return array{trazidos: int, enviados: int, apagados: int}|null */
    private function sincronizarComTrava(ConexaoGoogleAgenda $conexao, \DateTimeImmutable $agora): ?array
    {
        try {
            return $this->silencio->durante(fn (): array => $this->sincronizar($conexao, $agora));
        } catch (AutorizacaoDoGoogleRecusadaException) {
            $conexao->falhou(ConexaoGoogleAgenda::ERRO_AUTORIZACAO);
        } catch (GoogleAgendaException $e) {
            $conexao->falhou($e->getMessage());
            $this->logger->warning('Google Agenda: sincronia falhou', ['conexao' => $conexao->getId(), 'motivo' => $e->getMessage()]);
        } finally {
            $this->conexoes->liberar((int) $conexao->getId());
        }
        $this->conexoes->salvar($conexao);

        return null;
    }

    /** @return array{trazidos: int, enviados: int, apagados: int} */
    private function sincronizar(ConexaoGoogleAgenda $conexao, \DateTimeImmutable $agora): array
    {
        $acesso = $this->google->renovar($this->refreshToken($conexao));
        $ultima = $conexao->getUltimaSincronia();
        [$trazidos, $syncToken] = $this->trazer($conexao, $acesso, $agora);
        $apagados = $this->apagarPendentes($conexao, $acesso);
        $enviados = $this->enviar($conexao, $acesso, $agora, $ultima?->modify(self::FOLGA));
        $conexao->sincronizou($syncToken, $agora);
        $this->conexoes->salvar($conexao);

        return ['trazidos' => $trazidos, 'enviados' => $enviados, 'apagados' => $apagados];
    }

    /** Token que não decifra (chave do servidor trocada): só conectando de novo. */
    private function refreshToken(ConexaoGoogleAgenda $conexao): string
    {
        try {
            return $this->cifrador->decifrar($conexao->getRefreshTokenCifrado());
        } catch (\Throwable $e) {
            throw new AutorizacaoDoGoogleRecusadaException('Autorização guardada ilegível.', 0, $e);
        }
    }

    /** @return array{int, ?string} quantos vieram e o novo syncToken */
    private function trazer(ConexaoGoogleAgenda $conexao, string $acesso, \DateTimeImmutable $agora): array
    {
        $desde = $agora->modify(self::PRIMEIRA_VEZ);
        try {
            $mudancas = $this->google->mudancas($acesso, $conexao->getSyncToken(), $desde);
        } catch (SyncTokenVencidoException) {
            $conexao->recomecar();
            $mudancas = $this->google->mudancas($acesso, null, $desde);
        }
        $trazidos = 0;
        foreach ($mudancas['itens'] as $item) {
            $trazidos += $this->trazerUm($conexao, $item) ? 1 : 0;
        }
        $this->em->flush();

        return [$trazidos, $mudancas['syncToken']];
    }

    /** @param array<string, mixed> $item */
    private function trazerUm(ConexaoGoogleAgenda $conexao, array $item): bool
    {
        $id = is_string($item['id'] ?? null) ? $item['id'] : '';
        if ('' === $id || $this->ignorar($conexao, $item, $id)) {
            return false;
        }
        $evento = $this->eventos->doGoogle($conexao->getTenant(), $conexao->getUsuario(), $id);
        if ((null === $evento && 'cancelled' === ($item['status'] ?? null)) || (null !== $evento && $this->traducao->ehEcoDoEnvio($item, $evento))) {
            return false;
        }
        $evento ??= $this->novoDoGoogle($conexao);
        $cabe = $this->traducao->aplicar($item, $evento) && !$this->traducao->soDeLeitura($item);
        $evento->sincronizadoComGoogle($id, $cabe ? $this->traducao->resumo($evento) : CompromissoNoGoogle::SO_DO_GOOGLE);
        $this->em->persist($evento);

        return true;
    }

    /**
     * Fica de fora: ocorrência alterada de série, aniversário/local de trabalho/ausência, e id nosso de
     * outro escritório (a mesma conta Google ligada em dois escritórios não mistura os dados deles).
     *
     * @param array<string, mixed> $item
     */
    private function ignorar(ConexaoGoogleAgenda $conexao, array $item, string $id): bool
    {
        return $this->traducao->ehOcorrenciaDeSerie($item) || $this->traducao->ehDeOutroTipo($item)
            || $this->traducao->ehDeOutroEscritorio($id, (int) $conexao->getTenant()->getId());
    }

    /** O que nasce no Google entra como compromisso pessoal e particular ("Só eu"). */
    private function novoDoGoogle(ConexaoGoogleAgenda $conexao): Evento
    {
        return (new Evento())
            ->setTenant($conexao->getTenant())
            ->setCriador($conexao->getUsuario())
            ->setTipo(TipoDeCompromisso::Meus)
            ->setCor(TipoDeCompromisso::Meus->cor())
            ->setVisibilidade(Evento::VISIBILIDADE_SOMENTE_EU)
            ->setStatus(Evento::STATUS_AGENDADO);
    }

    private function apagarPendentes(ConexaoGoogleAgenda $conexao, string $acesso): int
    {
        $pendentes = $this->exclusoes->pendentesDaPessoa($conexao->getUsuario(), $conexao->getTenant());
        foreach ($pendentes as $exclusao) {
            try {
                $this->google->apagar($acesso, $exclusao->getGoogleId());
            } catch (EventoRecusadoPeloGoogleException $e) {
                $this->logger->info('Google Agenda: exclusão recusada, deixada de lado', ['motivo' => $e->getMessage()]);
            }
            $this->exclusoes->concluir($exclusao);
            $this->em->flush();
        }

        return count($pendentes);
    }

    private function enviar(ConexaoGoogleAgenda $conexao, string $acesso, \DateTimeImmutable $agora, ?\DateTimeImmutable $mudouDesde): int
    {
        $enviados = 0;
        foreach ($this->eventos->criadosPorParaOGoogle($conexao->getTenant(), $conexao->getUsuario(), $agora->modify(self::PRIMEIRA_VEZ), $mudouDesde) as $evento) {
            $enviados += $this->enviarUm($acesso, $evento) ? 1 : 0;
        }

        return $enviados;
    }

    private function enviarUm(string $acesso, Evento $evento): bool
    {
        $resumo = $this->traducao->resumo($evento);
        $guardado = $evento->getGoogleHash();
        $nuncaFoi = null === $evento->getGoogleId();
        if (in_array($guardado, [CompromissoNoGoogle::SO_DO_GOOGLE, $resumo, self::RECUSADO . $resumo], true)
            || ($nuncaFoi && Evento::STATUS_CANCELADO === $evento->getStatus())) {
            return false;
        }
        $id = $this->traducao->idPara($evento);
        try {
            $this->google->gravar($acesso, $id, $this->traducao->corpo($evento), !$nuncaFoi);
            $evento->sincronizadoComGoogle($id, $resumo);
        } catch (EventoRecusadoPeloGoogleException $e) {
            $this->logger->info('Google Agenda: evento recusado, fica de fora até mudar', ['evento' => $evento->getId(), 'motivo' => $e->getMessage()]);
            $evento->recusadoPeloGoogle(self::RECUSADO . $resumo);
        }
        $this->em->flush();

        return null !== $evento->getGoogleId() && $resumo === $evento->getGoogleHash();
    }
}
