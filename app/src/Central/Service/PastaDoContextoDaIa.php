<?php

declare(strict_types=1);

namespace App\Central\Service;

use App\Central\Exception\PastaDaIaNaoEncontradaException;
use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Inteligencia\Contexto\MontadorDeContextoDaPasta;
use App\Inteligencia\Enum\Agente;
use App\Inteligencia\Exception\ContextoBloqueadoException;
use App\Inteligencia\Service\VisibilidadeDoFinanceiroDaPasta;
use App\Pasta\Entity\AbaDaPasta;
use App\Pasta\Repository\PastaRepository;
use App\Pasta\Service\AcessoAPasta;
use App\Pasta\Service\VisibilidadeDasAbasDaPasta;

/**
 * A pasta que a pessoa tem aberta na tela, validada para a pergunta ao BlueJus IA (PR 4a,
 * `docs/specs/ia-chat-central.md`). Roda NA SOLICITAÇÃO (há sessão), antes de gravar qualquer coisa e fora da trava da
 * conversa: a recusa não pode deixar transação aberta nem fechar o EntityManager.
 *
 * Ordem: pasta por id + escritório (inexistente ou de outro escritório) → cadeado/permissão de ver
 * (`AcessoAPasta::podeVer`); os dois casos são {@see PastaDaIaNaoEncontradaException}, o mesmo 404 → segredo de justiça
 * (o montador lança {@see ContextoBloqueadoException} antes de ler qualquer outra coisa) → decisão do financeiro e das
 * abas ocultas.
 *
 * Devolve SÓ ids e flags para o `contexto_resumo`: nunca texto, nunca NUP nem número de processo. O worker relê tudo
 * e obedece o que foi decidido aqui (se a pessoa perdeu acesso, a análise falha sem chamar o provedor).
 */
final class PastaDoContextoDaIa
{
    /** O contexto mais amplo, o mesmo da aba Perguntar da pasta. */
    public const AGENTE = Agente::Gestor;

    public function __construct(
        private readonly PastaRepository $pastas,
        private readonly AcessoAPasta $acessoAPasta,
        private readonly VisibilidadeDoFinanceiroDaPasta $financeiro,
        private readonly VisibilidadeDasAbasDaPasta $abas,
        private readonly MontadorDeContextoDaPasta $montador,
    ) {
    }

    /**
     * @return array{pastaId: int, financeiro: bool, abasOcultas: list<string>}
     *
     * @throws PastaDaIaNaoEncontradaException
     * @throws ContextoBloqueadoException
     */
    public function liberar(User $eu, Tenant $tenant, int $pastaId): array
    {
        $pasta = $this->pastas->findOneBy(['id' => $pastaId, 'tenant' => $tenant]);
        if ($pasta === null || $pasta->estaExcluida() || !$this->acessoAPasta->podeVer($eu, $pasta)) {
            throw new PastaDaIaNaoEncontradaException();
        }

        $financeiro = $this->financeiro->podeVer($eu, $tenant, $pasta);
        $abasOcultas = array_map(static fn (AbaDaPasta $a): string => $a->value, $this->abas->abasOcultasPara($pasta, $eu));

        // Só para o sigilo: o resumo do montador traz NUPs, e este método não devolve nada dele.
        $this->montador->para($tenant, $pasta, self::AGENTE, $financeiro, $abasOcultas);

        return ['pastaId' => (int) $pasta->getId(), 'financeiro' => $financeiro, 'abasOcultas' => array_values($abasOcultas)];
    }
}
