<?php

declare(strict_types=1);

namespace App\Agenda\UseCase;

use App\Agenda\Entity\ConexaoGoogleAgenda;
use App\Agenda\Exception\GoogleAgendaException;
use App\Agenda\Infra\GoogleAgendaApi;
use App\Agenda\Message\SincronizarGoogleDaPessoa;
use App\Agenda\Repository\ConexaoGoogleAgendaRepository;
use App\Agenda\Repository\ExclusaoNoGoogleRepository;
use App\Agenda\Repository\LigacaoComGoogleRepository;
use App\Agenda\Service\GarantirVarreduraDoGoogle;
use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Sync\Service\CifradorDeSegredo;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * História: a pessoa clicou em "Sincronizar com o Google Agenda", escolheu a conta (qualquer uma; o BlueJus
 * sugere a do cadastro), autorizou e voltou. Trocamos o código pela autorização permanente (refresh token),
 * guardamos cifrada com o e-mail da conta, uma conexão por pessoa e escritório, e pedimos a primeira
 * sincronia na hora (que cria a agenda do escritório na conta). Conectar de novo substitui a autorização e
 * recomeça; com **outra** conta, a antiga é revogada no Google (se não estiver ligada em outro escritório) e os
 * compromissos perdem a ligação com ela (vão à agenda nova como novos). A mesma conta pode ser ligada em vários
 * escritórios: cada um tem a sua agenda nela (modelo do dono de 20/10/2026).
 */
final class ConectarGoogleAgendaUseCase
{
    public function __construct(
        private readonly GoogleAgendaApi $google,
        private readonly ConexaoGoogleAgendaRepository $conexoes,
        private readonly CifradorDeSegredo $cifrador,
        private readonly MessageBusInterface $bus,
        private readonly GarantirVarreduraDoGoogle $garantir,
        private readonly ExclusaoNoGoogleRepository $exclusoes,
        private readonly LigacaoComGoogleRepository $ligacoes,
    ) {
    }

    public function executar(Tenant $tenant, User $eu, string $codigo, string $verificador, string $retorno): void
    {
        $autorizacao = $this->google->trocarCodigo($codigo, $verificador, $retorno);
        $conexao = $this->guardar($tenant, $eu, $this->cifrador->cifrar($autorizacao['refresh']), $autorizacao['conta'], GoogleAgendaApi::FUSO);
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
        if (!$this->conexoes->contaLigadaEmOutraConexao($conexao)) {
            try {
                $this->google->revogar($this->cifrador->decifrar($conexao->getRefreshTokenCifrado()));
            } catch (\Throwable) {
                // Autorização antiga ilegível: não há o que revogar lá.
            }
        }
        $this->ligacoes->esquecerDaConexao($conexao);
        foreach ($this->exclusoes->pendentesDaPessoa($conexao->getUsuario(), $conexao->getTenant()) as $exclusao) {
            $this->exclusoes->concluir($exclusao);
        }
        $this->conexoes->liberar((int) $conexao->getId());
    }
}
