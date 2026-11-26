<?php

declare(strict_types=1);

namespace App\Voto\Controller;

use App\Entity\Auth\User;
use App\Voto\Exception\AssembleiaNaoEncontradaException;
use App\Voto\Exception\SemPapelNaAvaliacaoException;
use App\Voto\Service\GuardaDaEquipeDoVoto;
use App\Voto\UseCase\ListarOcorrenciasDaVotacaoUseCase;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * A sub-aba "Ocorrências" da gestão da assembleia: o que os eleitores registraram pelo Portal do Eleitor. Só leitura.
 * Mesma guarda dos vizinhos: sem módulo 403; outro escritório 404; quem não gere a assembleia (nível abaixo de Editar) 403.
 */
#[Route('/voto/assembleias/{id}/ocorrencias', requirements: ['id' => '\d+'])]
final class OcorrenciasDaAssembleiaController extends AbstractController
{
    public function __construct(
        private readonly GuardaDaEquipeDoVoto $guarda,
        private readonly TranslatorInterface $tradutor,
    ) {
    }

    #[Route('', name: 'voto_assembleia_ocorrencias', methods: ['GET'])]
    public function lista(int $id, ListarOcorrenciasDaVotacaoUseCase $listar): Response
    {
        $tenant = $this->guarda->exigir();
        $usuario = $this->getUser();
        if (!$usuario instanceof User) {
            throw $this->createAccessDeniedException();
        }
        try {
            $tela = $listar->executar($tenant, $id, $usuario);
        } catch (AssembleiaNaoEncontradaException) {
            throw $this->createNotFoundException();
        } catch (SemPapelNaAvaliacaoException) {
            throw $this->createAccessDeniedException($this->tradutor->trans('voto.equipe.recusa.oculto'));
        }

        return $this->render('voto/assembleia/ocorrencias.html.twig', ['assembleia' => $tela['assembleia'], 'ocorrencias' => $tela['ocorrencias']]);
    }
}
