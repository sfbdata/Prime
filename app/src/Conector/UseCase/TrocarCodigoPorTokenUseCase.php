<?php

declare(strict_types=1);

namespace App\Conector\UseCase;

use App\Conector\DTO\PedidoDeTokenInput;
use App\Conector\DTO\RespostaDeTokenOutput;
use App\Conector\Entity\CodigoDoConector;
use App\Conector\Enum\MotivoDaRevogacaoDaConcessao;
use App\Conector\Exception\AcessoDoConectorRecusado;
use App\Conector\Exception\ConcessaoRecusada;
use App\Conector\Exception\ErroOAuth;
use App\Conector\Exception\RecusaGravada;
use App\Conector\Repository\CodigoDoConectorRepository;
use App\Conector\Service\ConferenciaDaConcessao;
use App\Conector\Service\EmissorDeTokens;
use App\Conector\Service\SegredoOpaco;
use App\Conector\Service\ServidorDeAutorizacao;
use App\Conector\Service\TransacaoDoToken;
use App\Conector\Service\VerificadorPkce;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;

/**
 * Troca o código de autorização pelo primeiro par de tokens (`grant_type=authorization_code`, RFC 6749 4.1.3;
 * `docs/specs/mcp-remoto-oauth.md`, 3.2, 9.3 e 9.6).
 *
 * Quem chama: o app de I.A., de servidor para servidor, logo depois de a pessoa autorizar no `/oauth/authorize`.
 *
 * Na transação, com a linha do código travada:
 * 1. código desconhecido: `invalid_grant`;
 * 2. código já usado (reapresentado): a concessão cai com `codigo_reutilizado` (RFC 6749 4.1.2), a queda é GRAVADA e a
 *    resposta é `invalid_grant`;
 * 3. código vencido (10 minutos): `invalid_grant`;
 * 4. `client_id` e `redirect_uri` iguais, byte a byte, aos do código; senão `invalid_grant`;
 * 5. `resource` igual ao do servidor e ao da concessão; senão `invalid_target`;
 * 6. PKCE S256 ({@see VerificadorPkce}); senão `invalid_grant`;
 * 7. a conferência da concessão ({@see ConferenciaDaConcessao}: pessoa, escritório, corte, senha, liberação); senão
 *    `invalid_grant`;
 * 8. marca o código como usado e emite o par.
 *
 * No log `mcp` vão só o id da concessão e o motivo (9.7).
 *
 * Nos itens 1, 3 a 7 nada muda: o código continua sem uso até vencer, e o app pode tentar de novo com os dados certos.
 */
final class TrocarCodigoPorTokenUseCase
{
    public function __construct(
        private readonly CodigoDoConectorRepository $codigos,
        private readonly SegredoOpaco $segredos,
        private readonly VerificadorPkce $pkce,
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
            $codigo = $this->carregarCodigoAindaNaoUsado((string) $pedido->code, $agora);
            $this->exigirPedidoDoCodigo($codigo, $pedido);
            $this->exigirConcessaoConferida($codigo, $agora);
            $codigo->usar($agora);

            return $this->emissor->emitirPar($codigo->getConcessao(), $agora);
        });
    }

    /** @throws ErroOAuth */
    private function exigirCampos(PedidoDeTokenInput $pedido): void
    {
        if ($pedido->code === null || $pedido->redirectUri === null || $pedido->clientId === null || $pedido->codeVerifier === null) {
            throw ErroOAuth::invalidRequest();
        }
        if ($pedido->resource !== ServidorDeAutorizacao::RESOURCE) {
            throw ErroOAuth::invalidTarget();
        }
    }

    /** @throws ErroOAuth|RecusaGravada */
    private function carregarCodigoAindaNaoUsado(string $valor, \DateTimeImmutable $agora): CodigoDoConector
    {
        $codigo = $this->codigos->buscarParaUsar($this->segredos->hashDe($valor));
        if (!$codigo instanceof CodigoDoConector) {
            throw ErroOAuth::invalidGrant();
        }
        if ($codigo->getUsadoEm() !== null) {
            $codigo->getConcessao()->revogar(MotivoDaRevogacaoDaConcessao::CodigoReutilizado, $agora);
            $this->mcpLogger->warning('conector: código reapresentado, concessão revogada', ['concessao' => $codigo->getConcessao()->getId()]);

            throw new RecusaGravada(ErroOAuth::invalidGrant());
        }
        if ($codigo->venceuEm($agora)) {
            throw ErroOAuth::invalidGrant();
        }

        return $codigo;
    }

    /** @throws ErroOAuth */
    private function exigirPedidoDoCodigo(CodigoDoConector $codigo, PedidoDeTokenInput $pedido): void
    {
        $concessao = $codigo->getConcessao();
        if (!hash_equals($concessao->getCliente()->getIdentificador(), (string) $pedido->clientId)
            || !hash_equals($codigo->getRedirectUri(), (string) $pedido->redirectUri)) {
            throw ErroOAuth::invalidGrant();
        }
        if ($concessao->getResource() !== $pedido->resource) {
            throw ErroOAuth::invalidTarget();
        }
        if (!$this->pkce->confere((string) $pedido->codeVerifier, $codigo->getCodeChallenge())) {
            throw ErroOAuth::invalidGrant();
        }
    }

    /** @throws ErroOAuth */
    private function exigirConcessaoConferida(CodigoDoConector $codigo, \DateTimeImmutable $agora): void
    {
        try {
            $this->conferencia->exigir($codigo->getConcessao(), $agora);
        } catch (ConcessaoRecusada|AcessoDoConectorRecusado $recusa) {
            $this->mcpLogger->info('conector: troca do código recusada', ['concessao' => $codigo->getConcessao()->getId(), 'motivo' => $recusa->motivo]);

            throw ErroOAuth::invalidGrant();
        }
    }
}
