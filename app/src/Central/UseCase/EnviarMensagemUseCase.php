<?php

declare(strict_types=1);

namespace App\Central\UseCase;

use App\Central\DTO\MensagemOutput;
use App\Central\DTO\ReferenciaDeDespacho;
use App\Central\Entity\Conversa;
use App\Central\Entity\Mensagem;
use App\Central\Exception\ConversaNaoEncontradaException;
use App\Central\Exception\EnvioNaConversaComAIaException;
use App\Central\Exception\PedidoInvalidoNaCentralException;
use App\Central\Exception\SemAcessoACentralException;
use App\Central\Repository\MensagemRepository;
use App\Central\Service\AcessoACentral;
use App\Central\Service\AvisoDeMencaoNaCentral;
use App\Central\Service\LeituraDasMensagens;
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
 *
 * Despacho (`docs/specs/encaminhar-via-chat-ia.md` §3): `$despacho` grava na mensagem a referência da pasta e o tipo
 * do item, na mesma transação. Só o `EncaminharItemDaPastaUseCase` passa (a rota de enviar não tem como).
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
        private readonly LeituraDasMensagens $leitura,
    ) {
    }

    /**
     * @throws SemAcessoACentralException
     * @throws ConversaNaoEncontradaException
     * @throws PedidoInvalidoNaCentralException
     * @throws EnvioNaConversaComAIaException
     */
    public function executar(User $eu, ?Tenant $tenant, int $conversaId, string $conteudo, ?int $respostaAId = null, ?ReferenciaDeDespacho $despacho = null): MensagemOutput
    {
        $tenant = $this->acesso->exigirVinculoAtivo($eu, $tenant);
        $participacao = $this->acesso->participacao($conversaId, $eu, $tenant);
        $conversa = $participacao->getConversa();
        $this->recusarSeConversaIa($conversa);

        // Individual: o par é fixo, e falar com quem saiu do escritório não leva a lugar nenhum. Grupo (B): segue
        // valendo para quem ficou — quem saiu do escritório só deixa de ler.
        if (!$conversa->isGrupo() && !$this->acesso->temVinculoAtivo($conversa->outroAlemDe($eu), $tenant)) {
            throw new PedidoInvalidoNaCentralException('Este colega não faz mais parte do escritório.');
        }

        $respostaA = null;
        if ($respostaAId !== null) {
            $respostaA = $this->mensagens->daConversa($respostaAId, $conversa, $tenant);
            if ($respostaA === null || $respostaA->isSistema()) {
                throw new PedidoInvalidoNaCentralException('A mensagem respondida não está nesta conversa.');
            }
        }

        // Sem token no texto não há o que conferir — nem a consulta de quem é mencionável.
        $mencionaveis = str_contains($conteudo, '@[') ? $this->acesso->mencionaveis($conversa, $eu, $tenant) : [];
        $resolvidas = $this->mencoes->resolver($conteudo, $mencionaveis);

        $agora = $this->clock->now();
        try {
            $mensagem = new Mensagem($tenant, $conversa, $eu, $resolvidas->conteudo, $agora, $respostaA);
            if ($despacho !== null) {
                $mensagem->referenciarDespacho($despacho->pasta, $despacho->tipo);
            }
        } catch (\DomainException $e) {
            throw new PedidoInvalidoNaCentralException($e->getMessage());
        }

        // Uma transação explícita: a trava de cada mencionado (dentro do aviso) só segura até o commit, e a
        // mensagem e os avisos entram juntos ou não entram.
        $this->entityManager->wrapInTransaction(function () use ($conversa, $participacao, $agora, $mensagem, $eu, $tenant, $resolvidas): void {
            $participacao->arquivar(false);   // desenho L382: quem envia desarquiva a conversa — só para si
            $conversa->registrarMensagem($agora);
            $this->mensagens->salvar($mensagem);
            $this->aviso->avisar($conversa, $eu, $tenant, $resolvidas->mencionados);
            $this->entityManager->flush();
        });

        return $this->leitura->umaParaLeitor($mensagem, $eu);
    }

    /**
     * Na conversa do BlueJus IA só se pergunta (`PerguntarABlueJusIaUseCase`): enviar mensagem comum gravaria texto sem
     * análise e furaria o limitador, a cota e a disponibilidade da IA.
     *
     * @throws EnvioNaConversaComAIaException
     */
    private function recusarSeConversaIa(Conversa $conversa): void
    {
        if ($conversa->isIa()) {
            throw new EnvioNaConversaComAIaException();
        }
    }
}
