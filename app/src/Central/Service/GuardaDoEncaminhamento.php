<?php

declare(strict_types=1);

namespace App\Central\Service;

use App\Central\Entity\Conversa;
use App\Central\Enum\TipoDeItemEncaminhado;
use App\Central\Exception\ItemEncaminhadoNaoEncontradoException;
use App\Central\Repository\ParticipanteRepository;
use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Inteligencia\Service\VisibilidadeDoFinanceiroDaPasta;
use App\Pasta\Entity\Pasta;
use App\Pasta\Repository\PastaRepository;
use App\Pasta\Service\AcessoAPasta;

/**
 * As perguntas de acesso do "Encaminhar via Chat I.A" (`docs/specs/encaminhar-via-chat-ia.md` §2), comuns à lista de
 * destinos e ao envio: a pasta é deste escritório e eu a vejo? quem recebe na conversa? todos eles veem a pasta?
 *
 * O cadeado da pasta vale para quem RECEBE: mandar o conteúdo a quem não vê a pasta furaria o cadeado. Quem saiu do
 * escritório (sem vínculo ativo) não conta como membro: já não lê a Central.
 *
 * Registro do Financeiro: além da pasta, a pessoa precisa ver o financeiro dela (`VisibilidadeDoFinanceiroDaPasta`, o
 * mesmo aperto do contexto da IA); vale para quem envia e para cada um que recebe. Conversa sem ninguém que receba
 * (todos os outros saíram do escritório) não é destino: `todosVeem([])` é falso.
 */
final class GuardaDoEncaminhamento
{
    /** O motivo do grupo travado, na lista de destinos e na recusa do envio. */
    public const MOTIVO_GRUPO = 'Nem todos deste grupo veem esta pasta.';

    /** Conversa em que mais ninguém pode receber (os outros saíram do escritório). */
    public const MOTIVO_SEM_DESTINATARIO = 'Ninguém mais nesta conversa pode receber.';

    public function __construct(
        private readonly PastaRepository $pastas,
        private readonly AcessoAPasta $acessoAPasta,
        private readonly ParticipanteRepository $participantes,
        private readonly VisibilidadeDoFinanceiroDaPasta $financeiro,
    ) {
    }

    /** @throws ItemEncaminhadoNaoEncontradoException pasta de outro escritório, inexistente, excluída (lápide) ou que eu não vejo */
    public function pastaQueEuVejo(int $pastaId, User $eu, Tenant $tenant): Pasta
    {
        $pasta = $pastaId > 0 ? $this->pastas->findOneBy(['id' => $pastaId, 'tenant' => $tenant]) : null;
        if (!$pasta instanceof Pasta || $pasta->estaExcluida() || !$this->acessoAPasta->podeVer($eu, $pasta)) {
            throw new ItemEncaminhadoNaoEncontradoException();
        }

        return $pasta;
    }

    /** Vê a pasta e, no registro do Financeiro, também o financeiro dela. Sem tipo: só a pasta. */
    public function podeVer(User $pessoa, Pasta $pasta, ?TipoDeItemEncaminhado $tipo = null): bool
    {
        if (!$this->acessoAPasta->podeVer($pessoa, $pasta)) {
            return false;
        }

        return $tipo !== TipoDeItemEncaminhado::RegistroFinanceiro
            || $this->financeiro->podeVer($pessoa, $pasta->getTenant() ?? throw new \LogicException('Pasta sem escritório.'), $pasta);
    }

    /**
     * Quem recebe numa conversa: os participantes ATIVOS, com vínculo ativo no escritório, menos eu.
     *
     * @param array<int, mixed> $comVinculoAtivo ids de quem tem vínculo ativo (chave)
     *
     * @return list<User>
     */
    public function quemRecebe(Conversa $conversa, User $eu, Tenant $tenant, array $comVinculoAtivo): array
    {
        $pessoas = [];
        foreach ($this->participantes->daConversaInteira($conversa, $tenant) as $participante) {
            $pessoa = $participante->getUsuario();
            $id = (int) $pessoa->getId();
            if ($id !== (int) $eu->getId() && isset($comVinculoAtivo[$id])) {
                $pessoas[] = $pessoa;
            }
        }

        return $pessoas;
    }

    /**
     * Todos veem o item, e há ao menos um: lista vazia é falso (despachar para ninguém não é despacho).
     *
     * @param list<User> $pessoas
     */
    public function todosVeem(array $pessoas, Pasta $pasta, ?TipoDeItemEncaminhado $tipo = null): bool
    {
        if ($pessoas === []) {
            return false;
        }
        foreach ($pessoas as $pessoa) {
            if (!$this->podeVer($pessoa, $pasta, $tipo)) {
                return false;
            }
        }

        return true;
    }
}
