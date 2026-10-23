<?php

declare(strict_types=1);

namespace App\Central\UseCase;

use App\Central\Exception\ConversaNaoEncontradaException;
use App\Central\Exception\SemAcessoACentralException;
use App\Central\Repository\ConversaRepository;
use App\Central\Repository\MensagemRepository;
use App\Central\Repository\ParticipanteRepository;
use App\Central\Service\AcessoACentral;
use App\Central\Service\AvisoDeMencaoNaCentral;
use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Botão direito → "Excluir" numa conversa = limpar PARA MIM. Nada é apagado: a participação de quem pede guarda até
 * que mensagem limpou, e dali para trás o histórico, a lista e o polling não mostram mais nada a ELA. Os outros
 * participantes seguem vendo tudo. O que estava limpo conta como lido, inclusive o @aviso de menção desta conversa
 * (decisão do dono, 18/10); mensagem nova traz a conversa de volta.
 *
 * O corte espera a trava da conversa: um envio em andamento confirma antes da leitura da última mensagem, e uma
 * mensagem confirmada fora de ordem não fica escondida sem nunca ter sido vista. Corte, cursor e marca são gravados
 * no banco, só para frente (outra aba ao mesmo tempo não faz nada recuar).
 */
final class LimparConversaUseCase
{
    public function __construct(
        private readonly AcessoACentral $acesso,
        private readonly ConversaRepository $conversas,
        private readonly MensagemRepository $mensagens,
        private readonly ParticipanteRepository $participantes,
        private readonly AvisoDeMencaoNaCentral $aviso,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * @throws SemAcessoACentralException
     * @throws ConversaNaoEncontradaException
     */
    public function executar(User $eu, ?Tenant $tenant, int $conversaId): void
    {
        $tenant = $this->acesso->exigirVinculoAtivo($eu, $tenant);
        $participacao = $this->acesso->participacao($conversaId, $eu, $tenant);
        $conversa = $participacao->getConversa();

        $this->entityManager->wrapInTransaction(function () use ($participacao, $conversa, $eu, $tenant): void {
            $this->conversas->travar($conversa, $tenant);
            $ate = $this->mensagens->ultimoIdDa($conversa, $tenant);

            $this->participantes->avancarLimpeza($participacao, $ate);
            $this->participantes->avancarLeitura($participacao, $ate);
            $this->participantes->desmarcarNaoLida($participacao);
            $this->aviso->marcarComoLidas($conversa, $eu, $tenant);
        });
    }
}
