<?php

declare(strict_types=1);

namespace App\Profile\Controller;

use App\Auth\Enum\TipoInscricaoOab;
use App\Entity\Auth\User;
use App\Profile\DTO\InscricaoOabInput;
use App\Profile\UseCase\RemoverInscricaoOabUseCase;
use App\Profile\UseCase\SalvarInscricaoOabUseCase;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Inscrições na OAB da própria pessoa, pelo modal "Inscrição na OAB" do Meu Perfil (desenho 07, VS 18.10.26;
 * spec docs/specs/meu-perfil-oab-inscricoes.md §4). As mensagens são as do desenho. Recusa ao salvar volta para o
 * modal aberto, com o erro dentro dele e os valores digitados (desenho: `erroTem`/`erro`), pela sessão
 * ({@see self::ERRO_NA_SESSAO}), porque a base mostra e consome todo flash antes do corpo da página.
 */
#[Route('/perfil/oab', name: 'app_profile_oab')]
final class InscricoesOabDoPerfilController extends AbstractController
{
    public const ERRO_NA_SESSAO = 'perfil_oab_erro';

    public function __construct(
        private readonly SalvarInscricaoOabUseCase $salvar,
        private readonly RemoverInscricaoOabUseCase $remover,
    ) {
    }

    #[Route('', name: '', methods: ['POST'])]
    public function salvar(Request $request): Response
    {
        if (!$this->isCsrfTokenValid('profile_oab', (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Token inválido.');
        }
        /** @var User $user */
        $user  = $this->getUser();
        $id    = (string) $request->request->get('id');
        $input = new InscricaoOabInput(
            $id === '' ? null : (int) $id,
            (string) $request->request->get('numero'),
            (string) $request->request->get('uf'),
            TipoInscricaoOab::tryFrom((string) $request->request->get('tipo')) ?? TipoInscricaoOab::Suplementar,
        );

        try {
            $pendente = $this->salvar->executar($user, $input);
            $this->addFlash('success', $pendente ? 'Inscrição salva. Ela fica Pendente até o Master validar.' : 'Inscrição atualizada');
        } catch (\DomainException $e) {
            $request->getSession()->set(self::ERRO_NA_SESSAO, [
                'erro' => $e->getMessage(), 'id' => $id, 'numero' => $input->numero, 'uf' => $input->uf, 'tipo' => $input->tipo->value,
            ]);
        }

        return $this->redirectToRoute('app_profile');
    }

    #[Route('/{id}/remover', name: '_remover', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function remover(int $id, Request $request): Response
    {
        if (!$this->isCsrfTokenValid('profile_oab_remover', (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Token inválido.');
        }
        /** @var User $user */
        $user = $this->getUser();

        try {
            $this->remover->executar($user, $id);
            $this->addFlash('success', 'Inscrição removida');
        } catch (\DomainException $e) {
            $this->addFlash('error', $e->getMessage());
        }

        return $this->redirectToRoute('app_profile');
    }
}
