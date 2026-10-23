<?php

declare(strict_types=1);

namespace App\Agenda\UseCase;

use App\Agenda\Entity\ConexaoGoogleAgenda;
use App\Agenda\Entity\Evento;
use App\Agenda\Entity\LigacaoComGoogle;
use App\Agenda\Enum\TipoDeCompromisso;
use App\Agenda\Exception\AgendaDoEscritorioSumiuException;
use App\Agenda\Exception\AutorizacaoDoGoogleRecusadaException;
use App\Agenda\Exception\GoogleAgendaException;
use App\Agenda\Exception\SyncTokenVencidoException;
use App\Agenda\Infra\GoogleAgendaApi;
use App\Agenda\Repository\ConexaoGoogleAgendaRepository;
use App\Agenda\Repository\LigacaoComGoogleRepository;
use App\Agenda\Repository\DataNoGoogleRepository;
use App\Agenda\Repository\PrazoNoGoogleRepository;
use App\Agenda\Service\CompromissoNoGoogle;
use App\Agenda\Service\LevarAoGoogle;
use App\Agenda\Service\LevarDatasAoGoogle;
use App\Agenda\Service\LevarPrazosAoGoogle;
use App\Agenda\Service\ParticipantesDoGoogle;
use App\Agenda\Service\SilencioDaSincronia;
use App\Service\PermissionChecker;
use App\Sync\Service\CifradorDeSegredo;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * História: a pessoa conectou o Google Agenda e quer os compromissos iguais nos dois lados, sem fazer nada
 * (modelo do dono de 20/10/2026). A cada 5 min (e logo depois de cada mudança na Agenda) o worker sincroniza a
 * agenda do escritório na conta dela, criada pelo BlueJus com o nome do escritório:
 * 1. garante a agenda (cria se não houver; sumiu lá, cria outra e manda tudo de novo);
 * 2. traz o que mudou nela (syncToken). Criado lá entra visível à equipe, com os convidados da equipe como
 *    participantes; cancelado lá vira "cancelado" aqui, o registro fica. Na cópia de compromisso de outra
 *    pessoa (só leitura), mudança feita lá é desfeita no próximo envio; apagada lá, fica apagada até mudar aqui;
 * 3. leva ao Google as exclusões, o que mudou e tira as cópias que não valem mais (`LevarAoGoogle`);
 * 4. leva os prazos e audiências das pastas a quem tem "Ver prazos na agenda", só leitura (`LevarPrazosAoGoogle`);
 * 5. leva as datas do sistema (feriados, recessos, tribunais, comemorativas, aniversários), só leitura (`LevarDatasAoGoogle`).
 * Uma sincronia por conexão de cada vez (trava no banco). Sem acesso à Agenda no escritório, a conexão é
 * desfeita. Autorização revogada na conta Google: o painel volta a "Não conectado".
 */
final class SincronizarGoogleAgendaUseCase
{
    private const PRIMEIRA_VEZ = '-30 days';
    /**
     * Quem perdeu a Agenda no escritório (saiu, mudou de papel): apagar também a agenda do escritório na conta
     * Google dela? Com o dono (revisão da b5, item 1; recomendação do coordenador: sim). Virar é esta linha.
     */
    private const APAGAR_AGENDA_DE_QUEM_SAI = false;
    /** Folga ao procurar compromissos antigos mudados desde a última sincronia (relógios e transações). */
    private const FOLGA = '-1 hour';

    public function __construct(
        private readonly ConexaoGoogleAgendaRepository $conexoes,
        private readonly LigacaoComGoogleRepository $ligacoes,
        private readonly GoogleAgendaApi $google,
        private readonly CompromissoNoGoogle $traducao,
        private readonly LevarAoGoogle $levar,
        private readonly ParticipantesDoGoogle $participantes,
        private readonly CifradorDeSegredo $cifrador,
        private readonly SilencioDaSincronia $silencio,
        private readonly PermissionChecker $permissoes,
        private readonly DesconectarGoogleAgendaUseCase $desconectar,
        private readonly EntityManagerInterface $em,
        private readonly LoggerInterface $logger,
        private readonly LevarPrazosAoGoogle $levarPrazos,
        private readonly PrazoNoGoogleRepository $prazosNoGoogle,
        private readonly LevarDatasAoGoogle $levarDatas,
        private readonly DataNoGoogleRepository $datasNoGoogle,
    ) {
    }

    /** @return array{trazidos: int, enviados: int, apagados: int}|null nulo: outra sincronia da conexão está rodando */
    public function executar(int $conexaoId, \DateTimeImmutable $agora): ?array
    {
        $nada = ['trazidos' => 0, 'enviados' => 0, 'apagados' => 0];
        $conexao = $this->conexoes->find($conexaoId);
        if (null === $conexao || !$this->google->ligado()) {
            return $nada;
        }
        if (!$this->permissoes->canAccessModule($conexao->getUsuario(), $conexao->getTenant(), 'agenda')) {
            try {
                $this->desconectar->executar($conexao->getTenant(), $conexao->getUsuario(), self::APAGAR_AGENDA_DE_QUEM_SAI);
            } catch (GoogleAgendaException) {
                // Sincronia rodando agora: a próxima volta desconecta.
            }

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
        } catch (AgendaDoEscritorioSumiuException) {
            $this->em->clear();
            $this->agendaSumiu((int) $conexao->getId());
        } catch (AutorizacaoDoGoogleRecusadaException) {
            $conexao->falhou(ConexaoGoogleAgenda::ERRO_AUTORIZACAO);
            $this->conexoes->salvar($conexao);
        } catch (GoogleAgendaException $e) {
            $conexao->falhou($e->getMessage());
            $this->conexoes->salvar($conexao);
            $this->logger->warning('Google Agenda: sincronia falhou', ['conexao' => $conexao->getId(), 'motivo' => $e->getMessage()]);
        } finally {
            $this->conexoes->liberar((int) $conexao->getId());
        }

        return null;
    }

    /** @return array{trazidos: int, enviados: int, apagados: int} */
    private function sincronizar(ConexaoGoogleAgenda $conexao, \DateTimeImmutable $agora): array
    {
        $acesso = $this->google->renovar($this->refreshToken($conexao));
        $this->garantirAgenda($conexao, $acesso);
        $ultima = $conexao->getUltimaSincronia();
        [$trazidos, $syncToken] = $this->trazer($conexao, $acesso, $agora);
        $apagados = $this->levar->apagarPendentes($conexao, $acesso);
        $enviados = $this->levar->enviar($conexao, $acesso, $agora, $ultima?->modify(self::FOLGA));
        $apagados += $this->levar->tirarCopiasQueNaoValemMais($conexao, $acesso);
        $prazos = $this->levarPrazos->levar($conexao, $acesso, $agora);
        $datas = $this->levarDatas->levar($conexao, $acesso, $agora);
        $enviados += $prazos['enviados'] + $datas['enviados'];
        $apagados += $prazos['apagados'] + $datas['apagados'];
        $conexao->sincronizou($syncToken, $agora);
        $this->conexoes->salvar($conexao);

        return ['trazidos' => $trazidos, 'enviados' => $enviados, 'apagados' => $apagados];
    }

    /** Sem agenda do escritório na conta (primeira vez, ou sumiu lá): cria e manda tudo de novo. */
    private function garantirAgenda(ConexaoGoogleAgenda $conexao, string $acesso): void
    {
        if (null !== $conexao->getCalendarioId()) {
            return;
        }
        $conexao->agendaCriada($this->google->criarAgenda($acesso, (string) $conexao->getTenant()->getName()));
        $this->ligacoes->esquecerDaConexao($conexao);
        $this->prazosNoGoogle->esquecerDaConexao($conexao);
        $this->datasNoGoogle->esquecerDaConexao($conexao);
        $this->conexoes->salvar($conexao);
    }

    /** A pessoa apagou a agenda do escritório no Google: a próxima volta cria outra e manda tudo de novo. */
    private function agendaSumiu(int $conexaoId): void
    {
        $conexao = $this->conexoes->find($conexaoId);
        if (null === $conexao) {
            return;
        }
        $conexao->agendaSumiu();
        $this->ligacoes->esquecerDaConexao($conexao);
        $this->prazosNoGoogle->esquecerDaConexao($conexao);
        $this->datasNoGoogle->esquecerDaConexao($conexao);
        $this->conexoes->salvar($conexao);
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
        $calendario = (string) $conexao->getCalendarioId();
        try {
            $mudancas = $this->google->mudancas($acesso, $calendario, $conexao->getSyncToken(), $desde);
        } catch (SyncTokenVencidoException) {
            $conexao->recomecar();
            $mudancas = $this->google->mudancas($acesso, $calendario, null, $desde);
        }
        // O mesmo evento duas vezes na listagem: vale a última versão (uma ligação por evento).
        $porId = [];
        foreach ($mudancas['itens'] as $item) {
            $porId[is_string($item['id'] ?? null) ? $item['id'] : ''] = $item;
        }
        $trazidos = 0;
        foreach ($porId as $item) {
            $trazidos += $this->trazerUm($conexao, $item) ? 1 : 0;
        }
        $this->em->flush();

        return [$trazidos, $mudancas['syncToken']];
    }

    /** @param array<string, mixed> $item */
    private function trazerUm(ConexaoGoogleAgenda $conexao, array $item): bool
    {
        $id = is_string($item['id'] ?? null) ? $item['id'] : '';
        if ('' === $id || $this->traducao->ehOcorrenciaDeSerie($item) || $this->traducao->ehDeOutroTipo($item)) {
            return false;
        }
        if ($this->levarPrazos->ehIdDePrazo($id)) {
            $this->levarPrazos->mexeramLa($conexao, $item, $id);

            return false;
        }
        if ($this->levarDatas->ehIdDeData($id)) {
            $this->levarDatas->mexeramLa($conexao, $item, $id);

            return false;
        }
        $ligacao = $this->ligacoes->daConexaoPeloGoogle($conexao, $id) ?? $this->religarIdNosso($conexao, $id);
        if (null === $ligacao) {
            return 'cancelled' !== ($item['status'] ?? null) && !$this->traducao->ehIdNosso($id) && $this->novoDoGoogle($conexao, $item, $id);
        }
        if ($ligacao->getEvento()->getCriador()?->getId() !== $conexao->getUsuario()->getId()) {
            $this->mexeramNaCopia($ligacao, $item);

            return false;
        }

        return $this->mudouNoGoogle($conexao, $ligacao, $item);
    }

    /**
     * Evento com id nosso (`bjt<escritório>e<compromisso>`) sem ligação (perdida): religa ao compromisso daqui,
     * sem resumo guardado (vai de novo como está aqui), em vez de importar uma cópia. Compromisso que não existe
     * mais (ou é de outro escritório) não volta.
     */
    private function religarIdNosso(ConexaoGoogleAgenda $conexao, string $id): ?LigacaoComGoogle
    {
        $eventoId = $this->traducao->compromissoDoId($id, (int) $conexao->getTenant()->getId());
        $evento = null === $eventoId ? null : $this->em->find(Evento::class, $eventoId);
        if (null === $evento || $evento->getTenant()?->getId() !== $conexao->getTenant()->getId()) {
            return null;
        }
        $ligacao = new LigacaoComGoogle($conexao->getTenant(), $conexao, $evento, $id);
        $this->ligacoes->salvar($ligacao);

        return $ligacao;
    }

    /**
     * Compromisso da própria pessoa mudado no Google (não é o eco do nosso envio): vale, como edição auditada.
     *
     * @param array<string, mixed> $item
     */
    private function mudouNoGoogle(ConexaoGoogleAgenda $conexao, LigacaoComGoogle $ligacao, array $item): bool
    {
        $evento = $ligacao->getEvento();
        if ($this->traducao->ehEcoDoEnvio($item, $evento, $ligacao->getHash())) {
            return false;
        }
        $cabe = $this->traducao->aplicar($item, $evento) && !$this->traducao->soDeLeitura($item);
        foreach ($this->participantes->daEquipe($conexao->getTenant(), $this->traducao->emailsDosConvidados($item), $conexao->getUsuario()) as $colega) {
            $evento->addParticipante($colega);
        }
        $ligacao->sincronizou($cabe ? $this->traducao->resumo($evento) : CompromissoNoGoogle::SO_DO_GOOGLE);
        $this->em->persist($evento);

        return true;
    }

    /**
     * Cópia de compromisso de outra pessoa é só leitura (decisão b do dono): mexida lá, volta como aqui no
     * próximo envio; apagada lá, fica apagada até o compromisso mudar aqui.
     *
     * @param array<string, mixed> $item
     */
    private function mexeramNaCopia(LigacaoComGoogle $ligacao, array $item): void
    {
        $evento = $ligacao->getEvento();
        if ($this->traducao->ehEcoDoEnvio($item, $evento, $ligacao->getHash(), true)) {
            return;
        }
        'cancelled' === ($item['status'] ?? null)
            ? $ligacao->recusada($this->traducao->resumo($evento, true))
            : $ligacao->esquecerEnvio();
    }

    /**
     * Criado na agenda do escritório no Google: entra visível à equipe (decisão a do dono), criado pela dona da
     * conta, com os convidados da equipe como participantes (o de fora é ignorado e não é guardado).
     *
     * @param array<string, mixed> $item
     */
    private function novoDoGoogle(ConexaoGoogleAgenda $conexao, array $item, string $id): bool
    {
        $evento = (new Evento())
            ->setTenant($conexao->getTenant())
            ->setCriador($conexao->getUsuario())
            ->setTipo(TipoDeCompromisso::Meus)
            ->setCor(TipoDeCompromisso::Meus->cor())
            ->setVisibilidade(Evento::VISIBILIDADE_TODOS)
            ->setStatus(Evento::STATUS_AGENDADO)
            ->marcarImportadoDoGoogle();
        $cabe = $this->traducao->aplicar($item, $evento) && !$this->traducao->soDeLeitura($item);
        foreach ($this->participantes->daEquipe($conexao->getTenant(), $this->traducao->emailsDosConvidados($item), $conexao->getUsuario()) as $colega) {
            $evento->addParticipante($colega);
        }
        $ligacao = new LigacaoComGoogle($conexao->getTenant(), $conexao, $evento, $id);
        $ligacao->sincronizou($cabe ? $this->traducao->resumo($evento) : CompromissoNoGoogle::SO_DO_GOOGLE);
        $this->em->persist($evento);
        $this->ligacoes->salvar($ligacao);

        return true;
    }
}
