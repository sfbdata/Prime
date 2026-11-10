<?php

declare(strict_types=1);

namespace App\Ponto\Controller;

use App\Entity\Auth\User;
use App\Ponto\DTO\SincronizacaoDaFilaInput;
use App\Ponto\UseCase\RegistrarSincronizacaoDaFilaUseCase;
use App\Service\PermissionChecker;
use App\Service\Tenant\TenantContext;
use App\Shared\Trait\ValidaCsrfAjaxTrait;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * O aviso da fila sem internet de que uma rodada enviou batidas (spec docs/specs/ponto-desenho-0811.md, E14; evento
 * "Sincronização" da trilha, dc L438). POST JSON `{idsEnvio: string[]}`, com o token `ajax` no `X-CSRF-Token` (o
 * interceptador do base.html.twig põe). Responde `{registradas: N}`, quantas batidas entraram no evento (0 quando
 * nenhuma era da fila dela ou todas já tinham entrado).
 *
 * A pessoa e o escritório são os da sessão. Sem acesso ao Ponto no escritório: 403, antes do CSRF (a falha de
 * autorização tem prioridade e fica testável). A rota cai com a tela `ponto` desligada (prefixo `ponto_` no catálogo do
 * Master).
 */
final class FilaDoPontoController extends AbstractController
{
    use ValidaCsrfAjaxTrait;

    public function __construct(
        private readonly TenantContext $tenantContext,
        private readonly PermissionChecker $permissoes,
        private readonly RegistrarSincronizacaoDaFilaUseCase $registrar,
    ) {
    }

    #[Route('/ponto/fila/sincronizada', name: 'ponto_fila_sincronizada', methods: ['POST'])]
    public function sincronizada(Request $request): JsonResponse
    {
        $eu = $this->getUser();
        $escritorio = $this->tenantContext->getCurrentTenant();
        if (!$eu instanceof User || $escritorio === null || !$this->permissoes->canAccessModule($eu, $escritorio, 'ponto')) {
            return $this->json(['erro' => 'Sem permissão para registrar ponto.'], Response::HTTP_FORBIDDEN);
        }

        $this->validarCsrfAjax($request);

        $corpo = json_decode($request->getContent(), true);
        $input = SincronizacaoDaFilaInput::doJson($eu, $escritorio, \is_array($corpo) ? $corpo : []);
        if ($input === null) {
            return $this->json(['erro' => 'Informe os envios da fila.'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return $this->json(['registradas' => $this->registrar->executar($input)]);
    }
}
