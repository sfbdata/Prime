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
use App\Central\Service\AvisoDeMencaoNaCentral;
use App\Central\Service\MencoesDaCentral;
use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;

/**
 * Enviar mensagem de texto numa conversa (A1), só para participante. Colega que saiu do escritório não recebe
 * mensagem nova — a conversa continua legível. O cursor de quem envia não precisa andar: a própria mensagem
 * nunca conta como não lida.
 *
 * A2 (`docs/specs/central-comunicacao-a2.md`): pode responder a uma mensagem DESTA conversa (a de outra, ou de
 * outro escritório, é 422 — sem dizer se existe); as @menções são conferidas contra quem pode ser mencionado
 * (participante, ativo, não o autor) e cada mencionado ganha um aviso no sino, sem o texto — tudo no mesmo flush.
 */
final class EnviarMensagemUseCase
{
    public function __construct(
        private readonly AcessoACentral $acesso,
        private readonly MensagemRepository $mensagens,
        private readonly EntityManagerInterface $entityManager,
        private readonly ClockInterface $clock,
        private readonly MencoesDaCentral $mencoes,
        private readonly AvisoDeMencaoNaCentral $aviso,
    ) {
    }

    /**
     * @throws SemAcessoACentralException
     * @throws ConversaNaoEncontradaException
     * @throws PedidoInvalidoNaCentralException
     */
    public function executar(User $eu, ?Tenant $tenant, int $conversaId, string $conteudo, ?int $respostaAId = null): MensagemOutput
    {
        $tenant = $this->acesso->exigirVinculoAtivo($eu, $tenant);
        $conversa = $this->acesso->participacao($conversaId, $eu, $tenant)->getConversa();

        if (!$this->acesso->temVinculoAtivo($conversa->outroAlemDe($eu), $tenant)) {
            throw new PedidoInvalidoNaCentralException('Este colega não faz mais parte do escritório.');
        }

        $respostaA = null;
        if ($respostaAId !== null) {
            $respostaA = $this->mensagens->daConversa($respostaAId, $conversa, $tenant)
                ?? throw new PedidoInvalidoNaCentralException('A mensagem respondida não está nesta conversa.');
        }

        // Sem token no texto não há o que conferir — nem a consulta de quem é mencionável.
        $mencionaveis = str_contains($conteudo, '@[') ? $this->acesso->mencionaveis($conversa, $eu, $tenant) : [];
        $resolvidas = $this->mencoes->resolver($conteudo, $mencionaveis);

        $agora = $this->clock->now();
        try {
            $mensagem = new Mensagem($tenant, $conversa, $eu, $resolvidas->conteudo, $agora, $respostaA);
        } catch (\DomainException $e) {
            throw new PedidoInvalidoNaCentralException($e->getMessage());
        }

        $conversa->registrarMensagem($agora);
        $this->mensagens->salvar($mensagem);
        $this->aviso->avisar($conversa, $eu, $tenant, $resolvidas->mencionados);
        $this->entityManager->flush();

        return MensagemOutput::fromEntity($mensagem, $eu);
    }
}
