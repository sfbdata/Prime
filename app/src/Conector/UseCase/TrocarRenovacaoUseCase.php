<?php

declare(strict_types=1);

namespace App\Conector\UseCase;

use App\Conector\DTO\PedidoDeTokenInput;
use App\Conector\DTO\RespostaDeTokenOutput;
use App\Conector\Entity\ClienteDoConector;
use App\Conector\Entity\TokenDoConector;
use App\Conector\Enum\MotivoDaRevogacaoDaConcessao;
use App\Conector\Enum\RotacaoDaRenovacao;
use App\Conector\Exception\AcessoDoConectorRecusado;
use App\Conector\Exception\ConcessaoRecusada;
use App\Conector\Exception\ErroOAuth;
use App\Conector\Exception\RecusaGravada;
use App\Conector\Exception\RenovacaoRecusada;
use App\Conector\Repository\ClienteDoConectorRepository;
use App\Conector\Repository\TokenDoConectorRepository;
use App\Conector\Service\ConferenciaDaConcessao;
use App\Conector\Service\EmissorDeTokens;
use App\Conector\Service\SegredoOpaco;
use App\Conector\Service\ServidorDeAutorizacao;
use App\Conector\Service\TransacaoDoToken;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;

/**
 * Gira a renovação e emite um par novo (`grant_type=refresh_token`, RFC 6749 6; `docs/specs/mcp-remoto-oauth.md`, 9.1
 * e 9.2, decisão 14.c).
 *
 * Quem chama: o app de I.A., de servidor para servidor, quando o token de acesso de 1 hora vence ou está para vencer.
 *
 * Na transação, com a linha da renovação travada (`FOR UPDATE`; duas renovações simultâneas saem uma depois da outra):
 * 1. renovação desconhecida (ou um token de acesso no lugar dela): `invalid_grant`;
 * 2. `client_id` que não é de app registrado: é outro app, a concessão cai (`renovacao_reutilizada`);
 * 3. {@see TokenDoConector::girar()} com a filha (a renovação mais recente gerada por esta): outro app, renovação
 *    revogada ou vencida, concessão inválida, ou repetição fora da janela de 60 s: a concessão cai;
 * 4. a conferência da concessão ({@see ConferenciaDaConcessao}: corte, senha, pessoa, escritório, liberação): se falha,
 *    a concessão cai com `falha_na_renovacao`;
 * 5. repetição dentro da janela pelo mesmo app: revoga o par emitido antes a partir desta renovação;
 * 6. emite o par novo, gerado por esta renovação.
 *
 * Toda queda da concessão é GRAVADA antes da resposta `invalid_grant` ({@see RecusaGravada}). `resource`, quando vem, tem
 * de ser o do servidor e o da concessão (`invalid_target`, sem derrubar nada); ausente é aceito na renovação. No log
 * `mcp` vão só o id da concessão e o motivo (9.7).
 */
final class TrocarRenovacaoUseCase
{
    public function __construct(
        private readonly TokenDoConectorRepository $tokens,
        private readonly ClienteDoConectorRepository $clientes,
        private readonly SegredoOpaco $segredos,
        private readonly ConferenciaDaConcessao $conferencia,
        private readonly EmissorDeTokens $emissor,
        private readonly TransacaoDoToken $transacao,
        private readonly ClockInterface $relogio,
        private readonly LoggerInterface $mcpLogger,
    ) {
    }

    /** @throws ErroOAuth */
    public function executar(PedidoDeTokenInput $pedido): RespostaDeTokenOutput
    {
        $this->exigirCampos($pedido);
        $agora = $this->relogio->now();

        return $this->transacao->executar(function () use ($pedido, $agora): RespostaDeTokenOutput {
            $renovacao = $this->carregarRenovacao((string) $pedido->refreshToken);
            $this->exigirResourceDaConcessao($renovacao, $pedido);
            $rotacao = $this->girar($renovacao, (string) $pedido->clientId, $agora);
            $this->exigirConcessaoConferida($renovacao, $agora);
            if ($rotacao === RotacaoDaRenovacao::RepeticaoNaJanela) {
                $this->tokens->revogarParGeradoPor($renovacao, $agora);
            }

            return $this->emissor->emitirPar($renovacao->getConcessao(), $agora, $renovacao);
        });
    }

    /** @throws ErroOAuth */
    private function exigirCampos(PedidoDeTokenInput $pedido): void
    {
        if ($pedido->refreshToken === null || $pedido->clientId === null) {
            throw ErroOAuth::invalidRequest();
        }
        if ($pedido->resource !== null && $pedido->resource !== ServidorDeAutorizacao::RESOURCE) {
            throw ErroOAuth::invalidTarget();
        }
    }

    /** @throws ErroOAuth */
    private function carregarRenovacao(string $valor): TokenDoConector
    {
        $renovacao = $this->tokens->buscarRenovacaoParaGirar($this->segredos->hashDe($valor));
        if (!$renovacao instanceof TokenDoConector) {
            throw ErroOAuth::invalidGrant();
        }

        return $renovacao;
    }

    /** @throws ErroOAuth */
    private function exigirResourceDaConcessao(TokenDoConector $renovacao, PedidoDeTokenInput $pedido): void
    {
        if ($pedido->resource !== null && $pedido->resource !== $renovacao->getConcessao()->getResource()) {
            throw ErroOAuth::invalidTarget();
        }
    }

    /** @throws RecusaGravada */
    private function girar(TokenDoConector $renovacao, string $clientId, \DateTimeImmutable $agora): RotacaoDaRenovacao
    {
        $cliente = $this->clientes->buscarPorIdentificador($clientId);
        if (!$cliente instanceof ClienteDoConector) {
            $this->derrubar($renovacao, MotivoDaRevogacaoDaConcessao::RenovacaoReutilizada, $agora);
        }

        $filha = $renovacao->getUsadoEm() !== null ? $this->tokens->buscarRenovacaoGeradaPor($renovacao) : null;
        try {
            return $renovacao->girar($cliente, $agora, $filha);
        } catch (RenovacaoRecusada $recusa) {
            $this->registrarQueda($renovacao, $recusa->motivo->value);

            throw new RecusaGravada(ErroOAuth::invalidGrant(), $recusa);
        }
    }

    /** @throws RecusaGravada */
    private function exigirConcessaoConferida(TokenDoConector $renovacao, \DateTimeImmutable $agora): void
    {
        try {
            $this->conferencia->exigir($renovacao->getConcessao(), $agora);
        } catch (ConcessaoRecusada|AcessoDoConectorRecusado $recusa) {
            $this->derrubar($renovacao, MotivoDaRevogacaoDaConcessao::FalhaNaRenovacao, $agora, $recusa, $recusa->motivo);
        }
    }

    /** @throws RecusaGravada */
    private function derrubar(
        TokenDoConector $renovacao,
        MotivoDaRevogacaoDaConcessao $motivo,
        \DateTimeImmutable $agora,
        ?\Throwable $causa = null,
        ?string $barreira = null,
    ): never {
        $renovacao->getConcessao()->revogar($motivo, $agora);
        $this->registrarQueda($renovacao, $motivo->value, $barreira);

        throw new RecusaGravada(ErroOAuth::invalidGrant(), $causa);
    }

    /** Só o id da concessão, o motivo da queda e, se foi uma barreira, o nome dela (lista fechada): nada do pedido. */
    private function registrarQueda(TokenDoConector $renovacao, string $motivo, ?string $barreira = null): void
    {
        $this->mcpLogger->warning('conector: renovação recusada, concessão revogada', [
            'concessao' => $renovacao->getConcessao()->getId(),
            'motivo' => $motivo,
            'barreira' => $barreira,
        ]);
    }
}
