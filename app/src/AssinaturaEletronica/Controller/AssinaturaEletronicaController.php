<?php

declare(strict_types=1);

namespace App\AssinaturaEletronica\Controller;

use App\AssinaturaEletronica\EventListener\AssinaturaPublicaListener;
use App\AssinaturaEletronica\Exception\DocumentoNaoEncontrado;
use App\AssinaturaEletronica\Service\AcessoAAssinatura;
use App\AssinaturaEletronica\UseCase\MontarPaginaDaAssinaturaUseCase;
use App\AssinaturaEletronica\UseCase\ObterPreviaDoSignatarioUseCase;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * A página da tela Assinatura de documentos e a prévia da tela do signatário (etapa 2). Tudo o mais é JSON
 * ({@see DocumentosDaAssinaturaController}) ou arquivo ({@see ArquivosDaAssinaturaController}); a página recebe os
 * dados iniciais e as URLs nos atributos da raiz.
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
        // Etapa 4 (contrato da etapa 4, seção 4): modelos do escritório.
        'modelos' => false,
        'modelo_criar' => false,
        'modelo_salvar' => true,
        'modelo_excluir' => true,
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

    /**
     * "Tela do signatário" do detalhe (contrato da etapa 2, decisão E): a página pública em modo prévia, só leitura.
     * Nenhuma URL de POST nem CSRF; os arquivos pelas rotas internas, com a sessão de quem tem o módulo.
     */
    #[Route('/assinatura/documentos/{id}/previa/{ordem}', name: 'assinatura_eletronica_previa', requirements: ['id' => '\d+', 'ordem' => '\d+'], methods: ['GET'])]
    public function previa(int $id, int $ordem, ObterPreviaDoSignatarioUseCase $previa): Response
    {
        [, $tenant] = $this->acesso->quemPode() ?? throw $this->createAccessDeniedException();

        try {
            $publico = $previa->executar($tenant, $id, $ordem);
        } catch (DocumentoNaoEncontrado $e) {
            throw $this->createNotFoundException($e->getMessage(), $e);
        }

        return AssinaturaPublicaListener::proteger($this->render('assinatura_eletronica/publica.html.twig', [
            'publico' => $publico,
            'csrf' => null,
            'urls' => [
                'arquivo' => $this->generateUrl('assinatura_eletronica_arquivo', ['id' => $id]),
                'logo' => $this->generateUrl('assinatura_eletronica_logo', ['id' => $id]),
                'rubrica' => $this->generateUrl('assinatura_eletronica_rubrica', ['id' => $id, 'ordem' => 0]),
            ],
            'limite' => false,
        ]));
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
