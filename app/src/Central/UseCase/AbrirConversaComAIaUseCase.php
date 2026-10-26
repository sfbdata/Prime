<?php

declare(strict_types=1);

namespace App\Central\UseCase;

use App\Central\Entity\Conversa;
use App\Central\Entity\Participante;
use App\Central\Exception\ConversaCriadaAoMesmoTempoException;
use App\Central\Exception\SemAcessoACentralException;
use App\Central\Repository\ConversaRepository;
use App\Central\Repository\ParticipanteRepository;
use App\Central\Service\AcessoACentral;
use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;

/**
 * Abre (ou devolve) a conversa da pessoa com o "BlueJus IA" (F3, `docs/specs/ia-chat-central.md`).
 *
 * Quem: vínculo ativo no escritório, como o resto da Central. Sem permissão de módulo da IA por ora (é do PR da tela).
 * Idempotente: uma conversa por (escritório, usuário); o índice único do par (menor = maior = o usuário) barra a
 * segunda linha numa corrida. Só o próprio usuário participa. Sem controller nem rota neste PR.
 */
final class AbrirConversaComAIaUseCase
{
    public function __construct(
        private readonly AcessoACentral $acesso,
        private readonly ConversaRepository $conversas,
        private readonly ParticipanteRepository $participantes,
        private readonly EntityManagerInterface $entityManager,
        private readonly ClockInterface $clock,
    ) {
    }

    /**
     * @return int o id da conversa
     *
     * @throws SemAcessoACentralException
     * @throws ConversaCriadaAoMesmoTempoException
     */
    public function executar(User $eu, ?Tenant $tenant): int
    {
        $tenant = $this->acesso->exigirVinculoAtivo($eu, $tenant);

        $conversa = $this->conversas->daIaDe($tenant, $eu) ?? $this->criar($tenant, $eu);

        return (int) $conversa->getId();
    }

    private function criar(Tenant $tenant, User $eu): Conversa
    {
        $agora = $this->clock->now();
        $conversa = Conversa::comAIa($tenant, $eu, $agora);
        $this->conversas->salvar($conversa);
        $this->participantes->salvar(new Participante($tenant, $conversa, $eu, $agora));

        try {
            $this->entityManager->flush();
        } catch (UniqueConstraintViolationException) {
            throw new ConversaCriadaAoMesmoTempoException();
        }

        return $conversa;
    }
}
