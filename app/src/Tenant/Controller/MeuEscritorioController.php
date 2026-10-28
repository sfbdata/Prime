<?php

declare(strict_types=1);

namespace App\Tenant\Controller;

use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Repository\TenantRepository;
use App\Repository\UserTenantRepository;
use App\Service\Tenant\TenantContext;
use App\Shared\Armazenamento\ArmazenamentoDeArquivos;
use App\Shared\Http\EntregaDeArquivo;
use App\Tenant\Armazenamento\ChavesDeTenant;
use App\Tenant\DTO\SalvarDadosDoEscritorioInput;
use App\Tenant\Exception\AcessoAoEscritorioNegado;
use App\Tenant\Exception\DadosDoEscritorioInvalidos;
use App\Tenant\Exception\PedidoDeExclusaoRecusado;
use App\Tenant\Service\PreparadorDeLogoDoEscritorio;
use App\Tenant\Service\RegistroDeAcessoNegadoAoEscritorio;
use App\Tenant\UseCase\ConferirExclusaoDoEscritorioUseCase;
use App\Tenant\UseCase\ConsultarMeuEscritorioUseCase;
use App\Tenant\UseCase\EnviarLogoDoEscritorioUseCase;
use App\Tenant\UseCase\RemoverLogoDoEscritorioUseCase;
use App\Tenant\UseCase\SalvarDadosDoEscritorioUseCase;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * As ações da tela Meu Escritório (spec docs/specs/meu-escritorio.md): salvar os dados, enviar, remover e servir a logo
 * e conferir a exclusão. JSON com CSRF no cabeçalho `X-CSRF-Token` (id `meu_escritorio`). O escritório é o da ROTA e
 * é validado a cada chamada: quem não é o primário dele nem Master recebe 403 e a tentativa vai para o audit_log (D1),
 * sem o conteúdo enviado. Ler a logo vale para o Master e para quem tem vínculo ativo com o escritório.
 */
#[Route('/tenant/{tenantId}/escritorio', requirements: ['tenantId' => '\d+'])]
final class MeuEscritorioController extends AbstractController
{
    public const CSRF = 'meu_escritorio';

    public function __construct(
        private readonly TenantRepository $escritorios,
        private readonly UserTenantRepository $vinculos,
        private readonly ConsultarMeuEscritorioUseCase $consultarUseCase,
        private readonly SalvarDadosDoEscritorioUseCase $salvarUseCase,
        private readonly EnviarLogoDoEscritorioUseCase $enviarLogoUseCase,
        private readonly RemoverLogoDoEscritorioUseCase $removerLogoUseCase,
        private readonly ConferirExclusaoDoEscritorioUseCase $conferirExclusaoUseCase,
        private readonly RegistroDeAcessoNegadoAoEscritorio $acessoNegado,
        private readonly ArmazenamentoDeArquivos $armazenamento,
        private readonly EntregaDeArquivo $entrega,
        private readonly TenantContext $tenantContext,
    ) {
    }

    #[Route('/dados', name: 'meu_escritorio_salvar', methods: ['POST'])]
    public function salvar(Request $request, int $tenantId): JsonResponse
    {
        return $this->proteger($request, $tenantId, 'meu_escritorio_salvar', function (User $usuario, Tenant $tenant) use ($request): JsonResponse {
            $this->salvarUseCase->executar($usuario, $tenant, SalvarDadosDoEscritorioInput::fromRequest($request));

            return $this->escritorioJson($usuario, $tenant);
        });
    }

    #[Route('/logo', name: 'meu_escritorio_logo_enviar', methods: ['POST'])]
    public function enviarLogo(Request $request, int $tenantId): JsonResponse
    {
        return $this->proteger($request, $tenantId, 'meu_escritorio_logo_enviar', function (User $usuario, Tenant $tenant) use ($request): JsonResponse {
            $arquivo = $request->files->get('logo');
            if (!$arquivo instanceof UploadedFile || !$arquivo->isValid()) {
                throw new DadosDoEscritorioInvalidos(PreparadorDeLogoDoEscritorio::TIPO_RECUSADO, 'logo');
            }
            if ((int) $arquivo->getSize() > PreparadorDeLogoDoEscritorio::LIMITE_EM_BYTES) {
                throw new DadosDoEscritorioInvalidos(PreparadorDeLogoDoEscritorio::GRANDE_DEMAIS, 'logo');
            }
            $this->enviarLogoUseCase->executar($usuario, $tenant, $arquivo->getContent());

            return $this->escritorioJson($usuario, $tenant);
        });
    }

    #[Route('/logo/remover', name: 'meu_escritorio_logo_remover', methods: ['POST'])]
    public function removerLogo(Request $request, int $tenantId): JsonResponse
    {
        return $this->proteger($request, $tenantId, 'meu_escritorio_logo_remover', function (User $usuario, Tenant $tenant): JsonResponse {
            $this->removerLogoUseCase->executar($usuario, $tenant);

            return $this->escritorioJson($usuario, $tenant);
        });
    }

    #[Route('/exclusao/conferir', name: 'meu_escritorio_exclusao_conferir', methods: ['POST'])]
    public function conferirExclusao(Request $request, int $tenantId): JsonResponse
    {
        return $this->proteger($request, $tenantId, 'meu_escritorio_exclusao_conferir', function (User $usuario, Tenant $tenant) use ($request): JsonResponse {
            $corpo = $request->getPayload()->all();
            $texto = static fn (string $chave): string => is_string($corpo[$chave] ?? null) ? $corpo[$chave] : '';
            $this->conferirExclusaoUseCase->executar($usuario, $tenant, $texto('senha'), $texto('nome'), $this->tenantContext->getCurrentTenant()?->getId() === $tenant->getId());

            return $this->json(['ok' => true]);
        });
    }

    #[Route('/logo', name: 'meu_escritorio_logo', methods: ['GET'])]
    public function logo(int $tenantId): Response
    {
        $usuario = $this->getUser();
        $tenant = $this->escritorios->find($tenantId);
        if (!$usuario instanceof User || $tenant === null) {
            throw $this->createNotFoundException();
        }
        $master = in_array('ROLE_SUPER_ADMIN', $usuario->getRoles(), true);
        if (!$master && !$this->vinculos->existeVinculoAtivo($usuario, $tenant)) {
            throw $this->createAccessDeniedException();
        }
        // A chave sai do escritório da ROTA já validado: nunca de parâmetro da requisição.
        $chave = ChavesDeTenant::logo($tenant);
        if ($chave === null || !$this->armazenamento->existe($chave)) {
            throw $this->createNotFoundException();
        }

        return $this->entrega->resposta($chave, 'logo', inline: true);
    }

    /**
     * Porta comum dos POST: usuário, escritório da rota, CSRF e as recusas de domínio como JSON. A tentativa de quem não
     * pode (D1) é gravada no audit_log antes do 403.
     *
     * @param \Closure(User, Tenant): JsonResponse $acao
     */
    private function proteger(Request $request, int $tenantId, string $rota, \Closure $acao): JsonResponse
    {
        $usuario = $this->getUser();
        if (!$usuario instanceof User) {
            return $this->json(['ok' => false, 'erro' => 'Entre novamente.'], Response::HTTP_UNAUTHORIZED);
        }
        $tenant = $this->escritorios->find($tenantId);
        if ($tenant === null) {
            return $this->json(['ok' => false, 'erro' => 'Escritório não encontrado.'], Response::HTTP_NOT_FOUND);
        }
        if (!$this->isCsrfTokenValid(self::CSRF, (string) $request->headers->get('X-CSRF-Token'))) {
            return $this->json(['ok' => false, 'erro' => 'Token inválido. Recarregue a página.'], Response::HTTP_FORBIDDEN);
        }

        try {
            return $acao($usuario, $tenant);
        } catch (AcessoAoEscritorioNegado $e) {
            $this->acessoNegado->registrar($usuario, $tenant, $rota);

            return $this->json(['ok' => false, 'erro' => $e->getMessage()], Response::HTTP_FORBIDDEN);
        } catch (DadosDoEscritorioInvalidos $e) {
            return $this->json(['ok' => false, 'erro' => $e->getMessage(), 'campo' => $e->campo], Response::HTTP_UNPROCESSABLE_ENTITY);
        } catch (PedidoDeExclusaoRecusado $e) {
            return $this->json(['ok' => false, 'erro' => $e->getMessage()], $e->status);
        }
    }

    private function escritorioJson(User $usuario, Tenant $tenant): JsonResponse
    {
        return $this->json(['ok' => true, 'escritorio' => $this->consultarUseCase->executar($usuario, $tenant)->paraArray()]);
    }
}
