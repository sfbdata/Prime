<?php

declare(strict_types=1);

namespace App\Conector\Controller;

use App\Conector\Service\GuiaDoConector;
use App\Conector\Service\ServidorDeAutorizacao;
use App\Conector\UseCase\RevogarConexaoUseCase;
use App\Entity\Auth\User;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * "Conexões de I.A." no Meu Perfil (`docs/specs/mcp-remoto-oauth.md`, 13.1 linhas dos PRs 8 e 15, e 14.e): a pessoa revoga
 * uma conexão dela e lê o passo a passo para conectar. A lista sai no próprio Meu Perfil (`conector/_conexoes_no_perfil.html.twig`); aqui só o POST do botão
 * "Revogar", confirmado no modal.
 *
 * Só a pessoa logada, e só a conexão dela: o id da URL passa pela guarda de posse do UseCase, sem escritório da URL nem
 * da sessão (a conexão pode ser de outro escritório dela). Conexão alheia, já revogada ou inexistente dá o mesmo aviso.
 */
final class ConexoesNoPerfilController extends AbstractController
{
    public function __construct(
        private readonly RevogarConexaoUseCase $revogarConexao,
        private readonly TranslatorInterface $tradutor,
        private readonly GuiaDoConector $guia,
    ) {
    }

    /**
     * "Como conectar o Claude ou o ChatGPT" (13.1, linha do PR 15): o passo a passo, a URL exata do recurso (o Claude exige
     * o `resource` igual ao que a pessoa digitou, 9.3) e as ferramentas que o servidor tem hoje. Sem desenho, pela
     * convenção do Meu Perfil (14.e). Para qualquer pessoa logada: o próprio app avisa se o escritório não ligou o conector.
     */
    #[Route('/perfil/conexoes-de-ia/como-conectar', name: 'conector_perfil_guia', methods: ['GET'])]
    public function guia(): Response
    {
        if (!$this->getUser() instanceof User) {
            throw $this->createAccessDeniedException();
        }

        return $this->render('conector/guia.html.twig', [
            'urlDoConector' => ServidorDeAutorizacao::RESOURCE,
            'ferramentas' => $this->guia->ferramentas(),
        ]);
    }

    #[Route('/perfil/conexoes-de-ia/{id}/revogar', name: 'conector_perfil_revogar', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function revogar(int $id, Request $request): Response
    {
        $pessoa = $this->getUser();
        if (!$pessoa instanceof User) {
            throw $this->createAccessDeniedException();
        }
        if (!$this->isCsrfTokenValid('conector_revogar_' . $id, (string) $request->request->get('_token'))) {
            $this->addFlash('error', $this->tradutor->trans('conector.perfil.token_invalido'));

            return $this->redirectToRoute('app_profile');
        }

        try {
            $app = $this->revogarConexao->executar($id, $pessoa);
        } catch (\DomainException) {
            $this->addFlash('error', $this->tradutor->trans('conector.perfil.nao_encontrada'));

            return $this->redirectToRoute('app_profile');
        }

        $this->addFlash('success', $this->tradutor->trans('conector.perfil.revogada', ['app' => $app]));

        return $this->redirectToRoute('app_profile');
    }
}
