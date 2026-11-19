<?php

declare(strict_types=1);

namespace App\Master\Controller;

use App\Conector\DTO\EscritorioNoConectorOutput;
use App\Conector\UseCase\ObterConectorNoPainelDoMasterUseCase;
use App\Entity\Auth\User;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * O conector de I.A. na aba "Configurações da I.A." do Painel do Master (`docs/specs/mcp-remoto-oauth.md`, 14.a
 * e 14.e: sem desenho, pela convenção do sistema). Mora no domínio Master, como o {@see IaDoMasterController}: a rota e
 * a guarda são do painel; as regras moram nos UseCases do domínio Conector, que conferem o Master de novo.
 *
 * Só leitura, em JSON: a tabela dos escritórios com o "ligado" do administrador, o aceite e a contagem de conexões. O
 * Master não libera escritório por escritório (decisão do Samuel, 19/11); o interruptor de emergência é a chave geral
 * `conector_ia` do catálogo de recursos.
 */
#[IsGranted('ROLE_SUPER_ADMIN')]
final class ConectorDoMasterController extends AbstractController
{
    public function __construct(
        private readonly ObterConectorNoPainelDoMasterUseCase $painel,
    ) {
    }

    #[Route('/master/conector', name: 'master_conector_dados', methods: ['GET'])]
    public function dados(): JsonResponse
    {
        return $this->json($this->estado());
    }

    /** @return array{escritorios: list<array<string, mixed>>} */
    private function estado(): array
    {
        return ['escritorios' => array_map(self::escritorioEmJson(...), $this->painel->executar($this->master()))];
    }

    /** @return array<string, mixed> */
    private static function escritorioEmJson(EscritorioNoConectorOutput $e): array
    {
        return [
            'tenantId' => $e->tenantId,
            'nome' => $e->nome,
            'ligado' => $e->ligado,
            'aceiteEm' => $e->aceiteEm?->format('Y-m-d'),
            'aceiteVigente' => $e->aceiteVigente,
            'conexoesAtivas' => $e->conexoesAtivas,
        ];
    }

    private function master(): User
    {
        $usuario = $this->getUser();
        if (!$usuario instanceof User) {
            throw $this->createAccessDeniedException();
        }

        return $usuario;
    }
}
