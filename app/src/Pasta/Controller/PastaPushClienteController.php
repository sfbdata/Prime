<?php

declare(strict_types=1);

namespace App\Pasta\Controller;

use App\Cliente\Enum\ChaveDoEnvioAutomatico;
use App\Cliente\Enum\RotuloDoEmail;
use App\Cliente\Exception\EmailDoClienteEmConflitoException;
use App\Cliente\Exception\EmailDoClienteNaoEncontradoException;
use App\Cliente\Exception\EmailDoClienteRecusadoException;
use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Inteligencia\Exception\AnaliseNaoEncontradaException;
use App\Inteligencia\Service\DisponibilidadeDeInteligencia;
use App\Pasta\DTO\EnviarAnaliseAoClienteInput;
use App\Pasta\Entity\Pasta;
use App\Pasta\Exception\EnvioAoClienteMuitoFrequenteException;
use App\Pasta\Exception\EnvioAoClienteRecusadoException;
use App\Pasta\Exception\FalhaNoEnvioAoClienteException;
use App\Pasta\UseCase\CadastrarEmailDoClienteUseCase;
use App\Pasta\UseCase\DefinirAvisosDoEmailDoClienteUseCase;
use App\Pasta\UseCase\DefinirEnvioAutomaticoDoClienteUseCase;
use App\Pasta\UseCase\EnviarAnaliseAoClienteUseCase;
use App\Pasta\UseCase\MontarAvisarClienteUseCase;
use App\Pasta\UseCase\RemoverEmailDoClienteUseCase;
use App\Pasta\UseCase\TornarEmailDoClientePrincipalUseCase;
use App\Service\PermissionChecker;
use App\Service\Tenant\TenantContext;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * O "Avisar cliente" da aba Push (spec push-avisar-cliente §2 e §3; desenho, dc L.2765-2873):
 *
 *   GET  /pasta/{id}/push/cliente                              → o cartão (fragmento), para a recarga sem F5
 *   POST /pasta/{id}/push/cliente/emails                       → `email`, `rotulo` (pessoal|trabalho|familiar|outro)
 *   POST /pasta/{id}/push/cliente/emails/{emailId}/principal   → a estrela
 *   POST /pasta/{id}/push/cliente/emails/{emailId}/avisos      → `ligado` = 1|0 (o estado desejado)
 *   POST /pasta/{id}/push/cliente/emails/{emailId}/remover     → o X
 *   POST /pasta/{id}/push/cliente/automatico                   → `chave` (novas_movimentacoes|audiencias_intimacoes|
 *                                                                resumo_semanal), `ligado` = 1|0 (P4d, do cliente)
 *   POST /pasta/{id}/push/cliente/analises/{analiseId}/enviar  → `para[]`, `assunto`, `corpo`, `revisado` = 1
 * As de e-mail e a do automático respondem {sucesso, mensagem, html (o cartão)}; recusa 422 {erro}; id alheio 404.
 * O envio responde {sucesso, mensagem, enviados, jaEnviados (idempotência de 2 min), enviado, dica};
 * recusa 422; mais de 10 envios da pasta na hora 429; falha do transporte 502 {erro, enviados}.
 *
 * Guarda: a pasta é do escritório da sessão (404), EDITAR a pasta (403; o cartão só VER) e o CSRF
 * `pasta_push_cliente_{id}` (403). O envio exige também a BlueJus IA (`modules.inteligencia.view`).
 * O emailId 0 é o item virtual do cadastro. Na lápide, o PastaSomenteLeituraListener recusa os POST.
 */
#[Route('/pasta/{id}/push/cliente', requirements: ['id' => '\d+'])]
final class PastaPushClienteController extends AbstractController
{
    public function __construct(
        private readonly PermissionChecker $permissionChecker,
        private readonly TenantContext $tenantContext,
        private readonly MontarAvisarClienteUseCase $montar,
    ) {
    }

    #[Route('', name: 'pasta_push_cliente', methods: ['GET'])]
    public function cartao(Pasta $pasta): Response
    {
        $recusa = $this->recusa($pasta, 'view', null);
        if ($recusa !== null) {
            return $recusa;
        }

        return $this->render('pasta/_push_cliente.html.twig', ['pasta' => $pasta, 'cliente' => $this->montar->executar($pasta, $this->tenant())]);
    }

    #[Route('/emails', name: 'pasta_push_cliente_email_cadastrar', methods: ['POST'])]
    public function cadastrar(Pasta $pasta, Request $request, CadastrarEmailDoClienteUseCase $cadastrar): JsonResponse
    {
        $rotulo = RotuloDoEmail::doPedido((string) $request->request->get('rotulo', RotuloDoEmail::Pessoal->value));

        return $this->alterar($pasta, $request, function (User $usuario, Tenant $tenant) use ($pasta, $request, $rotulo, $cadastrar): string {
            if ($rotulo === null) {
                throw new EmailDoClienteRecusadoException('Rótulo desconhecido.');
            }

            return $cadastrar->executar($pasta, (string) $request->request->get('email'), $rotulo, $usuario, $tenant);
        });
    }

    #[Route('/emails/{emailId}/principal', name: 'pasta_push_cliente_email_principal', requirements: ['emailId' => '\d+'], methods: ['POST'])]
    public function principal(Pasta $pasta, int $emailId, Request $request, TornarEmailDoClientePrincipalUseCase $tornar): JsonResponse
    {
        return $this->alterar($pasta, $request, static function (User $u, Tenant $t) use ($pasta, $emailId, $tornar): ?string {
            $tornar->executar($pasta, $emailId, $u, $t);

            return null;
        });
    }

    #[Route('/emails/{emailId}/avisos', name: 'pasta_push_cliente_email_avisos', requirements: ['emailId' => '\d+'], methods: ['POST'])]
    public function avisos(Pasta $pasta, int $emailId, Request $request, DefinirAvisosDoEmailDoClienteUseCase $definir): JsonResponse
    {
        $ligado = (string) $request->request->get('ligado');

        return $this->alterar($pasta, $request, static function (User $u, Tenant $t) use ($pasta, $emailId, $ligado, $definir): ?string {
            if (!\in_array($ligado, ['0', '1'], true)) {
                throw new EmailDoClienteRecusadoException('Estado desconhecido.');
            }

            $definir->executar($pasta, $emailId, $ligado === '1', $u, $t);

            return null;
        });
    }

    #[Route('/emails/{emailId}/remover', name: 'pasta_push_cliente_email_remover', requirements: ['emailId' => '\d+'], methods: ['POST'])]
    public function remover(Pasta $pasta, int $emailId, Request $request, RemoverEmailDoClienteUseCase $remover): JsonResponse
    {
        return $this->alterar($pasta, $request, static function (User $u, Tenant $t) use ($pasta, $emailId, $remover): ?string {
            $remover->executar($pasta, $emailId, $u, $t);

            return null;
        });
    }

    #[Route('/automatico', name: 'pasta_push_cliente_automatico', methods: ['POST'])]
    public function automatico(Pasta $pasta, Request $request, DefinirEnvioAutomaticoDoClienteUseCase $definir): JsonResponse
    {
        $chave = ChaveDoEnvioAutomatico::tryFrom((string) $request->request->get('chave'));
        $ligado = (string) $request->request->get('ligado');

        return $this->alterar($pasta, $request, static function (User $u, Tenant $t) use ($pasta, $chave, $ligado, $definir): ?string {
            if ($chave === null || !\in_array($ligado, ['0', '1'], true)) {
                throw new EmailDoClienteRecusadoException('Chave ou estado desconhecido.');
            }

            $definir->executar($pasta, $chave, $ligado === '1', $u, $t);

            return null;
        });
    }

    #[Route('/analises/{analiseId}/enviar', name: 'pasta_push_cliente_enviar_analise', requirements: ['analiseId' => '\d+'], methods: ['POST'])]
    public function enviar(Pasta $pasta, int $analiseId, Request $request, EnviarAnaliseAoClienteUseCase $enviar): JsonResponse
    {
        $recusa = $this->recusa($pasta, 'edit', $request) ?? $this->recusaSemIa();
        if ($recusa !== null) {
            return $recusa;
        }

        $para = array_values(array_filter($request->request->all('para'), 'is_string'));
        $input = new EnviarAnaliseAoClienteInput($analiseId, $para, (string) $request->request->get('assunto'), (string) $request->request->get('corpo'), $request->request->get('revisado') === '1');
        try {
            $saida = $enviar->executar($pasta, $input, $this->usuario(), $this->tenant());
        } catch (AnaliseNaoEncontradaException) {
            return $this->json(['erro' => 'Análise não encontrada.'], Response::HTTP_NOT_FOUND);
        } catch (EnvioAoClienteRecusadoException|EmailDoClienteRecusadoException $e) {
            return $this->json(['erro' => $e->getMessage()], Response::HTTP_UNPROCESSABLE_ENTITY);
        } catch (EnvioAoClienteMuitoFrequenteException $e) {
            return $this->json(['erro' => $e->getMessage()], Response::HTTP_TOO_MANY_REQUESTS);
        } catch (FalhaNoEnvioAoClienteException $e) {
            return $this->json(['erro' => $e->getMessage(), 'enviados' => $e->enviados], Response::HTTP_BAD_GATEWAY);
        }

        return $this->json(['sucesso' => true, 'mensagem' => $saida->mensagem(), 'enviados' => $saida->enviados, 'jaEnviados' => $saida->jaEnviados, 'enviado' => $saida->envio->quando, 'dica' => $saida->envio->dica, 'html' => $this->html($pasta)]);
    }

    /**
     * Guarda, operação e resposta comuns às rotas dos e-mails.
     *
     * @param callable(User, Tenant): ?string $operacao devolve o aviso da tela, se houver
     */
    private function alterar(Pasta $pasta, Request $request, callable $operacao): JsonResponse
    {
        $recusa = $this->recusa($pasta, 'edit', $request);
        if ($recusa !== null) {
            return $recusa;
        }

        try {
            $mensagem = $operacao($this->usuario(), $this->tenant());
        } catch (EmailDoClienteNaoEncontradoException $e) {
            return $this->json(['erro' => $e->getMessage()], Response::HTTP_NOT_FOUND);
        } catch (EmailDoClienteEmConflitoException $e) {
            return $this->json(['erro' => $e->getMessage()], Response::HTTP_CONFLICT);
        } catch (EmailDoClienteRecusadoException $e) {
            return $this->json(['erro' => $e->getMessage()], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return $this->json(['sucesso' => true, 'mensagem' => $mensagem, 'html' => $this->html($pasta)]);
    }

    private function html(Pasta $pasta): string
    {
        return $this->renderView('pasta/_push_cliente.html.twig', ['pasta' => $pasta, 'cliente' => $this->montar->executar($pasta, $this->tenant())]);
    }

    /** Escritório (404), permissão sobre a pasta (403) e, nos POST, o CSRF (403). */
    private function recusa(Pasta $pasta, string $acao, ?Request $request): ?JsonResponse
    {
        $tenant = $this->tenantContext->getCurrentTenant();
        if ($tenant === null || $pasta->getTenant() !== $tenant) {
            return $this->json(['erro' => 'Pasta não encontrada.'], Response::HTTP_NOT_FOUND);
        }
        if (!$this->permissionChecker->canAccessResource($this->usuario(), $tenant, 'pasta', (int) $pasta->getId(), $acao)) {
            return $this->json(['erro' => 'Sem permissão para esta ação na pasta.'], Response::HTTP_FORBIDDEN);
        }
        if ($request !== null && !$this->isCsrfTokenValid('pasta_push_cliente_' . $pasta->getId(), (string) $request->request->get('_token'))) {
            return $this->json(['erro' => 'Token de segurança inválido.'], Response::HTTP_FORBIDDEN);
        }

        return null;
    }

    private function recusaSemIa(): ?JsonResponse
    {
        return $this->permissionChecker->canAccessModule($this->usuario(), $this->tenant(), DisponibilidadeDeInteligencia::MODULO)
            ? null
            : $this->json(['erro' => 'Sem acesso à BlueJus IA.'], Response::HTTP_FORBIDDEN);
    }

    private function usuario(): User
    {
        $usuario = $this->getUser();
        \assert($usuario instanceof User);

        return $usuario;
    }

    private function tenant(): Tenant
    {
        $tenant = $this->tenantContext->getCurrentTenant();
        \assert($tenant instanceof Tenant);

        return $tenant;
    }
}
