<?php

declare(strict_types=1);

namespace App\Conector\Service;

use App\Conector\Entity\ConcessaoDoConector;
use App\Conector\Entity\TokenDoConector;
use App\Conector\Enum\TipoDoTokenDoConector;
use App\Conector\Exception\AcessoDoConectorRecusado;
use App\Conector\Exception\ConcessaoRecusada;
use App\Conector\Repository\TokenDoConectorRepository;
use Mcp\Server\Authorization\AccessToken;
use Mcp\Server\Transport\Http\OAuth\AuthorizationResult;
use Mcp\Server\Transport\Http\OAuth\AuthorizationTokenValidatorInterface;
use Psr\Clock\ClockInterface;
use Symfony\Contracts\Service\ResetInterface;

/**
 * O validador do token opaco do `/mcp` (`docs/specs/mcp-remoto-oauth.md`, 3.2, seção 4, regra 3, e 5.1), que o
 * `AuthorizationMiddleware` do SDK chama. Um juiz só: o autenticador do firewall e o middleware leem o mesmo resultado,
 * guardado por requisição, e só o SDK formata o 401 e o 403.
 *
 * A ordem do julgamento, e a primeira que falha decide:
 *
 * 1. o token existe pelo hash ({@see SegredoOpaco::hashDe()}), sem escritório (é a linha que o revela);
 * 2. é de acesso (a renovação só vale no `/oauth/token`);
 * 3. não está revogado nem vencido;
 * 4. a concessão foi emitida para o `resource` deste servidor ({@see ServidorDeAutorizacao::RESOURCE}, RFC 8707);
 * 5. a concessão inteira passa na {@see ConferenciaDaConcessao::exigir()}: concessão viva, app ativo, corte de "Sair de
 *    todos os aparelhos", impressão da senha, barreiras da pessoa e do escritório (sem o passe do Master), termos e
 *    liberação nos três níveis.
 *
 * As recusas (decisão 3 do PR 5): token, concessão e acesso recusados por pessoa, escritório ou termos dão 401
 * `invalid_token` com uma descrição ASCII fixa por categoria; qualquer outro erro (filtro, requisição já aberta, banco fora do
 * ar, erro de programação) dá 503 sem desafio, e nunca deixa passar.
 *
 * A liberação é a exceção: quando só ela falha (chave geral, escritório bloqueado pelo Master, conector desligado ou
 * aceite antigo), o token é válido e o conector está SUSPENSO. O julgamento é 403 com o erro
 * {@see ERRO_CONECTOR_DESLIGADO}, nunca `invalid_token` (que levaria o app a renovar e, sem sucesso, a pedir
 * reconexão) nem `insufficient_scope` (que levaria ao step-up). O {@see \App\Conector\Mcp\ConectorDesligadoMiddleware}
 * troca a resposta do SDK por um 403 sem `WWW-Authenticate`, com um erro JSON-RPC de mensagem fixa. Nada é despachado:
 * o `AuthorizationMiddleware` para aí, antes da `ScopePolicy` e do portão. A renovação continua girando
 * ({@see ConferenciaDaConcessao::exigirNaRenovacao()}), e religar volta a aceitar o mesmo token.
 *
 * O resultado fica guardado até o `reset()` (fim da requisição, mensagem seguinte do worker): o mesmo token
 * apresentado de novo na mesma requisição recebe o MESMO julgamento, sem conferir outra vez.
 */
final class ValidadorDoTokenDoConector implements AuthorizationTokenValidatorInterface, ResetInterface
{
    /** Motivos próprios do token, para o log `mcp` (lista fechada, junto dos motivos das exceções). */
    public const MOTIVO_TOKEN_DESCONHECIDO = 'token_desconhecido';
    public const MOTIVO_NAO_E_ACESSO = 'nao_e_acesso';
    public const MOTIVO_TOKEN_VENCIDO_OU_REVOGADO = 'token_vencido_ou_revogado';
    public const MOTIVO_RESOURCE = 'resource';
    public const MOTIVO_INDISPONIVEL = 'indisponivel';

    /**
     * O `error_description` do 401: ASCII e fixo por categoria (RFC 6750, 3: só %x20-21 / %x23-5B / %x5D-7E), sem
     * nome de escritório nem de pessoa. O motivo detalhado fica só no log `mcp`, pelo {@see motivoDaRecusa()}.
     */
    public const DESCRICAO_TOKEN = 'The access token is invalid or expired.';
    public const DESCRICAO_AUTORIZACAO = 'The authorization was revoked.';
    public const DESCRICAO_ESCRITORIO = 'Access to this office is not available.';
    public const DESCRICAO_TERMOS = 'The Terms of Use must be accepted in BlueJus.';
    /** A descrição do 403 do conector suspenso (liberação): a mesma regra ASCII, no corpo da resposta. */
    public const DESCRICAO_LIBERACAO = 'The connector is not enabled for this office.';

    /**
     * O código de erro do julgamento do conector suspenso (403). Não é `invalid_token` nem `insufficient_scope`: o
     * token vale e não falta escopo. Só marca a resposta do SDK para o {@see \App\Conector\Mcp\ConectorDesligadoMiddleware},
     * que tira o `WWW-Authenticate`; nunca chega ao app de I.A.
     */
    public const ERRO_CONECTOR_DESLIGADO = 'connector_disabled';

    /** As recusas do portão que pedem um login novo (401), com a descrição de cada uma; as demais dão 503. */
    private const RECUSAS_DO_PORTAO_COM_DESAFIO = [
        AcessoDoConectorRecusado::MOTIVO_PESSOA => self::DESCRICAO_ESCRITORIO,
        AcessoDoConectorRecusado::MOTIVO_ESCRITORIO => self::DESCRICAO_ESCRITORIO,
        AcessoDoConectorRecusado::MOTIVO_TERMOS => self::DESCRICAO_TERMOS,
    ];

    private ?string $hashJulgado = null;
    private ?AuthorizationResult $resultado = null;
    private ?TokenDoConector $tokenAceito = null;
    private ?string $motivo = null;

    public function __construct(
        private readonly TokenDoConectorRepository $tokens,
        private readonly SegredoOpaco $segredos,
        private readonly ConferenciaDaConcessao $conferencia,
        private readonly ClockInterface $relogio,
    ) {
    }

    public function validate(string $accessToken): AuthorizationResult
    {
        $hash = $this->segredos->hashDe($accessToken);
        if ($this->resultado !== null && $this->hashJulgado !== null && hash_equals($this->hashJulgado, $hash)) {
            return $this->resultado;
        }

        $this->tokenAceito = null;
        $this->motivo = null;
        try {
            $resultado = $this->julgar($hash);
        } catch (\Throwable) {
            // Falha fechada: nada que escape do julgamento deixa passar, e nada vira 401 por engano.
            $this->tokenAceito = null;
            $this->motivo = self::MOTIVO_INDISPONIVEL;
            $resultado = AuthorizationResult::unavailable();
        }

        $this->hashJulgado = $hash;
        $this->resultado = $resultado;

        return $resultado;
    }

    /** O token aceito nesta requisição, ou null se nenhum foi aceito. */
    public function tokenValidado(): ?TokenDoConector
    {
        return $this->tokenAceito;
    }

    /** O motivo da recusa nesta requisição, de lista fechada, para o log `mcp`; null se não houve recusa. */
    public function motivoDaRecusa(): ?string
    {
        return $this->motivo;
    }

    /** Zera o resultado guardado da requisição. Não lança: roda em todo `kernel.reset`. */
    public function reset(): void
    {
        $this->hashJulgado = null;
        $this->resultado = null;
        $this->tokenAceito = null;
        $this->motivo = null;
    }

    private function julgar(string $hash): AuthorizationResult
    {
        $token = $this->tokens->buscarPorIdentificadorHash($hash);
        if (!$token instanceof TokenDoConector) {
            return $this->recusar(self::MOTIVO_TOKEN_DESCONHECIDO, self::DESCRICAO_TOKEN);
        }
        if ($token->getTipo() !== TipoDoTokenDoConector::Acesso) {
            return $this->recusar(self::MOTIVO_NAO_E_ACESSO, self::DESCRICAO_TOKEN);
        }

        $agora = $this->relogio->now();
        if ($token->getRevogadoEm() !== null || $token->venceuEm($agora)) {
            return $this->recusar(self::MOTIVO_TOKEN_VENCIDO_OU_REVOGADO, self::DESCRICAO_TOKEN);
        }

        $concessao = $token->getConcessao();
        if ($concessao->getResource() !== ServidorDeAutorizacao::RESOURCE) {
            return $this->recusar(self::MOTIVO_RESOURCE, self::DESCRICAO_TOKEN);
        }

        $recusa = $this->conferirConcessao($concessao, $agora);
        if ($recusa !== null) {
            return $recusa;
        }

        $this->tokenAceito = $token;

        return AuthorizationResult::allow(new AccessToken($token->getEscopos(), [
            'sub' => (string) $concessao->getUser()->getId(),
            'client_id' => $concessao->getCliente()->getIdentificador(),
        ]));
    }

    /** A conferência inteira da concessão; null se passou, ou o resultado da recusa (401, 403 ou 503). */
    private function conferirConcessao(ConcessaoDoConector $concessao, \DateTimeImmutable $agora): ?AuthorizationResult
    {
        try {
            $this->conferencia->exigir($concessao, $agora);
        } catch (ConcessaoRecusada $recusa) {
            return $this->recusar($recusa->motivo, self::DESCRICAO_AUTORIZACAO);
        } catch (AcessoDoConectorRecusado $recusa) {
            if ($recusa->motivo === AcessoDoConectorRecusado::MOTIVO_LIBERACAO) {
                // A liberação é a última conferência: chegar aqui quer dizer que todo o resto passou e só ela falhou.
                $this->motivo = $recusa->motivo;

                return AuthorizationResult::forbidden(self::ERRO_CONECTOR_DESLIGADO, self::DESCRICAO_LIBERACAO, []);
            }

            $descricao = self::RECUSAS_DO_PORTAO_COM_DESAFIO[$recusa->motivo] ?? null;
            if ($descricao === null) {
                // Defensivo, hoje inalcançável: a conferência só lança pessoa, escritório, termos e liberação (o
                // `conferir` do portão não liga filtro nem abre a requisição). Se um dia lançar outro motivo, é falha
                // do servidor, nunca um pedido de login novo.
                $this->motivo = $recusa->motivo;

                return AuthorizationResult::unavailable();
            }

            return $this->recusar($recusa->motivo, $descricao);
        }

        return null;
    }

    private function recusar(string $motivo, string $mensagem): AuthorizationResult
    {
        $this->motivo = $motivo;

        return AuthorizationResult::unauthorized('invalid_token', $mensagem);
    }
}
