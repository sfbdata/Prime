<?php

declare(strict_types=1);

namespace App\Master\Controller;

use App\Entity\Auth\User;
use App\Master\DTO\SalvarConfiguracaoGlobalInput;
use App\Master\Exception\ConfiguracaoRecusada;
use App\Master\UseCase\ConsultarConfiguracaoGlobalUseCase;
use App\Master\UseCase\SalvarConfiguracaoGlobalUseCase;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Seção "Configurações globais" do /master (desenho 05 Perfis 1.2 VS 25.10.26; spec
 * docs/specs/perfis-1.2-vs-25-10-metas-mapa-config.md, P2). Só o Master: ROLE_SUPER_ADMIN na classe.
 *
 * Token CSRF `master_configuracoes` no header `X-CSRF-Token`. As duas rotas respondem o estado
 * ({@see \App\Master\DTO\ConfiguracaoGlobalOutput::paraArray()}); 422 para valor recusado.
 */
#[IsGranted('ROLE_SUPER_ADMIN')]
final class ConfiguracoesGlobaisController extends AbstractController
{
    public const CSRF = 'master_configuracoes';

    #[Route('/master/configuracoes', name: 'master_configuracoes_dados', methods: ['GET'])]
    public function dados(ConsultarConfiguracaoGlobalUseCase $consultar): JsonResponse
    {
        return $this->json($consultar->executar()->paraArray());
    }

    /** Corpo `{valores: {chave: texto}}`. */
    #[Route('/master/configuracoes', name: 'master_configuracoes_salvar', methods: ['POST'])]
    public function salvar(Request $request, SalvarConfiguracaoGlobalUseCase $salvar): JsonResponse
    {
        if (!$this->isCsrfTokenValid(self::CSRF, (string) $request->headers->get('X-CSRF-Token', ''))) {
            return $this->json(['erro' => 'Token CSRF inválido. Recarregue a página.'], Response::HTTP_FORBIDDEN);
        }

        try {
            $saida = $salvar->executar(SalvarConfiguracaoGlobalInput::doJson($this->ator(), $this->corpo($request)));
        } catch (ConfiguracaoRecusada $e) {
            return $this->json(['erro' => $e->getMessage()], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return $this->json($saida->paraArray());
    }

    private function ator(): ?User
    {
        $ator = $this->getUser();

        return $ator instanceof User ? $ator : null;
    }

    /** @return array<mixed> */
    private function corpo(Request $request): array
    {
        try {
            $dados = json_decode($request->getContent(), true, 8, \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return [];
        }

        return is_array($dados) ? $dados : [];
    }
}
