<?php

declare(strict_types=1);

namespace App\Voto\Controller;

use App\Voto\Exception\AssembleiaNaoEncontradaException;
use App\Voto\Service\AvisosDoVoto;
use App\Voto\Service\GuardaDaEquipeDoVoto;
use App\Voto\UseCase\DuplicarAssembleiaUseCase;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * "Duplicar votação" (desenho de 14 e 15/11): copia a assembleia para um novo rascunho e abre as pautas da cópia.
 * Mesma guarda e mesmo CSRF (`voto_assembleia_{id}`) dos demais controllers da assembleia; de outro escritório dá 404.
 */
#[Route('/voto/assembleias/{id}/duplicar', requirements: ['id' => '\d+'])]
final class DuplicarAssembleiaController extends AbstractController
{
    public function __construct(
        private readonly GuardaDaEquipeDoVoto $guarda,
        private readonly AvisosDoVoto $avisos,
        private readonly TranslatorInterface $tradutor,
    ) {
    }

    #[Route('', name: 'voto_assembleia_duplicar', methods: ['POST'])]
    public function duplicar(int $id, Request $request, DuplicarAssembleiaUseCase $duplicar): Response
    {
        $tenant = $this->guarda->exigir();
        if (!$this->isCsrfTokenValid('voto_assembleia_' . $id, (string) $request->request->get('_token'))) {
            $this->avisos->erro($this->tradutor->trans('voto.assembleia.gestor.aviso.sessao_expirada'));

            return $this->redirectToRoute('voto_index', ['aba' => 'ges', '_fragment' => 'assembleia-' . $id]);
        }

        try {
            $resultado = $duplicar->executar($tenant, $id, $this->guarda->nomeDeQuemOpera());
        } catch (AssembleiaNaoEncontradaException) {
            throw $this->createNotFoundException();
        }

        // Como o desenho: sem lista, o aviso não ganha complemento; com lista, avisa se os e-mails ficaram de fora.
        $aviso = $this->tradutor->trans('voto.assembleia.gestor.aviso.copia_criada');
        if ($resultado->unidadesCopiadas > 0) {
            $aviso .= ' ' . $this->tradutor->trans($resultado->emailsDeixados
                ? 'voto.assembleia.gestor.aviso.copia_com_lista_sem_emails'
                : 'voto.assembleia.gestor.aviso.copia_com_lista');
        }
        $this->avisos->ok($aviso);

        return $this->redirectToRoute('voto_assembleia_pautas', ['id' => $resultado->assembleiaId]);
    }
}
