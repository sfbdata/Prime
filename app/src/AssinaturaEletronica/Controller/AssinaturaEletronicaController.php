<?php

declare(strict_types=1);

namespace App\AssinaturaEletronica\Controller;

use App\AssinaturaEletronica\Service\AcessoAAssinatura;
use App\AssinaturaEletronica\UseCase\MontarPaginaDaAssinaturaUseCase;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * A página da tela Assinatura de documentos. Tudo o mais é JSON ({@see DocumentosDaAssinaturaController}) ou arquivo
 * ({@see ArquivosDaAssinaturaController}); a página recebe os dados iniciais e as URLs nos atributos da raiz.
 */
final class AssinaturaEletronicaController extends AbstractController
{
    /**
     * As rotas que a tela chama, pelo nome curto (o `data-url-*`), com o marcador `0` no lugar do `{id}`. Só as desta
     * etapa: o JS esconde o botão cuja URL falta.
     */
    private const ROTAS = [
        'documentos' => false,
        'documento' => true,
        'criar' => false,
        'salvar' => true,
        'duplicar' => true,
        'lixeira' => false,
        'restaurar' => false,
        'excluir' => false,
        'cor' => false,
        'arquivo' => true,
        'logo' => true,
        'logo_padrao' => false,
    ];

    public function __construct(
        private readonly AcessoAAssinatura $acesso,
        private readonly MontarPaginaDaAssinaturaUseCase $pagina,
    ) {
    }

    #[Route('/assinatura', name: 'assinatura_eletronica_index', methods: ['GET'])]
    public function index(): Response
    {
        [$usuario, $tenant] = $this->acesso->quemPode() ?? throw $this->createAccessDeniedException();

        return $this->render('assinatura_eletronica/index.html.twig', array_merge(
            $this->pagina->executar($usuario, $tenant),
            ['urls' => $this->urls()],
        ));
    }

    /** @return array<string, string> */
    private function urls(): array
    {
        $urls = [];
        foreach (self::ROTAS as $nome => $comId) {
            $urls[$nome] = $this->generateUrl('assinatura_eletronica_' . $nome, $comId ? ['id' => 0] : []);
        }

        return $urls;
    }
}
