<?php

declare(strict_types=1);

namespace App\Central\UseCase;

use App\Central\DTO\ContatoOutput;
use App\Central\DTO\ConversaAbertaOutput;
use App\Central\Entity\Conversa;
use App\Central\Entity\Participante;
use App\Central\Exception\ConversaCriadaAoMesmoTempoException;
use App\Central\Exception\PedidoInvalidoNaCentralException;
use App\Central\Exception\SemAcessoACentralException;
use App\Central\Repository\ConversaRepository;
use App\Central\Repository\ParticipanteRepository;
use App\Central\Service\AcessoACentral;
use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Repository\UserRepository;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;

/**
 * Abrir conversa com um colega (A1).
 *
 * Quem: alguém com vínculo ativo no escritório. O quê: conversar com outra pessoa do MESMO escritório.
 * Idempotente: se a conversa do par já existe (aberta por qualquer um dos dois), devolve ela. Destinatário
 * inexistente, de outro escritório ou que saiu dá a mesma recusa — não confirma quem existe fora daqui.
 * Cria a conversa e as duas participações numa transação só.
 */
final class AbrirConversaUseCase
{
    public function __construct(
        private readonly AcessoACentral $acesso,
        private readonly UserRepository $usuarios,
        private readonly ConversaRepository $conversas,
        private readonly ParticipanteRepository $participantes,
        private readonly EntityManagerInterface $entityManager,
        private readonly ClockInterface $clock,
    ) {
    }

    /**
     * @throws SemAcessoACentralException
     * @throws PedidoInvalidoNaCentralException
     * @throws ConversaCriadaAoMesmoTempoException
     */
    public function executar(User $eu, ?Tenant $tenant, int $destinatarioId): ConversaAbertaOutput
    {
        $tenant = $this->acesso->exigirVinculoAtivo($eu, $tenant);

        if ($destinatarioId === $eu->getId()) {
            throw new PedidoInvalidoNaCentralException('Não há conversa consigo mesmo.');
        }

        $destinatario = $this->usuarios->find($destinatarioId);
        if ($destinatario === null || !$this->acesso->temVinculoAtivo($destinatario, $tenant)) {
            throw new PedidoInvalidoNaCentralException('Colega não encontrado neste escritório.');
        }

        $conversa = $this->conversas->individualEntre($tenant, $eu, $destinatario)
            ?? $this->criar($tenant, $eu, $destinatario);

        return new ConversaAbertaOutput(
            id: (int) $conversa->getId(),
            outro: new ContatoOutput((int) $destinatario->getId(), (string) $destinatario->getFullName()),
            outroAtivo: true,
        );
    }

    private function criar(Tenant $tenant, User $eu, User $destinatario): Conversa
    {
        $agora = $this->clock->now();
        $conversa = Conversa::individual($tenant, $eu, $destinatario, $agora);
        $this->conversas->salvar($conversa);
        $this->participantes->salvar(new Participante($tenant, $conversa, $eu, $agora));
        $this->participantes->salvar(new Participante($tenant, $conversa, $destinatario, $agora));

        try {
            $this->entityManager->flush();
        } catch (UniqueConstraintViolationException) {
            throw new ConversaCriadaAoMesmoTempoException();
        }

        return $conversa;
    }
}
