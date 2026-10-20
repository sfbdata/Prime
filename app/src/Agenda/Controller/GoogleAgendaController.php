<?php

declare(strict_types=1);

namespace App\Agenda\Controller;

use App\Agenda\Exception\GoogleAgendaException;
use App\Agenda\Infra\GoogleAgendaApi;
use App\Agenda\UseCase\ConectarGoogleAgendaUseCase;
use App\Agenda\UseCase\DesconectarGoogleAgendaUseCase;
use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Service\PermissionChecker;
use App\Service\Tenant\TenantContext;
use App\Shared\Trait\ValidaCsrfAjaxTrait;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Conexão da conta Google da pessoa com a Agenda do escritório (entrega 6, modelo do dono de 20/10/2026, spec
 * docs/specs/agenda-google.md). OAuth no molde do Drive: state na sessão conferido com hash_equals, mais PKCE
 * S256; o e-mail do cadastro vai só como sugestão de conta. O resultado volta no fragmento
 * (`#google=conectado|cancelado|falhou`), que a tela da Agenda mostra no toast (o base consome os flashes).
 * Não depende do login do BlueJus: o "Entrar com o Google" é outra coisa.
 */
final class GoogleAgendaController extends AbstractController
{
    use ValidaCsrfAjaxTrait;

    private const SESSAO = 'agenda_google_oauth';

    public function __construct(
        private readonly GoogleAgendaApi $google,
        private readonly ConectarGoogleAgendaUseCase $conectar,
        private readonly DesconectarGoogleAgendaUseCase $desconectar,
        private readonly PermissionChecker $permissionChecker,
        private readonly TenantContext $tenantContext,
        private readonly LoggerInterface $logger,
        private readonly RateLimiterFactoryInterface $agendaGoogleLimiter,
    ) {
    }

    #[Route('/agenda/google/conectar', name: 'app_agenda_google_conectar', methods: ['GET'])]
    public function iniciar(Request $request): RedirectResponse
    {
        [$eu, $tenant] = $this->exigirAgenda();
        $volta = $this->voltaLocal($request->query->getString('volta'), $request->getHost());
        if (!$this->google->ligado()) {
            return $this->redirect($volta);
        }
        $state = bin2hex(random_bytes(16));
        $verificador = rtrim(strtr(base64_encode(random_bytes(48)), '+/', '-_'), '=');
        $request->getSession()->set(self::SESSAO, ['state' => $state, 'verificador' => $verificador, 'volta' => $volta, 'tenant' => $tenant->getId()]);
        $desafio = rtrim(strtr(base64_encode(hash('sha256', $verificador, true)), '+/', '-_'), '=');

        return $this->redirect($this->google->urlDeAutorizacao($state, $desafio, $this->urlDeRetorno(), (string) $eu->getEmail()));
    }

    #[Route('/agenda/google/retorno', name: 'app_agenda_google_retorno', methods: ['GET'])]
    public function voltar(Request $request): RedirectResponse
    {
        [$eu, $tenant] = $this->exigirAgenda();
        $guardado = $request->getSession()->remove(self::SESSAO);
        if (!is_array($guardado) || !hash_equals((string) ($guardado['state'] ?? ''), $request->query->getString('state'))) {
            throw new AccessDeniedHttpException('Pedido de conexão inválido ou vencido.');
        }
        $volta = (string) $guardado['volta'];
        if (($guardado['tenant'] ?? null) !== $tenant->getId()) {
            // Trocou de escritório no meio da ida ao Google: a conexão não vai para o escritório errado.
            return $this->redirect($volta . '#google=falhou');
        }
        if ('' !== $request->query->getString('error') || '' === $request->query->getString('code')) {
            return $this->redirect($volta . '#google=cancelado');
        }
        try {
            $this->conectar->executar($tenant, $eu, $request->query->getString('code'), (string) $guardado['verificador'], $this->urlDeRetorno());
        } catch (GoogleAgendaException $e) {
            $this->logger->warning('Google Agenda: conexão falhou', ['motivo' => $e->getMessage()]);

            return $this->redirect($volta . '#google=falhou');
        }

        return $this->redirect($volta . '#google=conectado');
    }

    /** `{"apagarAgenda": true}` apaga também a agenda do escritório no Google; sem ele, ela fica lá parada. */
    #[Route('/agenda/google/desconectar', name: 'app_agenda_google_desconectar', methods: ['POST'])]
    public function sair(Request $request): JsonResponse
    {
        [$eu, $tenant] = $this->exigirAgenda();
        $this->validarCsrfAjax($request);
        $corpo = '' === $request->getContent() ? [] : $request->toArray();
        try {
            $this->desconectar->executar($tenant, $eu, true === ($corpo['apagarAgenda'] ?? false));
        } catch (GoogleAgendaException $e) {
            return $this->json(['erro' => $e->getMessage()], 409);
        }

        return $this->json(['ok' => true]);
    }

    /** @return array{User, Tenant} */
    private function exigirAgenda(): array
    {
        $eu = $this->getUser();
        $tenant = $this->tenantContext->getCurrentTenant();
        if (!$eu instanceof User || null === $tenant || !$this->permissionChecker->canAccessModule($eu, $tenant, 'agenda')) {
            throw new AccessDeniedHttpException('Sem acesso à Agenda.');
        }
        if (!$this->agendaGoogleLimiter->create('pessoa-' . $eu->getId())->consume()->isAccepted()) {
            throw new TooManyRequestsHttpException(null, 'Muitas tentativas de conexão com o Google. Tente de novo em alguns minutos.');
        }

        return [$eu, $tenant];
    }

    /**
     * Só página deste site (o desenho manda `location.href`): caminho `/algo` ou URL do mesmo host, nunca
     * `//outro.site` nem outro host (sem redirecionamento aberto).
     */
    private function voltaLocal(string $volta, string $host): string
    {
        $padrao = $this->generateUrl('agenda_index');
        if ('' === $volta) {
            return $padrao;
        }
        $partes = parse_url($volta);
        $deFora = isset($partes['host']) && strtolower((string) $partes['host']) !== strtolower($host);
        if (false === $partes || $deFora || (isset($partes['scheme']) && !in_array($partes['scheme'], ['http', 'https'], true))) {
            return $padrao;
        }
        $caminho = (string) ($partes['path'] ?? '');
        if (!str_starts_with($caminho, '/') || str_starts_with($caminho, '//') || str_contains($volta, '\\')) {
            return $padrao;
        }

        return $caminho . (isset($partes['query']) ? '?' . $partes['query'] : '');
    }

    private function urlDeRetorno(): string
    {
        return $this->generateUrl('app_agenda_google_retorno', [], UrlGeneratorInterface::ABSOLUTE_URL);
    }
}
