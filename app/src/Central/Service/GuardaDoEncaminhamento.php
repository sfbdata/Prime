<?php

declare(strict_types=1);

namespace App\Central\Service;

use App\Central\Entity\Conversa;
use App\Central\Exception\ItemEncaminhadoNaoEncontradoException;
use App\Central\Repository\ParticipanteRepository;
use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Pasta\Entity\Pasta;
use App\Pasta\Repository\PastaRepository;
use App\Pasta\Service\AcessoAPasta;

/**
 * As perguntas de acesso do "Encaminhar via Chat I.A" (`docs/specs/encaminhar-via-chat-ia.md` §2), comuns à lista de
 * destinos e ao envio: a pasta é deste escritório e eu a vejo? quem recebe na conversa? todos eles veem a pasta?
 *
 * O cadeado da pasta vale para quem RECEBE: mandar o conteúdo a quem não vê a pasta furaria o cadeado. Quem saiu do
 * escritório (sem vínculo ativo) não conta como membro: já não lê a Central.
 */
final class GuardaDoEncaminhamento
{
    /** O motivo do grupo travado, na lista de destinos e na recusa do envio. */
    public const MOTIVO_GRUPO = 'Nem todos deste grupo veem esta pasta.';

    public function __construct(
        private readonly PastaRepository $pastas,
        private readonly AcessoAPasta $acessoAPasta,
        private readonly ParticipanteRepository $participantes,
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

    public function podeVer(User $pessoa, Pasta $pasta): bool
    {
        return $this->acessoAPasta->podeVer($pessoa, $pasta);
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

    /** @param list<User> $pessoas */
    public function todosVeem(array $pessoas, Pasta $pasta): bool
    {
        foreach ($pessoas as $pessoa) {
            if (!$this->acessoAPasta->podeVer($pessoa, $pasta)) {
                return false;
            }
        }

        return true;
    }
}
