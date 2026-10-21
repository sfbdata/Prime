<?php

declare(strict_types=1);

namespace App\Expediente\Controller;

use App\Entity\Auth\User;
use App\Expediente\DTO\RelatorioDeProcessosOpcoes;
use App\Pasta\DTO\RelatorioPastasParadasOutput;
use App\Pasta\UseCase\GerarRelatorioPastasParadasUseCase;
use App\Service\PermissionChecker;
use App\Service\Tenant\TenantContext;
use App\Shared\Service\GeradorDePdf;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Relatório mensal de pastas paradas (desenho 02: `paradasPDF`, `paradasHTML` L4739-4749,
 * `paradasBaixarPDF` L4815; spec docs/specs/pastas-paradas.md, "Página e PDF").
 *
 * Uma folha, três entregas, como o relatório geral de processos:
 *  - página própria (`expediente_pastas_paradas`): o link do aviso mensal abre aqui;
 *  - fragmento (`?fragmento=1`): só a folha, para uma prévia;
 *  - PDF (`expediente_pastas_paradas_pdf`): a mesma folha no Dompdf, rodapé "BlueJus · página k de n".
 *
 * Ler: módulo Expediente (403 sem ele); o que cada um vê é decidido no UseCase. As opções de
 * cabeçalho e tamanho do texto são as da prévia do desenho (`impPrev`), lidas como no relatório irmão.
 */
#[Route('/expediente/pastas-paradas')]
final class PastasParadasController extends AbstractController
{
    public function __construct(
        private readonly GerarRelatorioPastasParadasUseCase $gerarRelatorio,
        private readonly PermissionChecker $permissionChecker,
        private readonly TenantContext $tenantContext,
        private readonly GeradorDePdf $geradorDePdf,
    ) {
    }

    #[Route('', name: 'expediente_pastas_paradas', methods: ['GET'])]
    public function relatorio(Request $request): Response
    {
        $resposta = $this->render('expediente/pastas_paradas.html.twig', $this->contexto($request) + [
            'modo' => $request->query->getBoolean('fragmento') ? 'fragmento' : 'pagina',
        ]);
        $resposta->headers->set('X-Robots-Tag', 'noindex');

        return $resposta;
    }

    #[Route('/pdf', name: 'expediente_pastas_paradas_pdf', methods: ['GET'])]
    public function pdf(Request $request): Response
    {
        $contexto = $this->contexto($request);
        $html     = $this->renderView('expediente/pastas_paradas.html.twig', $contexto + [
            'modo'    => 'pdf',
            'logoPdf' => $this->geradorDePdf->logoEmbutido(),
        ]);

        return $this->geradorDePdf->resposta($this->geradorDePdf->gerar($html), $contexto['relatorio']->titulo(), $contexto['impressoEm']);
    }

    /**
     * Guarda de leitura (módulo Expediente) e tudo o que a folha precisa.
     *
     * @return array{relatorio: RelatorioPastasParadasOutput, opcoes: RelatorioDeProcessosOpcoes, impressoEm: \DateTimeImmutable, impressoPor: ?string}
     */
    private function contexto(Request $request): array
    {
        /** @var User $usuario */
        $usuario = $this->getUser();
        $tenant  = $this->tenantContext->getCurrentTenant();
        if ($tenant === null || !$this->permissionChecker->canAccessModule($usuario, $tenant, 'expediente')) {
            throw $this->createAccessDeniedException('Sem acesso ao módulo Expediente.');
        }

        $relatorio = $this->gerarRelatorio->executar($tenant, $usuario);

        return [
            'relatorio'   => $relatorio,
            'opcoes'      => RelatorioDeProcessosOpcoes::daQuery($request->query),
            'impressoEm'  => $relatorio->hoje,
            'impressoPor' => $usuario->getFullName(),
        ];
    }
}
