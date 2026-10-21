<?php

declare(strict_types=1);

namespace App\Agenda\Service;

use App\Agenda\Entity\ConexaoGoogleAgenda;
use App\Agenda\Entity\Evento;
use App\Agenda\Entity\LigacaoComGoogle;
use App\Agenda\Exception\EventoRecusadoPeloGoogleException;
use App\Agenda\Infra\GoogleAgendaApi;
use App\Agenda\Repository\EventoDaAgendaRepository;
use App\Agenda\Repository\ExclusaoNoGoogleRepository;
use App\Agenda\Repository\LigacaoComGoogleRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * O lado BlueJus → Google da sincronia de uma conexão (modelo do dono de 20/10/2026): apaga lá o que foi
 * excluído aqui, leva os compromissos de que a pessoa é criadora ou participante que mudaram desde o último
 * envio (a cópia do participante com o aviso de só leitura), e tira da agenda dela as cópias de compromissos
 * em que deixou de participar. Um evento que o Google recusa fica de fora até mudar; os outros seguem.
 */
final class LevarAoGoogle
{
    private const PRIMEIRA_VEZ = '-30 days';

    public function __construct(
        private readonly GoogleAgendaApi $google,
        private readonly CompromissoNoGoogle $traducao,
        private readonly EventoDaAgendaRepository $eventos,
        private readonly ExclusaoNoGoogleRepository $exclusoes,
        private readonly LigacaoComGoogleRepository $ligacoes,
        private readonly EntityManagerInterface $em,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function apagarPendentes(ConexaoGoogleAgenda $conexao, string $acesso): int
    {
        $pendentes = $this->exclusoes->pendentesDaPessoa($conexao->getUsuario(), $conexao->getTenant());
        foreach ($pendentes as $exclusao) {
            try {
                $this->google->apagar($acesso, (string) $conexao->getCalendarioId(), $exclusao->getGoogleId());
            } catch (EventoRecusadoPeloGoogleException $e) {
                $this->logger->info('Google Agenda: exclusão recusada, deixada de lado', ['motivo' => $e->getMessage()]);
            }
            $this->exclusoes->concluir($exclusao);
            $this->em->flush();
        }

        return count($pendentes);
    }

    public function enviar(ConexaoGoogleAgenda $conexao, string $acesso, \DateTimeImmutable $agora, ?\DateTimeImmutable $mudouDesde): int
    {
        $enviados = 0;
        foreach ($this->eventos->daPessoaParaOGoogle($conexao->getTenant(), $conexao->getUsuario(), $agora->modify(self::PRIMEIRA_VEZ), $mudouDesde) as $evento) {
            $enviados += $this->enviarUm($conexao, $acesso, $evento) ? 1 : 0;
        }

        return $enviados;
    }

    /** Cópias de compromissos em que a pessoa deixou de participar (ou que viraram "Só eu") saem da agenda dela. */
    public function tirarCopiasQueNaoValemMais(ConexaoGoogleAgenda $conexao, string $acesso): int
    {
        $copias = $this->ligacoes->copiasQueNaoValemMais($conexao);
        foreach ($copias as $ligacao) {
            try {
                $this->google->apagar($acesso, (string) $conexao->getCalendarioId(), $ligacao->getGoogleId());
            } catch (EventoRecusadoPeloGoogleException $e) {
                $this->logger->info('Google Agenda: cópia não apagada, deixada de lado', ['motivo' => $e->getMessage()]);
            }
            $this->ligacoes->remover($ligacao);
            $this->em->flush();
        }

        return count($copias);
    }

    private function enviarUm(ConexaoGoogleAgenda $conexao, string $acesso, Evento $evento): bool
    {
        $copia = $evento->getCriador()?->getId() !== $conexao->getUsuario()->getId();
        $resumo = $this->traducao->resumo($evento, $copia);
        $ligacao = $this->ligacoes->daConexaoPeloEvento($conexao, $evento);
        if ($this->naoPrecisa($ligacao, $resumo, $evento)) {
            return false;
        }
        $id = $ligacao?->getGoogleId() ?? $this->traducao->idPara($evento);
        $ligacao ??= new LigacaoComGoogle($conexao->getTenant(), $conexao, $evento, $id);
        try {
            $this->google->gravar($acesso, (string) $conexao->getCalendarioId(), $id, $this->traducao->corpo($evento, $copia), null !== $ligacao->getId());
            $ligacao->sincronizou($resumo);
        } catch (EventoRecusadoPeloGoogleException $e) {
            $this->logger->info('Google Agenda: evento recusado, fica de fora até mudar', ['evento' => $evento->getId(), 'motivo' => $e->getMessage()]);
            $ligacao->recusada($resumo);
        }
        $this->ligacoes->salvar($ligacao);
        $this->em->flush();

        return $resumo === $ligacao->getHash();
    }

    /** Igual ao último envio, recusado nesta versão, só do Google, ou cancelado que nunca foi: não vai. */
    private function naoPrecisa(?LigacaoComGoogle $ligacao, string $resumo, Evento $evento): bool
    {
        if (null === $ligacao) {
            return Evento::STATUS_CANCELADO === $evento->getStatus();
        }

        return in_array($ligacao->getHash(), [CompromissoNoGoogle::SO_DO_GOOGLE, $resumo, LigacaoComGoogle::RECUSADO . $resumo], true);
    }
}
