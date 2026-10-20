<?php

declare(strict_types=1);

namespace App\Pasta\Controller;

use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Pasta\DTO\ConfigurarCadeadoInput;
use App\Pasta\DTO\PedirAcessoInput;
use App\Pasta\Entity\ModoDoCadeado;
use App\Pasta\Entity\NivelDeAcesso;
use App\Pasta\Entity\Pasta;
use App\Pasta\Exception\CadeadoRecusadoException;
use App\Pasta\Exception\PastaDeOutroEscritorioException;
use App\Pasta\Exception\SemPermissaoParaVerPastaException;
use App\Pasta\UseCase\ConfigurarCadeadoDaPastaUseCase;
use App\Pasta\UseCase\DecidirPedidoDeAcessoUseCase;
use App\Pasta\UseCase\PedirAcessoAPastaUseCase;
use App\Pasta\UseCase\RemoverCadeadoDaPastaUseCase;
use App\Service\Tenant\TenantContext;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * O cadeado da pasta (spec pasta-cadeado): "Trancar" e "Remover cadeado" do modal, o pedido de acesso da
 * tela trancada e da faixa de somente visualização, e a decisão de quem configura.
 *
 * Guardas: pasta do escritório da sessão (404, no UseCase: o resolver busca por PK e o TenantFilter não se
 * aplica a `find()`), quem configura (403, no UseCase) e CSRF por pasta. Os formulários da tela voltam
 * para a pasta com o aviso; a chamada XHR recebe JSON. Pasta excluída recebe a recusa do
 * `PastaSomenteLeituraListener`, como todo POST nela.
 */
#[Route('/pasta')]
final class PastaCadeadoController extends AbstractController
{
    public function __construct(
        private readonly ConfigurarCadeadoDaPastaUseCase $configurar,
        private readonly RemoverCadeadoDaPastaUseCase $remover,
        private readonly PedirAcessoAPastaUseCase $pedir,
        private readonly DecidirPedidoDeAcessoUseCase $decidir,
        private readonly TenantContext $tenantContext,
    ) {
    }

    #[Route('/{id}/cadeado', name: 'pasta_cadeado_salvar', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function salvar(Pasta $pasta, Request $request): Response
    {
        return $this->acao($request, $pasta, 'pasta_cadeado_', function (User $ator, Tenant $tenant) use ($pasta, $request): array {
            $modo  = ModoDoCadeado::tryFrom((string) $request->request->get('modo')) ?? throw new \DomainException('Escolha um modo para o cadeado.');
            $grade = $request->request->all('niveis');
            $saida = $this->configurar->executar(new ConfigurarCadeadoInput($pasta, $ator, $tenant, $modo, ConfigurarCadeadoInput::gradeDoFormulario($grade)));

            return ['Pasta ' . $pasta->getNup() . ' trancada', $saida->paraJson()];
        });
    }

    #[Route('/{id}/cadeado/remover', name: 'pasta_cadeado_remover', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function remover(Pasta $pasta, Request $request): Response
    {
        return $this->acao($request, $pasta, 'pasta_cadeado_', function (User $ator, Tenant $tenant) use ($pasta): array {
            $saida = $this->remover->executar($pasta, $ator, $tenant);

            return ['Cadeado removido da pasta ' . $pasta->getNup(), $saida->paraJson()];
        });
    }

    #[Route('/{id}/acesso/pedidos', name: 'pasta_acesso_pedir', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function pedir(Pasta $pasta, Request $request): Response
    {
        return $this->acao($request, $pasta, 'pasta_acesso_', function (User $ator, Tenant $tenant) use ($pasta, $request): array {
            $nivel = NivelDeAcesso::tryFrom((string) $request->request->get('nivel', NivelDeAcesso::Editar->value)) ?? NivelDeAcesso::Editar;
            $saida = $this->pedir->executar(new PedirAcessoInput($pasta, $ator, $tenant, $nivel, (string) $request->request->get('motivo', '')));

            return ['Pedido enviado ao usuário primário', $saida->paraJson()];
        });
    }

    #[Route('/{id}/acesso/pedidos/{pedidoId}/decidir', name: 'pasta_acesso_decidir', requirements: ['id' => '\d+', 'pedidoId' => '\d+'], methods: ['POST'])]
    public function decidir(Pasta $pasta, int $pedidoId, Request $request): Response
    {
        return $this->acao($request, $pasta, 'pasta_cadeado_', function (User $ator, Tenant $tenant) use ($pasta, $pedidoId, $request): array {
            $decisao = (string) $request->request->get('decisao');
            $saida   = $this->decidir->executar($pedidoId, $pasta, $ator, $tenant, $decisao);

            return [$saida->aviso, $saida->cadeado->paraJson()];
        });
    }

    /**
     * O molde das quatro ações: escritório, CSRF, o UseCase e a resposta (JSON para XHR, volta para a
     * pasta com o aviso para formulário). O UseCase devolve [aviso, dados].
     *
     * @param callable(User, Tenant): array{0: string, 1: array<string, mixed>} $executar
     */
    private function acao(Request $request, Pasta $pasta, string $prefixoDoToken, callable $executar): Response
    {
        /** @var User $ator */
        $ator   = $this->getUser();
        $tenant = $this->tenantContext->getCurrentTenant();
        if ($tenant === null || $pasta->getTenant() !== $tenant) {
            throw $this->createNotFoundException('Pasta não encontrada.');
        }
        if (!$this->isCsrfTokenValid($prefixoDoToken . $pasta->getId(), (string) $request->request->get('_token'))) {
            return $this->resposta($request, $pasta, 'Token de segurança inválido.', [], Response::HTTP_FORBIDDEN);
        }

        try {
            [$aviso, $dados] = $executar($ator, $tenant);
        } catch (PastaDeOutroEscritorioException $e) {
            throw $this->createNotFoundException($e->getMessage());
        } catch (CadeadoRecusadoException|SemPermissaoParaVerPastaException $e) {
            return $this->resposta($request, $pasta, $e->getMessage(), [], Response::HTTP_FORBIDDEN);
        } catch (UniqueConstraintViolationException) {
            return $this->resposta($request, $pasta, 'Você já pediu acesso a esta pasta hoje. Aguarde o usuário primário.', [], Response::HTTP_UNPROCESSABLE_ENTITY);
        } catch (\DomainException $e) {
            return $this->resposta($request, $pasta, $e->getMessage(), [], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return $this->resposta($request, $pasta, $aviso, $dados, Response::HTTP_OK);
    }

    /** @param array<string, mixed> $dados */
    private function resposta(Request $request, Pasta $pasta, string $mensagem, array $dados, int $status): Response
    {
        if ($request->isXmlHttpRequest()) {
            $ok = $status === Response::HTTP_OK;

            return new JsonResponse(['status' => $ok ? 'ok' : 'erro', 'mensagem' => $mensagem] + ($ok ? $dados : ['erro' => $mensagem]), $status);
        }

        $this->addFlash($status === Response::HTTP_OK ? 'success' : 'warning', $mensagem);

        return $this->redirectToRoute('pasta_show', ['id' => $pasta->getId()]);
    }
}
