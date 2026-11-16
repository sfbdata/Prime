<?php

declare(strict_types=1);

namespace App\Voto\Controller;

use App\Entity\Auth\User;
use App\Voto\Enum\AvaliacaoDaOcorrencia;
use App\Voto\Exception\AssembleiaNaoEncontradaException;
use App\Voto\Exception\OperacaoInvalidaNaAssembleiaException;
use App\Voto\Exception\SemPapelNaAvaliacaoException;
use App\Voto\Service\AvisosDoVoto;
use App\Voto\Service\GuardaDaEquipeDoVoto;
use App\Voto\UseCase\DecidirAvaliacaoUseCase;
use App\Voto\UseCase\ObterAvaliacoesDaAssembleiaUseCase;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * A aba "Avaliações" do conselho (spec voto-avaliacoes-do-conselho-1611, seções 3 e 4; desenho 18, H273/H469): a lista e a
 * decisão ("Manter inaptidão" ou "Tornar apto(a)", com justificativa).
 *
 * A decisão é a ÚNICA escrita do nível Visualizar: a rota `voto_assembleia_avaliacao_decidir` está em
 * `BarreiraDaEquipeListener::ROTAS_DO_VISUALIZAR` e o papel é conferido no UseCase ({@see DecidirAvaliacaoUseCase}); quem a
 * guarda do módulo recusa continua recusado, antes de tudo ({@see GuardaDaEquipeDoVoto::exigir()}). Sem papel dá 403,
 * outro escritório dá 404.
 */
#[Route('/voto/assembleias/{id}/avaliacoes', requirements: ['id' => '\d+', 'ocorrencia' => '\d+'])]
final class AvaliacoesDoConselhoController extends AbstractController
{
    private const DECISOES = ['manter' => AvaliacaoDaOcorrencia::Mantida, 'alterar' => AvaliacaoDaOcorrencia::TornadaApta];

    public function __construct(
        private readonly GuardaDaEquipeDoVoto $guarda,
        private readonly AvisosDoVoto $avisos,
        private readonly TranslatorInterface $tradutor,
    ) {
    }

    #[Route('', name: 'voto_assembleia_avaliacoes', methods: ['GET'])]
    public function avaliacoes(int $id, ObterAvaliacoesDaAssembleiaUseCase $obter): Response
    {
        $tenant = $this->guarda->exigir();
        $usuario = $this->getUser();
        if (!$usuario instanceof User) {
            throw $this->createAccessDeniedException();
        }
        try {
            $tela = $obter->executar($tenant, $id, $usuario);
        } catch (AssembleiaNaoEncontradaException) {
            throw $this->createNotFoundException();
        } catch (SemPapelNaAvaliacaoException) {
            throw $this->createAccessDeniedException($this->tradutor->trans('voto.equipe.recusa.oculto'));
        }

        return $this->render('voto/assembleia/avaliacoes.html.twig', ['tela' => $tela, 'assembleia' => $tela->assembleia]);
    }

    #[Route('/{ocorrencia}/decidir', name: 'voto_assembleia_avaliacao_decidir', methods: ['POST'])]
    public function decidir(int $id, int $ocorrencia, Request $request, DecidirAvaliacaoUseCase $decidir): RedirectResponse
    {
        $tenant = $this->guarda->exigir();
        $usuario = $this->getUser();
        if (!$usuario instanceof User) {
            throw $this->createAccessDeniedException();
        }
        if (!$this->isCsrfTokenValid('voto_assembleia_' . $id, (string) $request->request->get('_token'))) {
            $this->avisos->erro($this->tradutor->trans('voto.assembleia.gestor.aviso.sessao_expirada'));

            return $this->redirectToRoute('voto_assembleia_avaliacoes', ['id' => $id]);
        }
        $decisao = self::DECISOES[(string) $request->request->get('decisao')] ?? null;
        try {
            $decidir->executar(
                $tenant,
                $id,
                $ocorrencia,
                $decisao ?? AvaliacaoDaOcorrencia::Pendente,
                (string) $request->request->get('justificativa', ''),
                $usuario,
                $this->guarda->nomeDeQuemOpera(),
            );
            $this->avisos->ok($this->tradutor->trans('voto.assembleia.gestor.avaliacoes.decisao_registrada'));
        } catch (OperacaoInvalidaNaAssembleiaException $e) {
            $this->avisos->erro($e->getMessage());
        } catch (AssembleiaNaoEncontradaException) {
            throw $this->createNotFoundException();
        } catch (SemPapelNaAvaliacaoException) {
            throw $this->createAccessDeniedException($this->tradutor->trans('voto.equipe.recusa.somente_leitura'));
        }

        return $this->redirectToRoute('voto_assembleia_avaliacoes', ['id' => $id]);
    }
}
