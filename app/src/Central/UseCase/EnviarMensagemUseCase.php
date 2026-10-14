<?php

declare(strict_types=1);

namespace App\Central\UseCase;

use App\Central\DTO\MensagemOutput;
use App\Central\Entity\Mensagem;
use App\Central\Exception\ConversaNaoEncontradaException;
use App\Central\Exception\PedidoInvalidoNaCentralException;
use App\Central\Exception\SemAcessoACentralException;
use App\Central\Repository\MensagemRepository;
use App\Central\Service\AcessoACentral;
use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;

/**
 * Enviar mensagem de texto numa conversa (A1), só para participante. Colega que saiu do escritório não recebe
 * mensagem nova — a conversa continua legível. O cursor de quem envia não precisa andar: a própria mensagem
 * nunca conta como não lida.
 */
final class EnviarMensagemUseCase
{
    public function __construct(
        private readonly AcessoACentral $acesso,
        private readonly MensagemRepository $mensagens,
        private readonly EntityManagerInterface $entityManager,
        private readonly ClockInterface $clock,
    ) {
    }

    /**
     * @throws SemAcessoACentralException
     * @throws ConversaNaoEncontradaException
     * @throws PedidoInvalidoNaCentralException
     */
    public function executar(User $eu, ?Tenant $tenant, int $conversaId, string $conteudo): MensagemOutput
    {
        $tenant = $this->acesso->exigirVinculoAtivo($eu, $tenant);
        $conversa = $this->acesso->participacao($conversaId, $eu, $tenant)->getConversa();

        if (!$this->acesso->temVinculoAtivo($conversa->outroAlemDe($eu), $tenant)) {
            throw new PedidoInvalidoNaCentralException('Este colega não faz mais parte do escritório.');
        }

        $agora = $this->clock->now();
        try {
            $mensagem = new Mensagem($tenant, $conversa, $eu, $conteudo, $agora);
        } catch (\DomainException $e) {
            throw new PedidoInvalidoNaCentralException($e->getMessage());
        }

        $conversa->registrarMensagem($agora);
        $this->mensagens->salvar($mensagem);
        $this->entityManager->flush();

        return MensagemOutput::fromEntity($mensagem, $eu);
    }
}
