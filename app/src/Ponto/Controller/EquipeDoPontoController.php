<?php

declare(strict_types=1);

namespace App\Ponto\Controller;

use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Ponto\Exception\ChoqueDeJornadaException;
use App\Ponto\Exception\EscalaDesconhecidaException;
use App\Ponto\Exception\ForaDaEquipeException;
use App\Ponto\Service\GestaoDoPonto;
use App\Ponto\Service\TextoDoPonto;
use App\Ponto\UseCase\AbonarJustificativasDaEquipeUseCase;
use App\Ponto\UseCase\ConsultarEquipeDoPontoUseCase;
use App\Ponto\UseCase\TrocarEscalaNaEquipeUseCase;
use App\Service\PermissionChecker;
use App\Service\Tenant\TenantContext;
use App\Shared\Trait\ValidaCsrfAjaxTrait;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Aba "Equipe" da tela /ponto (spec `docs/specs/ponto-desenho-0811.md`, E5; desenho 16 L210 a L219). O painel é buscado
 * pelo js/ponto-paineis.js na primeira vez que a aba abre (`data-carregar` no `_abas.html.twig`); a troca de escala é
 * um fetch do js/ponto-equipe.js; o "Abonar selecionadas" é um formulário que volta para a /ponto na aba Equipe.
 *
 * Quem vê e mexe: `GestaoDoPonto::veEquipe` no escritório da sessão (P58). Sem ele, 403.
 */
#[Route('/ponto/equipe')]
final class EquipeDoPontoController extends AbstractController
{
    use ValidaCsrfAjaxTrait;

    public function __construct(
        private readonly TenantContext $tenantContext,
        private readonly PermissionChecker $permissoes,
        private readonly GestaoDoPonto $gestao,
        private readonly TranslatorInterface $tradutor,
    ) {
    }

    #[Route('', name: 'ponto_equipe', methods: ['GET'])]
    public function painel(ConsultarEquipeDoPontoUseCase $consultar): Response
    {
        $escritorio = $this->escritorioDoGestor();

        return $this->render('ponto/_abas_equipe.html.twig', [
            'painel' => $consultar->executar($this->eu(), $escritorio),
        ]);
    }

    /** Troca a escala de uma pessoa da equipe a partir de hoje (P56 a). Corpo: `{"escala": "<id do catálogo>"}`. */
    #[Route('/{id}/escala', name: 'ponto_equipe_escala', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function trocarEscala(int $id, Request $request, TrocarEscalaNaEquipeUseCase $trocar): JsonResponse
    {
        $escritorio = $this->escritorioDoGestor();   // quem pede antes do alvo: sem acesso, 403 com ou sem a pessoa
        $this->validarCsrfAjax($request);
        $dados = json_decode((string) $request->getContent(), true);
        if (!is_array($dados) || !is_string($dados['escala'] ?? null)) {
            return $this->json(['erro' => $this->tradutor->trans('ponto_servidor.equipe.escala_da_lista')], Response::HTTP_BAD_REQUEST);
        }

        try {
            $trocar->executar($id, $escritorio, $dados['escala']);
        } catch (ForaDaEquipeException $fora) {
            return $this->json(['erro' => $this->tradutor->trans($fora->chave())], Response::HTTP_NOT_FOUND);
        } catch (EscalaDesconhecidaException $recusada) {
            return $this->json(['erro' => $this->tradutor->trans($recusada->chave())], Response::HTTP_UNPROCESSABLE_ENTITY);
        } catch (ChoqueDeJornadaException $choque) {
            return $this->json(['erro' => $choque->texto()->traduzir($this->tradutor)], Response::HTTP_CONFLICT);
        } catch (UniqueConstraintViolationException) {
            return $this->json(['erro' => $this->tradutor->trans('ponto_servidor.comum.jornada_alterada')], Response::HTTP_CONFLICT);
        }

        return $this->json(['ok' => true]);
    }

    /** "Abonar selecionadas" (P57): `ids[]` do formulário; volta para a /ponto na aba Equipe com o resultado. */
    #[Route('/abonar', name: 'ponto_equipe_abonar', methods: ['POST'])]
    public function abonar(Request $request, AbonarJustificativasDaEquipeUseCase $abonar): Response
    {
        $escritorio = $this->escritorioDoGestor();
        if (!$this->isCsrfTokenValid('ponto_equipe_abonar', (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Token CSRF inválido.');
        }
        $ids = array_map('intval', array_filter($request->request->all('ids'), 'is_numeric'));

        $resultado = $abonar->executar($this->eu(), $escritorio, array_values($ids));
        // E8 (P80): as abonadas no aviso flutuante da /ponto; as recusas, uma por pessoa e dia, ficam no flash do topo.
        if ($resultado->abonadas > 0) {
            $this->addFlash(PontoController::FLASH_AVISO_FLUTUANTE, $this->tradutor->trans('ponto_servidor.equipe.abonadas', ['quantidade' => $resultado->abonadas, 'numero' => (string) $resultado->abonadas]));
        }
        // Cada recusa traduz pela chave (a pessoa, o dia e o motivo entram como parâmetro); sem as versões com chave, o português.
        $recusas = $resultado->recusasComChave !== []
            ? array_map(fn (TextoDoPonto $recusa): string => $recusa->traduzir($this->tradutor), $resultado->recusasComChave)
            : $resultado->recusas;
        foreach ($recusas as $recusa) {
            $this->addFlash('warning', $recusa);
        }
        if ($ids === []) {
            $this->addFlash('warning', $this->tradutor->trans('ponto_servidor.equipe.selecione_justificativa'));
        }

        return $this->redirectToRoute('ponto_index', ['aba' => 'equipe']);
    }

    /** O escritório da sessão, se quem pede gere a equipe dele. */
    private function escritorioDoGestor(): Tenant
    {
        $eu = $this->eu();
        $escritorio = $this->tenantContext->getCurrentTenant();
        if ($escritorio === null || !$this->permissoes->canAccessModule($eu, $escritorio, 'ponto') || !$this->gestao->veEquipe($eu, $escritorio)) {
            throw $this->createAccessDeniedException('Sem acesso à equipe do ponto.');
        }

        return $escritorio;
    }

    private function eu(): User
    {
        $eu = $this->getUser();
        if (!$eu instanceof User) {
            throw $this->createAccessDeniedException();
        }

        return $eu;
    }
}
