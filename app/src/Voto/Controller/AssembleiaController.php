<?php

declare(strict_types=1);

namespace App\Voto\Controller;

use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Voto\DTO\AssembleiaInput;
use App\Voto\DTO\AssembleiaNoPainel;
use App\Voto\DTO\FiltroDoPainel;
use App\Voto\Enum\CenarioDaAssembleia;
use App\Voto\Exception\AssembleiaNaoEncontradaException;
use App\Voto\Exception\OperacaoInvalidaNaAssembleiaException;
use App\Voto\Exception\SemPermissaoParaVerAsUnidadesException;
use App\Voto\Service\AcessoAAssembleia;
use App\Voto\Service\AvisosDoVoto;
use App\Voto\Service\GuardaDaEquipeDoVoto;
use App\Voto\Service\PainelPorEntidade;
use App\Voto\UseCase\ConferirPublicacaoDaAssembleiaUseCase;
use App\Voto\UseCase\ObterEquipeDaAssembleiaUseCase;
use App\Voto\UseCase\CriarAssembleiaUseCase;
use App\Voto\UseCase\EditarAssembleiaUseCase;
use App\Voto\UseCase\ListarVotacoesParaVotarUseCase;
use App\Voto\UseCase\MontarPainelDeUnidadesUseCase;
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
    public const ABAS_EMBUTIDAS = ['painel', 'resultados', 'acompanhar', 'votar'];

    public function __construct(
        private readonly GuardaDaEquipeDoVoto $guarda,
        private readonly AvisosDoVoto $avisos,
        private readonly ObterPainelDoVotoUseCase $obterPainel,
        private readonly ObterAssembleiaUseCase $obterAssembleia,
        private readonly TranslatorInterface $tradutor,
        private readonly PainelPorEntidade $painelPorEntidade,
        private readonly ListarVotacoesParaVotarUseCase $listarVotacoes,
    ) {
    }

    /**
     * Aba "Gestão eleitoral" de /voto: as assembleias agrupadas por entidade, com as pautas e a mesa. Embutida, sem
     * rota. As arquivadas e as pautas retiradas ficam fora da lista até o parâmetro `arquivadas=1` ("Mostrar
     * arquivadas", desenho 18). Filtros por URL (sem JavaScript): `q` (busca), `entidade` (chave do grupo) e `situacao`
     * (and, ras, con); `kpi` (abe ou ced) abre o painel de lista do KPI. Os KPIs olham todas as assembleias, sem filtro.
     */
    public function painel(Request $request): Response
    {
        $tenant = $this->guarda->exigir();
        $todas = $this->obterPainel->executar($tenant, null, $this->pessoa());
        $arquivadas = $request->query->getBoolean('arquivadas');
        $agora = new \DateTimeImmutable();
        $totalArquivadas = 0;
        foreach ($todas as $assembleia) {
            $totalArquivadas += $assembleia->arquivada() ? 1 : \count($assembleia->pautasRetiradas());
        }

        $filtro = FiltroDoPainel::de((string) $request->query->get('q', ''), (string) $request->query->get('entidade', ''), (string) $request->query->get('situacao', ''));
        $kpi = (string) $request->query->get('kpi', '');

        return $this->render('voto/assembleia/_painel.html.twig', [
            'painel' => $this->painelPorEntidade->agrupar($arquivadas ? $todas : self::semArquivadas($todas), $filtro),
            'kpis' => $this->painelPorEntidade->kpis($todas, $agora),
            'kpi' => \in_array($kpi, ['abe', 'ced'], true) ? $kpi : '',
            'filtro' => $filtro,
            'agora' => $agora,
            'arquivadas' => $arquivadas,
            'totalArquivadas' => $totalArquivadas,
        ]);
    }

    /** Aba "Resultados" de /voto: as pautas encerradas (arquivada e retirada saem). Embutida, sem rota. */
    public function resultados(): Response
    {
        return $this->render('voto/assembleia/_resultados.html.twig', [
            'assembleias' => self::semArquivadas($this->obterPainel->executar($this->guarda->exigir(), null, $this->pessoa())),
        ]);
    }

    /** Aba "Acompanhar" de /voto: as pautas publicadas, com "Assistir" (arquivada e retirada saem). Embutida, sem rota. */
    public function acompanhar(): Response
    {
        return $this->render('voto/assembleia/_acompanhar.html.twig', [
            'assembleias' => self::semArquivadas($this->obterPainel->executar($this->guarda->exigir(), null, $this->pessoa())),
        ]);
    }

    /**
     * Aba "Votar" de /voto (desenho 18): uma votação por cartão, com chip, frase de situação e "Votar", que abre a tela
     * do eleitor (a mesma do link e do QR Code do evento). Embutida, sem rota.
     */
    public function votar(): Response
    {
        return $this->render('voto/assembleia/_votar.html.twig', [
            'votacoes' => $this->listarVotacoes->executar($this->guarda->exigir(), null, $this->pessoa()),
        ]);
    }

    /** Quem está logada (a guarda já garantiu): as listas escondem o que o teto da equipe deixa `Oculto` para ela. */
    private function pessoa(): User
    {
        $usuario = $this->getUser();

        return $usuario instanceof User ? $usuario : throw $this->createAccessDeniedException();
    }

    /**
     * @param list<AssembleiaNoPainel> $assembleias
     *
     * @return list<AssembleiaNoPainel>
     */
    private static function semArquivadas(array $assembleias): array
    {
        return array_values(array_filter($assembleias, static fn (AssembleiaNoPainel $a): bool => !$a->arquivada()));
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

        return $this->render('voto/assembleia/form.html.twig', self::contextoDoForm(null, $input, $erro ?? null, self::passoDoForm($request)));
    }

    #[Route('/{id}/editar', name: 'voto_assembleia_editar', methods: ['GET', 'POST'])]
    public function editar(int $id, Request $request, EditarAssembleiaUseCase $editar): Response
    {
        $tenant = $this->guarda->exigir();
        $input = $request->isMethod('POST') ? self::input($request) : null;
        if ($input !== null && $this->tokenValido($request, 'voto_assembleia_' . $id)) {
            try {
                $editar->executar($tenant, $id, $input);

                return $this->depoisDeSalvar($request, $id);
            } catch (AssembleiaNaoEncontradaException) {
                throw $this->createNotFoundException();
            } catch (OperacaoInvalidaNaAssembleiaException $e) {
                $erro = $e->getMessage();
            }
        }

        $assembleia = $this->lida($tenant, $id);
        $input ??= new AssembleiaInput($assembleia->titulo, $assembleia->peso->value, $assembleia->entidade ?? '', $assembleia->cnpj ?? '', $assembleia->cenario->value, $assembleia->simulacao, $assembleia->rotuloUnidade ?? '', $assembleia->rotuloBloco ?? '', $assembleia->linkSalaVirtual ?? '');

        return $this->render('voto/assembleia/form.html.twig', self::contextoDoForm($assembleia, $input, $erro ?? null, self::passoDoForm($request)));
    }

    /**
     * Passos 2 (Regras e prazo), 3 (Acesso) e 6 (Equipe) do assistente de 7 passos (desenho 18). O 2 reabre o formulário
     * da assembleia (grava por `editar`); o 3 e o 6 só informam. Os passos 1, 4, 5 e 7 são `editar`, `pautas`, `unidades` e a
     * conferência. Publicada não tem assistente: vai para a mesa.
     */
    #[Route('/{id}/passo/{passo}', name: 'voto_assembleia_passo', requirements: ['passo' => '[236]'], methods: ['GET'])]
    public function passo(int $id, int $passo, ObterEquipeDaAssembleiaUseCase $obterEquipe, AcessoAAssembleia $acesso): Response
    {
        $tenant = $this->guarda->exigir();
        $assembleia = $this->lida($tenant, $id);
        if (!$assembleia->podeAlterarPautas) {
            return $this->redirectToRoute('voto_assembleia_pautas', ['id' => $id]);
        }
        if (2 === $passo) {
            $input = new AssembleiaInput($assembleia->titulo, $assembleia->peso->value, $assembleia->entidade ?? '', $assembleia->cnpj ?? '', $assembleia->cenario->value, $assembleia->simulacao, $assembleia->rotuloUnidade ?? '', $assembleia->rotuloBloco ?? '', $assembleia->linkSalaVirtual ?? '');

            return $this->render('voto/assembleia/form.html.twig', self::contextoDoForm($assembleia, $input, null, 2));
        }

        return $this->render('voto/assembleia/passo.html.twig', [
            'assembleia' => $assembleia,
            'passo' => $passo,
            'equipe' => 6 === $passo ? $obterEquipe->executar($tenant, $id) : [],
            'administracao' => 6 === $passo && $acesso->ehAdministracao($this->pessoa(), $tenant),
        ]);
    }

    #[Route('/{id}/publicar', name: 'voto_assembleia_conferir_publicacao', methods: ['GET'])]
    public function conferirPublicacao(int $id, ConferirPublicacaoDaAssembleiaUseCase $conferir): Response
    {
        $tenant = $this->guarda->exigir();
        try {
            $conferencia = $conferir->executar($tenant, $id);
        } catch (AssembleiaNaoEncontradaException) {
            throw $this->createNotFoundException();
        }

        if ($conferencia->assembleia->publicada()) {
            return $this->redirectToRoute('voto_assembleia_pautas', ['id' => $id]);
        }

        return $this->render('voto/assembleia/conferencia.html.twig', ['assembleia' => $conferencia->assembleia, 'conferencia' => $conferencia]);
    }

    #[Route('/{id}/publicar', name: 'voto_assembleia_publicar', methods: ['POST'])]
    public function publicar(int $id, Request $request, PublicarAssembleiaUseCase $publicar): Response
    {
        $tenant = $this->guarda->exigir();
        if ($this->tokenValido($request, 'voto_assembleia_' . $id)) {
            try {
                $publicar->executar($tenant, $id, conferencia: $request->request->getString('conferencia'));
                $this->avisos->ok($this->tradutor->trans('voto.assembleia.gestor.aviso.publicada'));

                return $this->redirectToRoute('voto_assembleia_pautas', ['id' => $id, 'publicada' => 1]);
            } catch (OperacaoInvalidaNaAssembleiaException $e) {
                $this->avisos->erro($e->getMessage());
            } catch (AssembleiaNaoEncontradaException) {
                throw $this->createNotFoundException();
            }
        }

        return $this->redirectToRoute('voto_assembleia_conferir_publicacao', ['id' => $id]);
    }

    #[Route('/{id}/ata', name: 'voto_assembleia_ata', methods: ['GET'])]
    public function ata(int $id, ObterAtaDaAssembleiaUseCase $obterAta): Response
    {
        $tenant = $this->guarda->exigir();
        try {
            $ata = $obterAta->executar($tenant, $id, $this->guarda->nomeDeQuemOpera(), ator: $this->getUser() instanceof User ? $this->getUser() : null);
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
            if ($lida->id === $pauta && $assembleia->publicada() && !$assembleia->arquivada() && !$lida->retirada()) {
                return $this->render('voto/assembleia/assistir.html.twig', ['assembleia' => $assembleia, 'pauta' => $lida, 'agora' => new \DateTimeImmutable()]);
            }
        }

        throw $this->createNotFoundException();
    }

    /**
     * "Unidades": página própria com todas as unidades da pauta, que mudam de cor quando votam. Só quem vê a relação de
     * votantes (403 para os demais); cenário sem lista, rascunho, arquivada e pauta retirada dão 404. Só a identificação.
     */
    #[Route('/{id}/pautas/{pauta}/unidades', name: 'voto_assembleia_unidades_ao_vivo', methods: ['GET'])]
    public function unidadesAoVivo(int $id, int $pauta, MontarPainelDeUnidadesUseCase $montar): Response
    {
        try {
            $painel = $montar->executar($this->guarda->exigir(), $id, $pauta, $this->pessoa());
        } catch (AssembleiaNaoEncontradaException) {
            throw $this->createNotFoundException();
        } catch (SemPermissaoParaVerAsUnidadesException) {
            throw $this->createAccessDeniedException();
        }

        return $this->render('voto/assembleia/unidades_ao_vivo.html.twig', ['painel' => $painel, 'agora' => new \DateTimeImmutable()]);
    }

    private function lida(Tenant $tenant, int $id): AssembleiaNoPainel
    {
        try {
            return $this->obterAssembleia->executar($tenant, $id);
        } catch (AssembleiaNaoEncontradaException) {
            throw $this->createNotFoundException();
        }
    }

    /**
     * "Salvar e sair" volta ao painel; o trilho e "Continuar"/"Voltar" levam ao passo (`passo-N`); sem valor (formulário
     * antigo guardado) segue para as pautas, como antes do assistente.
     */
    private function depoisDeSalvar(Request $request, int $id): Response
    {
        $depois = (string) $request->request->get('depois', '');
        if ('sair' === $depois) {
            return $this->redirectToRoute('voto_index', ['aba' => 'ges', '_fragment' => 'assembleia-' . $id]);
        }
        $passo = 1 === preg_match('/^passo-([1-7])$/', $depois, $achou) ? (int) $achou[1] : 4;

        return match ($passo) {
            1 => $this->redirectToRoute('voto_assembleia_editar', ['id' => $id]),
            4 => $this->redirectToRoute('voto_assembleia_pautas', ['id' => $id]),
            5 => $this->redirectToRoute('voto_assembleia_unidades', ['id' => $id]),
            7 => $this->redirectToRoute('voto_assembleia_conferir_publicacao', ['id' => $id]),
            default => $this->redirectToRoute('voto_assembleia_passo', ['id' => $id, 'passo' => $passo]),
        };
    }

    private function tokenValido(Request $request, string $id): bool
    {
        if ($this->isCsrfTokenValid($id, (string) $request->request->get('_token'))) {
            return true;
        }
        $this->avisos->erro($this->tradutor->trans('voto.assembleia.gestor.aviso.sessao_expirada'));

        return false;
    }

    /**
     * Contexto do formulário dos passos 1 e 2: os cenários e o escolhido (valor desconhecido volta ao padrão, condomínio).
     *
     * @return array<string, mixed>
     */
    private static function contextoDoForm(?AssembleiaNoPainel $assembleia, AssembleiaInput $input, ?string $erro, int $passo): array
    {
        return [
            'passo' => $passo,
            'assembleia' => $assembleia,
            'input' => $input,
            'erro' => $erro,
            'cenarios' => CenarioDaAssembleia::cases(),
            'cenario' => CenarioDaAssembleia::tryFrom($input->cenario) ?? CenarioDaAssembleia::Condominio,
        ];
    }

    /** O passo do formulário que enviou (campo `passo`): 2 ou, em qualquer outro caso, 1. */
    private static function passoDoForm(Request $request): int
    {
        return 2 === (int) $request->request->get('passo', 1) ? 2 : 1;
    }

    private static function input(Request $request): AssembleiaInput
    {
        return new AssembleiaInput(
            (string) $request->request->get('titulo', ''),
            (string) $request->request->get('peso', 'fracao'),
            (string) $request->request->get('entidade', ''),
            (string) $request->request->get('cnpj', ''),
            (string) $request->request->get('cenario', CenarioDaAssembleia::Condominio->value),
            '1' === (string) $request->request->get('simulacao', ''),
            trim((string) $request->request->get('rotulo_unidade', '')),
            trim((string) $request->request->get('rotulo_bloco', '')),
            trim((string) $request->request->get('link_sala_virtual', '')),
        );
    }
}
