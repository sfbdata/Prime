<?php

declare(strict_types=1);

namespace App\Tenant\EventListener;

use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Master\Service\RegraDosMasters;
use App\Repository\TenantRepository;
use App\Repository\UserTenantRepository;
use App\Tenant\Service\AvisoDeContaSuspensa;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Twig\Environment;

/**
 * Escritório suspenso ou excluído (em quarentena) nas rotas que recebem o escritório pela URL
 * (spec docs/specs/escritorio-inativo-nas-rotas-por-url.md).
 *
 * O TenantContextValidatorListener só olha o escritório da SESSÃO. As rotas `/tenant/{tenantId}/...`
 * agem sobre o escritório da URL: sem esta barreira, o admin do escritório A suspenso, com B na sessão,
 * seguia mexendo na equipe e no ponto de A. Quem tem vínculo ativo com A e não é Master é recusado; o Master
 * passa (R3). Quem não tem vínculo com A segue para o controller, que já o recusa como sempre: a mensagem com o
 * nome e a situação da conta não vaza para fora do escritório (R1).
 *
 * Prioridade 3: depois do roteamento (32), do firewall (8), da barreira da sessão (6), do TenantFilter (5) e
 * do TenantUrlScopeListener (4), que só ESCOPA os dados no `{tenantId}` e não autoriza nada.
 */
#[AsEventListener(event: KernelEvents::REQUEST, priority: 3)]
final class BarreiraDoEscritorioInativoNaRota
{
    public const ROTULO = 'Escritório indisponível';

    /** Rotas em que o `{id}` do caminho é o próprio escritório (R2). */
    public const ROTAS_COM_ID_DO_ESCRITORIO = [
        'app_jornada_tenant_get',
        'app_jornada_tenant_save',
        'escritorio_excluir',
    ];

    /**
     * Rotas com o escritório no caminho que ficam FORA da barreira, de propósito (R4). O teste de arquitetura
     * recusa toda rota `/tenant/{...}` ou `/escritorio/{...}` que não esteja coberta pelo `tenantId`, pelo R2
     * ou por esta lista.
     */
    public const ROTAS_FORA_DA_BARREIRA = [
        // Sair tira o próprio acesso, e tirar acesso nunca trava.
        'escritorio_sair',
    ];

    public function __construct(
        private readonly Security $security,
        private readonly TenantRepository $tenants,
        private readonly UserTenantRepository $vinculos,
        private readonly RegraDosMasters $regraDosMasters,
        private readonly Environment $twig,
    ) {
    }

    public function __invoke(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();
        $escritorioId = self::escritorioDaRota($request);
        if ($escritorioId === null) {
            return;
        }

        $usuario = $this->security->getUser();
        if (!$usuario instanceof User || $this->regraDosMasters->ehMaster($usuario)) {
            return;
        }

        $escritorio = $this->tenants->find($escritorioId);
        if ($escritorio === null || $escritorio->isActive() === true || !$this->vinculos->existeVinculoAtivo($usuario, $escritorio)) {
            return;
        }

        $event->setResponse($this->recusa($request, $escritorio));
    }

    /** Id do escritório sobre o qual a rota age (R2), ou null para rota que não recebe escritório. */
    public static function escritorioDaRota(Request $request): ?int
    {
        $rota = (string) $request->attributes->get('_route', '');
        $parametro = in_array($rota, self::ROTAS_COM_ID_DO_ESCRITORIO, true) ? 'id' : 'tenantId';
        $valor = $request->attributes->get($parametro);

        return is_scalar($valor) && ctype_digit((string) $valor) ? (int) $valor : null;
    }

    private function recusa(Request $request, Tenant $escritorio): Response
    {
        $nome = (string) $escritorio->getName();
        $mensagem = $escritorio->getExcluidoEm() === null
            ? AvisoDeContaSuspensa::para($nome)
            : AvisoDeContaSuspensa::paraExcluida($nome);

        if ($this->ehChamadaDeApi($request)) {
            return new JsonResponse(['erro' => $mensagem], Response::HTTP_FORBIDDEN);
        }

        return new Response($this->twig->render('tenant/escritorio_indisponivel.html.twig', [
            'titulo'   => self::ROTULO,
            'mensagem' => $mensagem,
        ]), Response::HTTP_FORBIDDEN);
    }

    /** Chamada de script (fetch/XHR ou JSON): 403 JSON. Formulário enviado pela tela recebe a página (R5). */
    private function ehChamadaDeApi(Request $request): bool
    {
        return $request->isXmlHttpRequest()
            || str_contains((string) $request->headers->get('Accept', ''), 'application/json')
            || str_contains((string) $request->headers->get('Content-Type', ''), 'application/json');
    }
}
