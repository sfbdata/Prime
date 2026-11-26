<?php

declare(strict_types=1);

namespace App\Voto\Controller;

use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Voto\DTO\MembroDaEquipeInput;
use App\Voto\Enum\CenarioDaAssembleia;
use App\Voto\Enum\PapelNaEquipe;
use App\Voto\Exception\AssembleiaNaoEncontradaException;
use App\Voto\Exception\EquipeSoDaAdministracaoException;
use App\Voto\Exception\OperacaoInvalidaNaAssembleiaException;
use App\Voto\Service\AvisosDoVoto;
use App\Voto\Service\GuardaDaEquipeDoVoto;
use App\Voto\UseCase\AlterarMembroDaEquipeUseCase;
use App\Voto\UseCase\AplicarCargosDaCategoriaUseCase;
use App\Voto\UseCase\DesignarMembroDaEquipeUseCase;
use App\Voto\UseCase\ListarEquipesDasAssembleiasUseCase;
use App\Voto\UseCase\RemoverMembroDaEquipeUseCase;
use App\Voto\UseCase\UsarEquipePadraoUseCase;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Tela "Perfis por assembleia ou votação" (desenho 18, H247 e H463-464; spec docs/specs/voto-equipe-e-master-1611.md,
 * seção 1.3): a equipe própria de cada assembleia. Só a Administração (Master e primário ativo) entra e edita; para
 * qualquer outra pessoa é 403 ("Seu perfil não tem permissão para esta ação."). Mesma guarda do módulo dos vizinhos
 * ({@see GuardaDaEquipeDoVoto}). O grupo aberto vai em `grupo` (id da assembleia ou `padrao`), sem JavaScript.
 *
 * Assembleia de outro escritório dá 404. CSRF `voto_equipe`.
 */
#[Route('/voto/equipe', requirements: ['id' => '\d+', 'membro' => '\d+'])]
final class EquipeDoVotoController extends AbstractController
{
    private const CSRF = 'voto_equipe';

    public function __construct(
        private readonly GuardaDaEquipeDoVoto $guarda,
        private readonly AvisosDoVoto $avisos,
        private readonly TranslatorInterface $tradutor,
    ) {
    }

    #[Route('', name: 'voto_equipe_index', methods: ['GET'])]
    public function index(Request $request, ListarEquipesDasAssembleiasUseCase $listar): Response
    {
        $tenant = $this->guarda->exigir();
        try {
            $grupos = $listar->executar($this->pessoa(), $tenant);
        } catch (EquipeSoDaAdministracaoException) {
            throw $this->createAccessDeniedException($this->tradutor->trans('voto.equipe.recusa.so_administracao'));
        }

        return $this->render('voto/equipe/index.html.twig', [
            'grupos' => $grupos,
            'grupo' => (string) $request->query->get('grupo', ''),
            'editar' => $request->query->getInt('editar'),
            'categorias' => CenarioDaAssembleia::cases(),
            'papeis' => PapelNaEquipe::cases(),
        ]);
    }

    #[Route('/{id}/designar', name: 'voto_equipe_designar', methods: ['POST'])]
    public function designar(int $id, Request $request, DesignarMembroDaEquipeUseCase $designar): RedirectResponse
    {
        $input = self::input($request);

        return $this->executar($request, $id, 'voto.equipe.aviso.designado', fn (Tenant $t) => $designar->executar($this->pessoa(), $t, $id, $input));
    }

    #[Route('/{id}/{membro}/alterar', name: 'voto_equipe_alterar', methods: ['POST'])]
    public function alterar(int $id, int $membro, Request $request, AlterarMembroDaEquipeUseCase $alterar): RedirectResponse
    {
        $input = self::input($request);

        return $this->executar($request, $id, 'voto.equipe.aviso.alterado', fn (Tenant $t) => $alterar->executar($this->pessoa(), $t, $id, $membro, $input));
    }

    #[Route('/{id}/{membro}/remover', name: 'voto_equipe_remover', methods: ['POST'])]
    public function remover(int $id, int $membro, Request $request, RemoverMembroDaEquipeUseCase $remover): RedirectResponse
    {
        return $this->executar($request, $id, 'voto.equipe.aviso.removido', fn (Tenant $t) => $remover->executar($this->pessoa(), $t, $id, $membro));
    }

    #[Route('/{id}/categoria', name: 'voto_equipe_categoria', methods: ['POST'])]
    public function aplicarCategoria(int $id, Request $request, AplicarCargosDaCategoriaUseCase $aplicar): RedirectResponse
    {
        $categoria = CenarioDaAssembleia::tryFrom((string) $request->request->get('categoria', '')) ?? CenarioDaAssembleia::Livre;

        return $this->executar($request, $id, 'voto.equipe.aviso.cargos_aplicados', fn (Tenant $t) => $aplicar->executar($this->pessoa(), $t, $id, $categoria));
    }

    /** "Usar a equipe padrão" (o passo 4 do assistente chega aqui): apaga a equipe própria da assembleia em rascunho. */
    #[Route('/{id}/padrao', name: 'voto_equipe_padrao', methods: ['POST'])]
    public function usarPadrao(int $id, Request $request, UsarEquipePadraoUseCase $usarPadrao): RedirectResponse
    {
        return $this->executar($request, $id, 'voto.equipe.aviso.padrao', fn (Tenant $t) => $usarPadrao->executar($this->pessoa(), $t, $id));
    }

    /** @param callable(Tenant): mixed $acao */
    private function executar(Request $request, int $id, string $avisoOk, callable $acao): RedirectResponse
    {
        $tenant = $this->guarda->exigir();
        if (!$this->isCsrfTokenValid(self::CSRF, (string) $request->request->get('_token'))) {
            $this->avisos->erro($this->tradutor->trans('voto.assembleia.gestor.aviso.sessao_expirada'));

            return $this->voltar($id, $request);
        }
        try {
            $acao($tenant);
            $this->avisos->ok($this->tradutor->trans($avisoOk));
        } catch (EquipeSoDaAdministracaoException) {
            throw $this->createAccessDeniedException($this->tradutor->trans('voto.equipe.recusa.so_administracao'));
        } catch (AssembleiaNaoEncontradaException) {
            throw $this->createNotFoundException();
        } catch (OperacaoInvalidaNaAssembleiaException $e) {
            $this->avisos->erro($e->getMessage());
        }

        return $this->voltar($id, $request);
    }

    /** Volta à tela Perfis; se a ação veio do passo 4 do assistente (`depois=passo-4`), volta ao passo 4. */
    private function voltar(int $id, Request $request): RedirectResponse
    {
        if ('passo-4' === $request->request->get('depois')) {
            return $this->redirectToRoute('voto_assembleia_passo', ['id' => $id, 'passo' => 4]);
        }

        return $this->redirectToRoute('voto_equipe_index', ['grupo' => $id, '_fragment' => 'equipe-' . $id]);
    }

    private function pessoa(): User
    {
        $usuario = $this->getUser();

        return $usuario instanceof User ? $usuario : throw $this->createAccessDeniedException();
    }

    private static function input(Request $request): MembroDaEquipeInput
    {
        return new MembroDaEquipeInput(
            (string) $request->request->get('nome', ''),
            (string) $request->request->get('email', ''),
            (string) $request->request->get('cargo', ''),
            (string) $request->request->get('papel', ''),
        );
    }
}
