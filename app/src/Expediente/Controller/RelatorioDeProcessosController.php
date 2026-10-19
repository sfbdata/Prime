<?php

declare(strict_types=1);

namespace App\Expediente\Controller;

use App\Entity\Auth\User;
use App\Entity\Permission\AccessRequest;
use App\Entity\Tenant\Tenant;
use App\Expediente\DTO\RelatorioDeProcessosOpcoes;
use App\Pasta\Entity\Pasta;
use App\Pasta\Repository\PastaRepository;
use App\Pasta\UseCase\EnviarRelatorioSemProcessoUseCase;
use App\Pasta\UseCase\GerarRelatorioSemProcessoUseCase;
use App\Service\PermissionChecker;
use App\Service\Tenant\TenantContext;
use App\Shared\Service\GeradorDePdf;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Relatório geral de processos das pastas e o envio à Controladoria (desenho 1.2.3: `spPDF`,
 * `spHTML` L4538-4555, `spEnviar` L4566-4568; spec docs/specs/relatorio-processos-controladoria.md).
 *
 * Uma folha, três entregas, como a impressão da pasta:
 *  - página própria (`expediente_relatorio_processos`): o link do aviso da Controladoria abre aqui;
 *  - fragmento (`?fragmento=1`): só a folha, que o relatorio-processos.js põe na prévia;
 *  - PDF (`expediente_relatorio_processos_pdf`): a mesma folha no Dompdf, rodapé "BlueJus · página k de n".
 *
 * Ler: módulo Expediente; o que cada um vê é decidido no UseCase (spec D4). Enviar: quem EDITA a
 * pasta de onde clicou (404 de outro escritório ou excluída, 403 sem edição), com CSRF; um envio
 * manual a cada 30 minutos por escritório (429 acima disso).
 */
#[Route('/expediente/relatorio-processos')]
final class RelatorioDeProcessosController extends AbstractController
{
    public const CSRF_ENVIAR = 'expediente_relatorio_processos_enviar';

    public function __construct(
        private readonly GerarRelatorioSemProcessoUseCase $gerarRelatorio,
        private readonly EnviarRelatorioSemProcessoUseCase $enviarRelatorio,
        private readonly PermissionChecker $permissionChecker,
        private readonly TenantContext $tenantContext,
        private readonly PastaRepository $pastaRepository,
        private readonly GeradorDePdf $geradorDePdf,
    ) {
    }

    #[Route('', name: 'expediente_relatorio_processos', methods: ['GET'])]
    public function relatorio(Request $request): Response
    {
        $resposta = $this->render('expediente/relatorio_processos.html.twig', $this->contexto($request) + [
            'modo' => $request->query->getBoolean('fragmento') ? 'fragmento' : 'pagina',
        ]);
        $resposta->headers->set('X-Robots-Tag', 'noindex');

        return $resposta;
    }

    #[Route('/pdf', name: 'expediente_relatorio_processos_pdf', methods: ['GET'])]
    public function pdf(Request $request): Response
    {
        $contexto = $this->contexto($request);
        $html     = $this->renderView('expediente/relatorio_processos.html.twig', $contexto + [
            'modo'    => 'pdf',
            'logoPdf' => $this->geradorDePdf->logoEmbutido(),
        ]);

        return $this->geradorDePdf->resposta($this->geradorDePdf->gerar($html), $contexto['relatorio']->titulo(), $contexto['impressoEm']);
    }

    #[Route('/enviar', name: 'expediente_relatorio_processos_enviar', methods: ['POST'])]
    public function enviar(Request $request): Response
    {
        /** @var User $usuario */
        $usuario = $this->getUser();
        $isXhr   = $request->isXmlHttpRequest();

        if (!$this->isCsrfTokenValid(self::CSRF_ENVIAR, (string) $request->request->get('_token'))) {
            return $this->recusa($isXhr, 'Token de segurança inválido.', Response::HTTP_FORBIDDEN);
        }

        [$tenant, $pasta, $erro, $status] = $this->pastaDeOrigem($request, $usuario);
        if ($tenant === null || $pasta === null) {
            return $this->recusa($isXhr, $erro, $status);
        }

        $resultado = $this->enviarRelatorio->executar($tenant, $usuario, false);
        if ($resultado->acimaDoTeto) {
            return $this->recusa($isXhr, $resultado->mensagem, Response::HTTP_TOO_MANY_REQUESTS);
        }
        if ($isXhr) {
            return $this->json(['sucesso' => $resultado->enviado, 'mensagem' => $resultado->mensagem, 'avisados' => $resultado->avisados]);
        }

        $this->addFlash($resultado->semControladoria ? 'warning' : 'success', $resultado->mensagem);

        return $this->redirectToRoute('pasta_show', ['id' => $pasta->getId(), '_fragment' => 'processo']);
    }

    /**
     * Guarda de leitura (módulo Expediente) e tudo o que a folha precisa.
     *
     * @return array{relatorio: \App\Pasta\DTO\RelatorioSemProcessoOutput, opcoes: RelatorioDeProcessosOpcoes, impressoEm: \DateTimeImmutable, impressoPor: ?string}
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

    /**
     * A pasta de onde o envio saiu: do escritório da sessão e não excluída (404), editável por quem
     * enviou (403). É ela que decide quem pode enviar (o desenho mostra o ⋮ a quem edita).
     *
     * @return array{0: ?Tenant, 1: ?Pasta, 2: string, 3: int}
     */
    private function pastaDeOrigem(Request $request, User $usuario): array
    {
        $tenant = $this->tenantContext->getCurrentTenant();
        $id     = (string) $request->request->get('pasta', '');
        $pasta  = $tenant !== null && ctype_digit($id) ? $this->pastaRepository->find((int) $id) : null;

        if ($tenant === null || $pasta === null || $pasta->getTenant() !== $tenant || $pasta->estaExcluida()) {
            return [null, null, 'Pasta não encontrada.', Response::HTTP_NOT_FOUND];
        }
        if (!$this->permissionChecker->canAccessResource($usuario, $tenant, AccessRequest::RESOURCE_PASTA, (int) $pasta->getId(), AccessRequest::ACTION_EDIT)) {
            return [null, null, 'Sem permissão para editar esta pasta.', Response::HTTP_FORBIDDEN];
        }

        return [$tenant, $pasta, '', Response::HTTP_OK];
    }

    private function recusa(bool $isXhr, string $mensagem, int $status): Response
    {
        if ($isXhr) {
            return $this->json(['sucesso' => false, 'erro' => $mensagem], $status);
        }
        if ($status === Response::HTTP_NOT_FOUND) {
            throw $this->createNotFoundException($mensagem);
        }
        if ($status === Response::HTTP_TOO_MANY_REQUESTS) {
            throw new HttpException($status, $mensagem);
        }

        throw $this->createAccessDeniedException($mensagem);
    }
}
