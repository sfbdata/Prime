<?php

declare(strict_types=1);

namespace App\Cobranca\Controller;

use App\Cobranca\Exception\ListaGrandeDemaisParaExportarException;
use App\Cobranca\Repository\CarteiraRepository;
use App\Cobranca\Service\ListaCarteiraXlsxExporter;
use App\Cobranca\UseCase\ListarCasosParaExportarCarteiraUseCase;
use App\Service\PermissionChecker;
use App\Service\Tenant\TenantContext;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * "Exportar lista (.xlsx)" do ⋮ da carteira (desenho Carteira 1B, L75-84). Só leitura.
 *
 * A guarda é a MESMA da tela da carteira (`CarteiraController::show`): gate de módulo `cobrancas`
 * (sem ele, o mesmo redirect com aviso da tela) e carteira resolvida pelo tenant atual (de outro
 * escritório é 404, nunca 403: não confirma que o id existe). Quem vê a lista pode levá-la; exportar
 * não exige capacidade além de ver.
 *
 * Os parâmetros de recorte são os da URL da tela (`busca`, `estado`, `ordenar`, `direcao`), com a
 * mesma normalização do `show()`: a planilha é a lista que o usuário está olhando, sem a paginação.
 */
#[Route('/cobrancas')]
#[IsGranted('ROLE_USER')]
final class CarteiraExportacaoController extends AbstractController
{
    use AutorizacaoCobranca;

    public function __construct(
        private readonly PermissionChecker $permissionChecker,
        private readonly TenantContext $tenantContext,
        private readonly CarteiraRepository $carteiraRepository,
        private readonly ListarCasosParaExportarCarteiraUseCase $listarCasos,
        private readonly ListaCarteiraXlsxExporter $exporter,
    ) {
    }

    #[Route('/carteiras/{id}/exportar', name: 'cobranca_carteira_exportar', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function exportar(int $id, Request $request): Response
    {
        $tenant = $this->tenantComModulo();
        if ($tenant === null) {
            return $this->semAcesso();
        }

        $carteira = $this->carteiraRepository->findOneByIdDoTenant($id, $tenant);
        if ($carteira === null) {
            throw $this->createNotFoundException('Carteira de cobrança não encontrada.');
        }

        $busca = trim((string) $request->query->get('busca', ''));
        $ordenar = (string) $request->query->get('ordenar', '') ?: 'saldo';
        $direcao = strtolower((string) $request->query->get('direcao', 'desc')) === 'asc' ? 'asc' : 'desc';
        $estado = (string) $request->query->get('estado', '');

        try {
            $lista = $this->listarCasos->executar($carteira, $busca, $ordenar, $direcao, $estado);
        } catch (ListaGrandeDemaisParaExportarException $e) {
            // Volta para a tela com o MESMO recorte, para o usuário estreitá-lo a partir de onde estava.
            $this->addFlash('warning', $e->getMessage());

            return $this->redirectToRoute('cobranca_carteira_show', array_filter(
                ['id' => $id, 'busca' => $busca, 'estado' => $estado, 'ordenar' => $ordenar, 'direcao' => $direcao],
                static fn (int|string $valor): bool => $valor !== '',
            ));
        }

        // Nome sem dado pessoal: nem credor, nem devedor, nem quem exportou. Só o número da carteira e o dia.
        $nomeArquivo = sprintf('carteira-%d-%s.xlsx', $id, (new \DateTimeImmutable('today'))->format('Y-m-d'));

        return $this->exporter->exportar($lista['casos'], $lista['rotuloObjeto'], $nomeArquivo);
    }
}
