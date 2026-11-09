<?php

declare(strict_types=1);

namespace App\AssinaturaEletronica\Controller;

use App\AssinaturaEletronica\DTO\ArquivoParaEntregar;
use App\AssinaturaEletronica\DTO\EntradaDaAssinatura;
use App\AssinaturaEletronica\DTO\EvidenciaDaRequisicao;
use App\AssinaturaEletronica\DTO\LeitorDeCampos;
use App\AssinaturaEletronica\Exception\AcaoRecusada;
use App\AssinaturaEletronica\Exception\LinkInvalido;
use App\AssinaturaEletronica\Exception\MuitasTentativas;
use App\AssinaturaEletronica\Service\ChaveDaAssinatura;
use App\AssinaturaEletronica\Service\PaginaDoSignatario;
use App\AssinaturaEletronica\Service\SignatarioDoLink;
use App\AssinaturaEletronica\UseCase\AbrirPaginaDoSignatarioUseCase;
use App\AssinaturaEletronica\UseCase\AssinarDocumentoUseCase;
use App\AssinaturaEletronica\UseCase\ObterArquivoDoSignatarioUseCase;
use App\AssinaturaEletronica\UseCase\RecusarAssinaturaUseCase;
use App\AssinaturaEletronica\UseCase\SolicitarCodigoUseCase;
use App\Shared\Http\EntregaDeArquivo;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Exception\JsonException;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * A tela pública do signatário (contrato da etapa 2, seção 5.2), sem login: o token do link é a chave. Prefixo de nome
 * `assinatura_eletronica_publica` (o {@see \App\AssinaturaEletronica\EventListener\AssinaturaPublicaListener} desliga
 * o filtro de escritório e põe os cabeçalhos de toda resposta).
 *
 * A página e os POST passam pelo limitador por IP e, com token, pelo do sha256 do token; o pedido de código passa também
 * pelo do signatário. Os GET de arquivo (PDF, logo e rubricas, até 50 numa abertura) têm o par de limitadores próprio
 * (`assinatura_publica_arquivos`, por IP, e `assinatura_publica_arquivos_token`), para uma abertura não gastar a cota
 * da página. Token malformado (a rota de reserva), desconhecido ou de documento que sumiu: a mesma página "Link
 * inválido." com 404 (JSON nos POST). Os POST conferem o CSRF de id `assinar_{sha256 do token}` no cabeçalho
 * X-CSRF-Token, depois de achar o signatário (token ruim é sempre 404, nunca 403).
 */
final class AssinaturaPublicaController extends AbstractController
{
    private const TOKEN = '[a-f0-9]{64}';
    private const TEMPLATE = 'assinatura_eletronica/publica.html.twig';

    public function __construct(
        private readonly RateLimiterFactoryInterface $assinaturaPublicaLimiter,
        private readonly RateLimiterFactoryInterface $assinaturaPublicaTokenLimiter,
        private readonly RateLimiterFactoryInterface $assinaturaPublicaArquivosLimiter,
        private readonly RateLimiterFactoryInterface $assinaturaPublicaArquivosTokenLimiter,
        private readonly RateLimiterFactoryInterface $assinaturaCodigoEnvioLimiter,
        private readonly CsrfTokenManagerInterface $csrf,
        private readonly SignatarioDoLink $link,
        private readonly PaginaDoSignatario $pagina,
        private readonly EntregaDeArquivo $entrega,
        private readonly TranslatorInterface $tradutor,
    ) {
    }

    #[Route('/assinar/{token}', name: 'assinatura_eletronica_publica', requirements: ['token' => self::TOKEN], methods: ['GET'])]
    public function pagina(string $token, Request $request, AbrirPaginaDoSignatarioUseCase $abrir): Response
    {
        if (!$this->dentroDoLimite($request, $token)) {
            return $this->paginaDoLimite();
        }

        try {
            $publico = $abrir->executar($token, EvidenciaDaRequisicao::da($request));
        } catch (LinkInvalido) {
            return $this->paginaInvalida();
        }

        return $this->render(self::TEMPLATE, [
            'publico' => $publico,
            'csrf' => $this->csrf->getToken(self::idDoCsrf($token))->getValue(),
            'urls' => $this->urls($token),
            'limite' => false,
        ]);
    }

    #[Route('/assinar/{token}/codigo', name: 'assinatura_eletronica_publica_codigo', requirements: ['token' => self::TOKEN], methods: ['POST'])]
    public function codigo(string $token, Request $request, SolicitarCodigoUseCase $solicitar): JsonResponse
    {
        return $this->acao($request, $token, function () use ($token, $request, $solicitar): array {
            if (!$this->assinaturaCodigoEnvioLimiter->create(ChaveDaAssinatura::hashDoToken($token))->consume()->isAccepted()) {
                throw new MuitasTentativas();
            }
            $solicitar->executar($token, EvidenciaDaRequisicao::da($request));

            return ['ok' => true];
        });
    }

    #[Route('/assinar/{token}', name: 'assinatura_eletronica_publica_assinar', requirements: ['token' => self::TOKEN], methods: ['POST'])]
    public function assinar(string $token, Request $request, AssinarDocumentoUseCase $assinar): JsonResponse
    {
        return $this->acao($request, $token, static fn (): array => [
            'ok' => true,
            'estado' => $assinar->executar($token, EntradaDaAssinatura::deJson($request->toArray()), EvidenciaDaRequisicao::da($request)),
        ]);
    }

    #[Route('/assinar/{token}/recusa', name: 'assinatura_eletronica_publica_recusa', requirements: ['token' => self::TOKEN], methods: ['POST'])]
    public function recusa(string $token, Request $request, RecusarAssinaturaUseCase $recusar): JsonResponse
    {
        return $this->acao($request, $token, static fn (): array => [
            'ok' => true,
            'estado' => $recusar->executar($token, LeitorDeCampos::texto($request->toArray(), 'motivo'), EvidenciaDaRequisicao::da($request)),
        ]);
    }

    #[Route('/assinar/{token}/arquivo', name: 'assinatura_eletronica_publica_arquivo', requirements: ['token' => self::TOKEN], methods: ['GET'])]
    public function arquivo(string $token, Request $request, ObterArquivoDoSignatarioUseCase $arquivos): Response
    {
        return $this->entregar($request, $token, static fn (): ArquivoParaEntregar => $arquivos->pdf($token));
    }

    #[Route('/assinar/{token}/logo', name: 'assinatura_eletronica_publica_logo', requirements: ['token' => self::TOKEN], methods: ['GET'])]
    public function logo(string $token, Request $request, ObterArquivoDoSignatarioUseCase $arquivos): Response
    {
        return $this->entregar($request, $token, static fn (): ArquivoParaEntregar => $arquivos->logo($token));
    }

    #[Route('/assinar/{token}/rubrica/{ordem}', name: 'assinatura_eletronica_publica_rubrica', requirements: ['token' => self::TOKEN, 'ordem' => '\d+'], methods: ['GET'])]
    public function rubrica(string $token, int $ordem, Request $request, ObterArquivoDoSignatarioUseCase $arquivos): Response
    {
        return $this->entregar($request, $token, static fn (): ArquivoParaEntregar => $arquivos->rubrica($token, $ordem));
    }

    /** Reserva: qualquer outro caminho sob /assinar/ (token malformado) é o mesmo "Link inválido." do token desconhecido. */
    #[Route('/assinar/{resto}', name: 'assinatura_eletronica_publica_invalido', requirements: ['resto' => '.+'], methods: ['GET', 'POST'], priority: -10)]
    public function invalido(Request $request): Response
    {
        if (!$this->dentroDoLimite($request, null)) {
            return $request->isMethod('POST') ? $this->erro(MuitasTentativas::CHAVE, [], Response::HTTP_TOO_MANY_REQUESTS) : $this->paginaDoLimite();
        }

        return $request->isMethod('POST') ? $this->erro(LinkInvalido::CHAVE, [], Response::HTTP_NOT_FOUND) : $this->paginaInvalida();
    }

    /**
     * Porta dos POST: limitadores, signatário do link, CSRF e as recusas como JSON.
     *
     * @param \Closure(): array<string, mixed> $acao
     */
    private function acao(Request $request, string $token, \Closure $acao): JsonResponse
    {
        if (!$this->dentroDoLimite($request, $token)) {
            return $this->erro(MuitasTentativas::CHAVE, [], Response::HTTP_TOO_MANY_REQUESTS);
        }

        try {
            $this->link->localizar($token);
            if (!$this->csrf->isTokenValid(new CsrfToken(self::idDoCsrf($token), (string) $request->headers->get('X-CSRF-Token', '')))) {
                return $this->erro('assinatura_eletronica.erro.sessao_expirada', [], Response::HTTP_FORBIDDEN);
            }

            return $this->json($acao());
        } catch (LinkInvalido) {
            return $this->erro(LinkInvalido::CHAVE, [], Response::HTTP_NOT_FOUND);
        } catch (MuitasTentativas) {
            return $this->erro(MuitasTentativas::CHAVE, [], Response::HTTP_TOO_MANY_REQUESTS);
        } catch (AcaoRecusada $e) {
            return $this->erro($e->getMessage(), $e->parametros, Response::HTTP_UNPROCESSABLE_ENTITY);
        } catch (JsonException) {
            return $this->erro('assinatura_eletronica.erro.dados_invalidos', [], Response::HTTP_UNPROCESSABLE_ENTITY);
        }
    }

    /** @param \Closure(): ArquivoParaEntregar $qual */
    private function entregar(Request $request, string $token, \Closure $qual): Response
    {
        if (!self::cabe($this->assinaturaPublicaArquivosLimiter, $this->assinaturaPublicaArquivosTokenLimiter, $request, $token)) {
            return new Response('', Response::HTTP_TOO_MANY_REQUESTS);
        }

        try {
            $arquivo = $qual();
        } catch (LinkInvalido) {
            return $this->paginaInvalida();
        }

        return $this->entrega->resposta($arquivo->chave, $arquivo->nome, inline: true);
    }

    /** A página e os POST: o limitador por IP; com token, também o do sha256 do token. */
    private function dentroDoLimite(Request $request, ?string $token): bool
    {
        return self::cabe($this->assinaturaPublicaLimiter, $this->assinaturaPublicaTokenLimiter, $request, $token);
    }

    /** O par de limitadores: o do IP primeiro; com token, também o do sha256 do token (nunca o token cru). */
    private static function cabe(RateLimiterFactoryInterface $porIp, RateLimiterFactoryInterface $porToken, Request $request, ?string $token): bool
    {
        if (!$porIp->create($request->getClientIp() ?? 'sem-ip')->consume()->isAccepted()) {
            return false;
        }

        return $token === null || $porToken->create(ChaveDaAssinatura::hashDoToken($token))->consume()->isAccepted();
    }

    /**
     * As URLs da página, pelo nome curto: as de POST e as dos arquivos (a rubrica com o marcador 0 na ordem).
     *
     * @return array<string, string>
     */
    private function urls(string $token): array
    {
        $urls = [];
        foreach (['codigo', 'assinar', 'recusa', 'arquivo', 'logo'] as $nome) {
            $urls[$nome] = $this->generateUrl('assinatura_eletronica_publica_' . $nome, ['token' => $token]);
        }
        $urls['rubrica'] = $this->generateUrl('assinatura_eletronica_publica_rubrica', ['token' => $token, 'ordem' => 0]);

        return $urls;
    }

    private function paginaInvalida(): Response
    {
        return $this->render(self::TEMPLATE, [
            'publico' => $this->pagina->invalido(),
            'csrf' => null,
            'urls' => [],
            'limite' => false,
        ], new Response(null, Response::HTTP_NOT_FOUND));
    }

    private function paginaDoLimite(): Response
    {
        return $this->render(self::TEMPLATE, ['limite' => true], new Response(null, Response::HTTP_TOO_MANY_REQUESTS));
    }

    /** @param array<string, int|string> $parametros */
    private function erro(string $chave, array $parametros, int $status): JsonResponse
    {
        return $this->json(['erro' => $this->tradutor->trans($chave, $parametros)], $status);
    }

    /** O id do CSRF do link: o sha256 do token, nunca o token cru (que não vai para a sessão). */
    private static function idDoCsrf(string $token): string
    {
        return 'assinar_' . ChaveDaAssinatura::hashDoToken($token);
    }
}
