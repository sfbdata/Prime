<?php

declare(strict_types=1);

namespace App\Voto\Controller;

use App\Entity\Tenant\Tenant;
use App\Voto\DTO\AssembleiaInput;
use App\Voto\DTO\AssembleiaNoPainel;
use App\Voto\Exception\AssembleiaNaoEncontradaException;
use App\Voto\Exception\OperacaoInvalidaNaAssembleiaException;
use App\Voto\Service\AvisosDoVoto;
use App\Voto\Service\GuardaDaEquipeDoVoto;
use App\Voto\UseCase\CriarAssembleiaUseCase;
use App\Voto\UseCase\EditarAssembleiaUseCase;
use App\Voto\UseCase\ObterAssembleiaUseCase;
use App\Voto\UseCase\ObterAtaDaAssembleiaUseCase;
use App\Voto\UseCase\ObterPainelDoVotoUseCase;
use App\Voto\UseCase\PublicarAssembleiaUseCase;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Gestão da assembleia de condomínio pela equipe logada (spec docs/specs/voto-assembleia-condominio.md, seção 1,
 * Gestor; insumo do desenho docs/specs/voto-assembleia-desenho.md, §2, §3 e §5).
 *
 * As três abas de /voto (Gestão eleitoral, Resultados e Acompanhar) embutem `painel()`, `resultados()` e
 * `acompanhar()` por `render(controller())`, sem mexer no VotoController. As telas próprias da assembleia (criar,
 * editar, ata e assistir) moram aqui; pautas e mesa em PautasDaAssembleiaController e MesaDaAssembleiaController;
 * unidades e links em UnidadesDaAssembleiaController.
 *
 * Acesso: {@see GuardaDaEquipeDoVoto} (a mesma regra do VotoController). Assembleia de outro escritório dá 404.
 */
#[Route('/voto/assembleias', requirements: ['id' => '\d+', 'pauta' => '\d+'])]
final class AssembleiaController extends AbstractController
{
    /** As ações que as abas de /voto embutem (função Twig `voto_assembleia_aba`). */
    public const ABAS_EMBUTIDAS = ['painel', 'resultados', 'acompanhar'];

    public function __construct(
        private readonly GuardaDaEquipeDoVoto $guarda,
        private readonly AvisosDoVoto $avisos,
        private readonly ObterPainelDoVotoUseCase $obterPainel,
        private readonly ObterAssembleiaUseCase $obterAssembleia,
        private readonly TranslatorInterface $tradutor,
    ) {
    }

    /** Aba "Gestão eleitoral" de /voto: as assembleias com as pautas e a mesa. Embutida, sem rota. */
    public function painel(): Response
    {
        $tenant = $this->guarda->exigir();

        return $this->render('voto/assembleia/_painel.html.twig', [
            'assembleias' => $this->obterPainel->executar($tenant),
            'agora' => new \DateTimeImmutable(),
        ]);
    }

    /** Aba "Resultados" de /voto: as pautas encerradas. Embutida, sem rota. */
    public function resultados(): Response
    {
        return $this->render('voto/assembleia/_resultados.html.twig', [
            'assembleias' => $this->obterPainel->executar($this->guarda->exigir()),
        ]);
    }

    /** Aba "Acompanhar" de /voto: as pautas publicadas, com "Assistir". Embutida, sem rota. */
    public function acompanhar(): Response
    {
        return $this->render('voto/assembleia/_acompanhar.html.twig', [
            'assembleias' => $this->obterPainel->executar($this->guarda->exigir()),
        ]);
    }

    #[Route('/nova', name: 'voto_assembleia_nova', methods: ['GET', 'POST'])]
    public function nova(Request $request, CriarAssembleiaUseCase $criar): Response
    {
        $tenant = $this->guarda->exigir();
        $input = self::input($request);
        if ($request->isMethod('POST') && $this->tokenValido($request, 'voto_assembleia_nova')) {
            try {
                $id = $criar->executar($tenant, $input, $this->guarda->nomeDeQuemOpera());

                return $this->depoisDeSalvar($request, $id);
            } catch (OperacaoInvalidaNaAssembleiaException $e) {
                $erro = $e->getMessage();
            }
        }

        return $this->render('voto/assembleia/form.html.twig', ['assembleia' => null, 'input' => $input, 'erro' => $erro ?? null]);
    }

    #[Route('/{id}/editar', name: 'voto_assembleia_editar', methods: ['GET', 'POST'])]
    public function editar(int $id, Request $request, EditarAssembleiaUseCase $editar): Response
    {
        $tenant = $this->guarda->exigir();
        $assembleia = $this->lida($tenant, $id);
        $input = $request->isMethod('POST') ? self::input($request) : new AssembleiaInput($assembleia->titulo, $assembleia->peso->value);
        if ($request->isMethod('POST') && $this->tokenValido($request, 'voto_assembleia_' . $id)) {
            try {
                $editar->executar($tenant, $id, $input);

                return $this->depoisDeSalvar($request, $id);
            } catch (OperacaoInvalidaNaAssembleiaException $e) {
                $erro = $e->getMessage();
            }
        }

        return $this->render('voto/assembleia/form.html.twig', ['assembleia' => $assembleia, 'input' => $input, 'erro' => $erro ?? null]);
    }

    #[Route('/{id}/publicar', name: 'voto_assembleia_publicar', methods: ['POST'])]
    public function publicar(int $id, Request $request, PublicarAssembleiaUseCase $publicar): Response
    {
        $tenant = $this->guarda->exigir();
        if ($this->tokenValido($request, 'voto_assembleia_' . $id)) {
            try {
                $publicar->executar($tenant, $id);
                $this->avisos->ok($this->tradutor->trans('voto.assembleia.gestor.aviso.publicada'));
            } catch (OperacaoInvalidaNaAssembleiaException $e) {
                $this->avisos->erro($e->getMessage());
            } catch (AssembleiaNaoEncontradaException) {
                throw $this->createNotFoundException();
            }
        }

        return $this->redirectToRoute('voto_index', ['aba' => 'ges', '_fragment' => 'assembleia-' . $id]);
    }

    #[Route('/{id}/ata', name: 'voto_assembleia_ata', methods: ['GET'])]
    public function ata(int $id, ObterAtaDaAssembleiaUseCase $obterAta): Response
    {
        $tenant = $this->guarda->exigir();
        try {
            $ata = $obterAta->executar($tenant, $id, $this->guarda->nomeDeQuemOpera());
        } catch (AssembleiaNaoEncontradaException) {
            throw $this->createNotFoundException();
        }

        return $this->render('voto/assembleia/ata.html.twig', ['ata' => $ata]);
    }

    /** "Assistir": página própria de uma pauta para projetar, que se atualiza sozinha. Nunca mostra parcial de votos. */
    #[Route('/{id}/pautas/{pauta}/assistir', name: 'voto_assembleia_assistir', methods: ['GET'])]
    public function assistir(int $id, int $pauta): Response
    {
        $assembleia = $this->lida($this->guarda->exigir(), $id);
        foreach ($assembleia->pautas as $lida) {
            if ($lida->id === $pauta && $assembleia->publicada()) {
                return $this->render('voto/assembleia/assistir.html.twig', ['assembleia' => $assembleia, 'pauta' => $lida, 'agora' => new \DateTimeImmutable()]);
            }
        }

        throw $this->createNotFoundException();
    }

    private function lida(Tenant $tenant, int $id): AssembleiaNoPainel
    {
        try {
            return $this->obterAssembleia->executar($tenant, $id);
        } catch (AssembleiaNaoEncontradaException) {
            throw $this->createNotFoundException();
        }
    }

    /** "Salvar e sair" volta ao painel; "Continuar" segue para as pautas. */
    private function depoisDeSalvar(Request $request, int $id): Response
    {
        if ('sair' === $request->request->get('depois')) {
            return $this->redirectToRoute('voto_index', ['aba' => 'ges', '_fragment' => 'assembleia-' . $id]);
        }

        return $this->redirectToRoute('voto_assembleia_pautas', ['id' => $id]);
    }

    private function tokenValido(Request $request, string $id): bool
    {
        if ($this->isCsrfTokenValid($id, (string) $request->request->get('_token'))) {
            return true;
        }
        $this->avisos->erro($this->tradutor->trans('voto.assembleia.gestor.aviso.sessao_expirada'));

        return false;
    }

    private static function input(Request $request): AssembleiaInput
    {
        return new AssembleiaInput(
            (string) $request->request->get('titulo', ''),
            (string) $request->request->get('peso', 'fracao'),
        );
    }
}
