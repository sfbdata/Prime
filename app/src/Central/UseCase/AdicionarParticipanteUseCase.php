<?php

declare(strict_types=1);

namespace App\Central\UseCase;

use App\Central\DTO\ParticipanteOutput;
use App\Central\Entity\Participante;
use App\Central\Exception\ConversaNaoEncontradaException;
use App\Central\Exception\ParticipacaoAlteradaAoMesmoTempoException;
use App\Central\Exception\PedidoInvalidoNaCentralException;
use App\Central\Exception\SemAcessoACentralException;
use App\Central\Exception\SemPermissaoNoGrupoException;
use App\Central\Repository\MensagemRepository;
use App\Central\Repository\ParticipanteRepository;
use App\Central\Service\AcessoACentral;
use App\Central\Service\AvisoDeGrupoNaCentral;
use App\Central\Service\MensagensDeSistemaDoGrupo;
use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;

/**
 * Adicionar alguém ao grupo — só o administrador do grupo (`docs/specs/central-comunicacao-b.md` §2). A pessoa
 * precisa de vínculo ativo no escritório. Quem tinha saído volta na mesma participação. O histórico anterior fica
 * visível, mas não chega como não lido (o cursor começa na última mensagem). Duas adições simultâneas da mesma
 * pessoa: a segunda esbarra no único `(conversa, usuario)` e vira 409.
 */
final class AdicionarParticipanteUseCase
{
    public const MAXIMO_DE_PARTICIPANTES = 100;

    public function __construct(
        private readonly AcessoACentral $acesso,
        private readonly ParticipanteRepository $participantes,
        private readonly MensagemRepository $mensagens,
        private readonly MensagensDeSistemaDoGrupo $sistema,
        private readonly AvisoDeGrupoNaCentral $aviso,
        private readonly EntityManagerInterface $entityManager,
        private readonly ClockInterface $clock,
    ) {
    }

    /**
     * @throws SemAcessoACentralException
     * @throws ConversaNaoEncontradaException
     * @throws SemPermissaoNoGrupoException
     * @throws PedidoInvalidoNaCentralException
     * @throws ParticipacaoAlteradaAoMesmoTempoException
     */
    public function executar(User $eu, ?Tenant $tenant, int $conversaId, int $usuarioId): ParticipanteOutput
    {
        $tenant = $this->acesso->exigirVinculoAtivo($eu, $tenant);
        $grupo = $this->acesso->participacao($conversaId, $eu, $tenant)->getConversa();
        if (!$grupo->isGrupo()) {
            throw new PedidoInvalidoNaCentralException('Só se adiciona participante a um grupo.');
        }
        if (!$grupo->isAdministradaPor($eu)) {
            throw new SemPermissaoNoGrupoException();
        }

        $pessoa = $this->acesso->colaboradoresAtivos($tenant)[$usuarioId]
            ?? throw new PedidoInvalidoNaCentralException('Colega não encontrado neste escritório.');

        $existente = $this->participantes->deQualquerSituacao($grupo, $pessoa, $tenant);
        if ($existente !== null && $existente->isAtivo()) {
            throw new PedidoInvalidoNaCentralException('Esta pessoa já participa do grupo.');
        }
        if (count($this->participantes->daConversaInteira($grupo, $tenant)) >= self::MAXIMO_DE_PARTICIPANTES) {
            throw new PedidoInvalidoNaCentralException(sprintf('O grupo já tem %d participantes.', self::MAXIMO_DE_PARTICIPANTES));
        }

        $agora = $this->clock->now();
        $ultima = $this->mensagens->ultimoIdDa($grupo, $tenant);

        try {
            $this->entityManager->wrapInTransaction(function () use ($existente, $grupo, $pessoa, $eu, $tenant, $agora, $ultima): void {
                if ($existente !== null) {
                    $existente->voltar($agora, $ultima);
                } else {
                    $nova = new Participante($tenant, $grupo, $pessoa, $agora);
                    $nova->marcarLidaAte($ultima);
                    $this->participantes->salvar($nova);
                }
                $this->sistema->adicionou($grupo, $eu, $pessoa, $tenant, $agora);
                $this->aviso->avisarAdicionado($grupo, $eu, $tenant, $pessoa);
                $this->entityManager->flush();
            });
        } catch (UniqueConstraintViolationException) {
            throw new ParticipacaoAlteradaAoMesmoTempoException();
        }

        return new ParticipanteOutput((int) $pessoa->getId(), (string) $pessoa->getFullName(), false, true, false);
    }
}
