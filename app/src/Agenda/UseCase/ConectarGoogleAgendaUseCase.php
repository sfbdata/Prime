<?php

declare(strict_types=1);

namespace App\Agenda\UseCase;

use App\Agenda\Entity\ConexaoGoogleAgenda;
use App\Agenda\Exception\ContaGoogleEmOutroEscritorioException;
use App\Agenda\Exception\GoogleAgendaException;
use App\Agenda\Infra\GoogleAgendaApi;
use App\Agenda\Message\SincronizarGoogleDaPessoa;
use App\Agenda\Repository\ConexaoGoogleAgendaRepository;
use App\Agenda\Repository\EventoDaAgendaRepository;
use App\Agenda\Repository\ExclusaoNoGoogleRepository;
use App\Agenda\Service\GarantirVarreduraDoGoogle;
use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Sync\Service\CifradorDeSegredo;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * História: a pessoa clicou em "Sincronizar com o Google Agenda", autorizou na conta Google e voltou.
 * Trocamos o código pela autorização permanente (refresh token), guardamos cifrada, uma conexão por pessoa
 * e escritório, e pedimos a primeira sincronia na hora. Conectar de novo substitui a autorização e
 * recomeça; com **outra** conta, a autorização antiga é revogada no Google e os compromissos dela neste
 * escritório perdem a ligação com a conta antiga (vão para a nova como novos). A cadeia de 5 min é posta
 * para andar. A mesma conta Google já ligada em outro escritório é recusada (sem revogar, que derrubaria a
 * de lá): a agenda pessoal entra só no primeiro, porque o "Só eu" aparece na auditoria de quem administra.
 */
final class ConectarGoogleAgendaUseCase
{
    public function __construct(
        private readonly GoogleAgendaApi $google,
        private readonly ConexaoGoogleAgendaRepository $conexoes,
        private readonly CifradorDeSegredo $cifrador,
        private readonly MessageBusInterface $bus,
        private readonly GarantirVarreduraDoGoogle $garantir,
        private readonly EventoDaAgendaRepository $eventos,
        private readonly ExclusaoNoGoogleRepository $exclusoes,
    ) {
    }

    public function executar(Tenant $tenant, User $eu, string $codigo, string $verificador, string $retorno): void
    {
        $refresh = $this->google->trocarCodigo($codigo, $verificador, $retorno);
        $agenda = $this->google->agendaPrincipal($this->google->renovar($refresh));
        if ($this->conexoes->contaEmOutroEscritorio($agenda['conta'], $tenant)) {
            throw new ContaGoogleEmOutroEscritorioException('Conta Google já ligada à Agenda de outro escritório.');
        }
        $conexao = $this->guardar($tenant, $eu, $this->cifrador->cifrar($refresh), $agenda['conta'], $agenda['fuso']);
        $this->bus->dispatch(new SincronizarGoogleDaPessoa((int) $conexao->getId()));
        $this->garantir->garantir();
    }

    private function guardar(Tenant $tenant, User $eu, string $cifrado, string $conta, string $fuso): ConexaoGoogleAgenda
    {
        $conexao = $this->conexoes->daPessoa($eu, $tenant);
        if (null === $conexao) {
            $conexao = new ConexaoGoogleAgenda($tenant, $eu, $cifrado, $conta, $fuso);
        } else {
            $this->deixarAContaAntiga($conexao, $conta);
            $conexao->reconectar($cifrado, $conta, $fuso);
        }
        $this->conexoes->salvar($conexao);

        return $conexao;
    }

    private function deixarAContaAntiga(ConexaoGoogleAgenda $conexao, string $contaNova): void
    {
        if (0 === strcasecmp($conexao->getConta(), $contaNova)) {
            return;
        }
        // Uma sincronia da conta antiga rodando agora regravaria as ligações que vamos apagar.
        if (!$this->conexoes->ocupar((int) $conexao->getId(), new \DateTimeImmutable())) {
            throw new GoogleAgendaException('A agenda está sincronizando agora; tente conectar de novo em instantes.');
        }
        try {
            $this->google->revogar($this->cifrador->decifrar($conexao->getRefreshTokenCifrado()));
        } catch (\Throwable) {
            // Autorização antiga ilegível: não há o que revogar lá.
        }
        $this->eventos->esquecerGoogleDaPessoa($conexao->getTenant(), $conexao->getUsuario());
        foreach ($this->exclusoes->pendentesDaPessoa($conexao->getUsuario(), $conexao->getTenant()) as $exclusao) {
            $this->exclusoes->concluir($exclusao);
        }
        $this->conexoes->liberar((int) $conexao->getId());
    }
}
