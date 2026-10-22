<?php

declare(strict_types=1);

namespace App\Tarefa\Controller;

use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Service\PermissionChecker;
use App\Service\Tenant\TenantContext;
use App\Tarefa\DTO\ConfigurarCaixaDaEsteiraInput;
use App\Tarefa\Enum\EtapaDaPasta;
use App\Tarefa\Exception\CaixaNaoConfiguravelException;
use App\Tarefa\UseCase\ConfigurarCaixaDaEsteiraUseCase;
use App\Tarefa\UseCase\RestaurarCaixaDaEsteiraUseCase;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/**
 * "Salvar" e "Restaurar padrão" do modal "Configurar caixa" da esteira (desenho 04 de 22/10, dc L133).
 *
 * POST de formulário comum com CSRF, que volta para Minhas Metas com o aviso do desenho ("Caixa salva."). As
 * rotas começam por `tarefa_minhas`: o liga/desliga do Master da tela Minhas Metas (`CatalogoDeRecursos`,
 * prefixo) desliga as duas junto. Guarda: módulo `tarefas`, como a tela; quem pode mudar a caixa (D2) é regra
 * do UseCase.
 */
#[Route('/tarefas/minhas/caixas')]
final class CaixaDaEsteiraController extends AbstractController
{
    private const ETAPAS = 'comercial|abertura|controladoria|documentacao|juridico|protocolo|acompanhamento|encerramento';

    public function __construct(
        private readonly TenantContext $tenantContext,
        private readonly PermissionChecker $permissionChecker,
    ) {
    }

    #[Route('/{etapa}', name: 'tarefa_minhas_caixa_salvar', requirements: ['etapa' => self::ETAPAS], methods: ['POST'])]
    public function salvar(string $etapa, Request $request, ConfigurarCaixaDaEsteiraUseCase $useCase, ValidatorInterface $validator): Response
    {
        [$usuario, $tenant] = $this->acesso();
        $this->exigirToken($request, 'caixa_esteira_' . $etapa);

        $input = new ConfigurarCaixaDaEsteiraInput(
            nomeDaCaixa: trim((string) $request->request->get('nome_da_caixa', '')),
            nomeDaEtapa: trim((string) $request->request->get('nome_da_etapa', '')),
            avisados: array_values(array_map('intval', $request->request->all('avisados'))),
        );

        $erros = $validator->validate($input);
        if (count($erros) > 0) {
            $this->addFlash('danger', (string) $erros->get(0)->getMessage());

            return $this->redirectToRoute('tarefa_minhas');
        }

        try {
            $useCase->executar($usuario, $tenant, EtapaDaPasta::from($etapa), $input);
            $this->addFlash('success', $this->avisoDeSalva(count(array_unique($input->avisados))));
        } catch (CaixaNaoConfiguravelException $e) {
            $this->addFlash('danger', $e->getMessage());
        }

        return $this->redirectToRoute('tarefa_minhas');
    }

    #[Route('/{etapa}/restaurar', name: 'tarefa_minhas_caixa_restaurar', requirements: ['etapa' => self::ETAPAS], methods: ['POST'])]
    public function restaurar(string $etapa, Request $request, RestaurarCaixaDaEsteiraUseCase $useCase): Response
    {
        [$usuario, $tenant] = $this->acesso();
        $this->exigirToken($request, 'caixa_esteira_' . $etapa);

        try {
            $useCase->executar($usuario, $tenant, EtapaDaPasta::from($etapa));
        } catch (CaixaNaoConfiguravelException $e) {
            $this->addFlash('danger', $e->getMessage());
        }

        return $this->redirectToRoute('tarefa_minhas');
    }

    /** O aviso do desenho (dc L190): "Caixa salva." ou "Caixa salva. N pessoa(s) serão avisadas quando chegar demanda." */
    private function avisoDeSalva(int $avisados): string
    {
        return $avisados === 0
            ? 'Caixa salva.'
            : sprintf('Caixa salva. %d pessoa(s) serão avisadas quando chegar demanda.', $avisados);
    }

    /** @return array{0: User, 1: Tenant} */
    private function acesso(): array
    {
        $usuario = $this->getUser();
        $tenant  = $this->tenantContext->getCurrentTenant();
        if (!$usuario instanceof User || $tenant === null) {
            throw $this->createAccessDeniedException('Sem tenant selecionado.');
        }
        if (!$this->permissionChecker->canAccessModule($usuario, $tenant, 'tarefas')) {
            throw $this->createAccessDeniedException('Sem acesso ao módulo Tarefas.');
        }

        return [$usuario, $tenant];
    }

    private function exigirToken(Request $request, string $id): void
    {
        if (!$this->isCsrfTokenValid($id, (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Token CSRF inválido.');
        }
    }
}
