<?php

declare(strict_types=1);

namespace App\Comercial\Controller;

use App\Comercial\Envio\CotaDeEnvio;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * O "Dispensar" do aviso da cota de envio de e-mail (spec comercial-e-emails.md §3.6). Só o Master, só POST com CSRF.
 * Guarda na SESSÃO a chave do episódio (estado e, nas pausadas, o dia da volta): o aviso some até o estado mudar.
 * Volta para a página de onde veio, só se for um caminho local (nada de redirecionamento aberto); senão, para o início.
 */
#[IsGranted('ROLE_SUPER_ADMIN')]
final class AvisoDaCotaDeEnvioController extends AbstractController
{
    public const CSRF = 'dispensar_aviso_da_cota';
    public const SESSAO = 'comercial.aviso_da_cota.dispensado';

    #[Route('/master/comercial/cota/dispensar', name: 'master_comercial_cota_dispensar', methods: ['POST'])]
    public function dispensar(Request $request, CotaDeEnvio $cota): Response
    {
        if (!$this->isCsrfTokenValid(self::CSRF, (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Token CSRF inválido.');
        }

        $chave = $cota->situacao()->chaveDoEpisodio();
        if ($chave !== null) {
            $request->getSession()->set(self::SESSAO, $chave);
        }

        return $this->redirect($this->destinoSeguro((string) $request->request->get('voltar')));
    }

    /** Só caminho local: começa com uma barra, sem "//", sem barra invertida nem caractere de controle. */
    private function destinoSeguro(string $voltar): string
    {
        foreach ([$voltar, rawurldecode($voltar)] as $forma) {
            if (!str_starts_with($forma, '/') || str_starts_with($forma, '//')
                || str_contains($forma, '\\') || preg_match('/[\x00-\x1F\x7F]/', $forma) === 1) {
                return $this->generateUrl('homepage');
            }
        }

        return $voltar;
    }
}
