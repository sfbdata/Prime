<?php

declare(strict_types=1);

namespace App\Voto\Controller;

use App\Entity\Auth\User;
use App\Service\PermissionChecker;
use App\Service\Tenant\TenantContext;
use App\Voto\Enum\NivelNaAssembleia;
use App\Voto\Repository\AssembleiaRepository;
use App\Voto\Service\AcessoAAssembleia;
use App\Voto\Service\LiberacaoDoVotoNoEscritorio;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * BlueJus Voto, tela da equipe logada (desenho docs/design/redesign-08-11-26/18 - Voto - VS 08.11.26.dc.html,
 * spec docs/specs/bluejus-voto.md). Fase A1: a tela dentro da moldura do sistema, com a faixa de demonstração e as
 * quatro abas do desenho; o conteúdo de cada aba chega nos PRs seguintes (seção 6 da spec).
 *
 * Três travas: a chave `voto` do catálogo do Master (o guarda de rota devolve "em preparação" enquanto estiver
 * desligada, e só o Master passa), a permissão `modules.voto.view` e, desde a A1b, a liberação do escritório pelo
 * Master (aba "Voto" do painel; {@see LiberacaoDoVotoNoEscritorio}, em que o Master também passa sempre).
 */
#[Route('/voto')]
final class VotoController extends AbstractController
{
    private const MODULO = 'voto';

    /** Abas do desenho, na ordem da barra: Gestão eleitoral (padrão), Acompanhar, Votar, Resultados. */
    public const ABAS = ['ges', 'aco', 'ele', 'tra'];

    public function __construct(
        private readonly TenantContext $tenantContext,
        private readonly PermissionChecker $permissionChecker,
        private readonly LiberacaoDoVotoNoEscritorio $liberacao,
        private readonly TranslatorInterface $tradutor,
        private readonly AcessoAAssembleia $acesso,
        private readonly AssembleiaRepository $assembleias,
    ) {
    }

    #[Route('', name: 'voto_index', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $this->assertAcesso();
        // Sem ?aba (ou aba desconhecida): quem gere abre em Gestão eleitoral; quem só vota abre em Votar.
        $padrao = $this->podeGerir() ? 'ges' : 'ele';
        $aba = (string) $request->query->get('aba', $padrao);

        return $this->render('voto/index.html.twig', [
            'aba' => in_array($aba, self::ABAS, true) ? $aba : $padrao,
        ]);
    }

    /**
     * Quem gere: a Administração, o escritório sem nenhuma assembleia ainda, ou quem edita pelo menos uma assembleia
     * (comissão ou módulo sem equipe própria). Quem só tem Visualizar ou Oculto em todas (auditoria, fiscal, conselho,
     * fora da equipe) só vota: abre em Votar.
     */
    private function podeGerir(): bool
    {
        $usuario = $this->getUser();
        $tenant = $this->tenantContext->getCurrentTenant();
        if (!$usuario instanceof User || null === $tenant) {
            return false;
        }
        $todas = $this->assembleias->listarDoEscritorio($tenant);
        if ([] === $todas || $this->acesso->ehAdministracao($usuario, $tenant)) {
            return true;
        }

        return \in_array(NivelNaAssembleia::Editar, $this->acesso->niveisEmLote($usuario, $todas), true);
    }

    private function assertAcesso(): void
    {
        $usuario = $this->getUser();
        $tenant = $this->tenantContext->getCurrentTenant();
        if (!$usuario instanceof User || $tenant === null || !$this->permissionChecker->canAccessModule($usuario, $tenant, self::MODULO)) {
            throw $this->createAccessDeniedException();
        }
        if (!$this->liberacao->liberadoParaOUsuarioAtual()) {
            throw $this->createAccessDeniedException($this->tradutor->trans('voto.recusa.nao_liberado'));
        }
    }
}
