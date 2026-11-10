<?php

declare(strict_types=1);

namespace App\AssinaturaEletronica\Controller;

use App\AssinaturaEletronica\DTO\ArquivoParaEntregar;
use App\AssinaturaEletronica\Exception\DocumentoNaoEncontrado;
use App\AssinaturaEletronica\Exception\GeracaoDoPdfRecusada;
use App\AssinaturaEletronica\Service\AcessoAAssinatura;
use App\AssinaturaEletronica\Service\EntregaDoPdfDaAssinatura;
use App\AssinaturaEletronica\UseCase\ObterArquivoDaAssinaturaUseCase;
use App\AssinaturaEletronica\UseCase\ObterOriginalComAssinaturasUseCase;
use App\AssinaturaEletronica\UseCase\ObterPdfDaAssinaturaUseCase;
use App\Entity\Tenant\Tenant;
use App\Shared\Http\EntregaDeArquivo;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Os arquivos da tela Assinatura, abertos no navegador: o PDF enviado (a prévia do pdf.js), a logo própria do
 * documento, a rubrica de quem assinou (etapa 2) e a logo padrão do escritório. Documento de outro escritório: 404.
 *
 * Etapa 5, bloco 5A: "Baixar original com as assinaturas" sai como anexo (download), não aberto no navegador.
 * Etapa 3 (A5): o "Baixar PDF com selo", como download, em qualquer situação ({@see ObterPdfDaAssinaturaUseCase}).
 * Gerar o PDF pesa (cerca de 1,2 s por página): as vagas de geração e o limitador `assinatura_pdf` (por usuário, só no
 * pedido que gera de verdade) respondem 429 `{"erro": ...}` com o texto que a tela mostra.
 */
final class ArquivosDaAssinaturaController extends AbstractController
{
    public function __construct(
        private readonly AcessoAAssinatura $acesso,
        private readonly ObterArquivoDaAssinaturaUseCase $arquivos,
        private readonly EntregaDeArquivo $entrega,
    ) {
    }

    #[Route('/assinatura/documentos/{id}/arquivo', name: 'assinatura_eletronica_arquivo', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function arquivo(int $id): Response
    {
        return $this->entregar(fn (Tenant $tenant): ArquivoParaEntregar => $this->arquivos->pdf($tenant, $id));
    }

    #[Route('/assinatura/documentos/{id}/logo', name: 'assinatura_eletronica_logo', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function logo(int $id): Response
    {
        return $this->entregar(fn (Tenant $tenant): ArquivoParaEntregar => $this->arquivos->logo($tenant, $id));
    }

    #[Route('/assinatura/documentos/{id}/rubrica/{ordem}', name: 'assinatura_eletronica_rubrica', requirements: ['id' => '\d+', 'ordem' => '\d+'], methods: ['GET'])]
    public function rubrica(int $id, int $ordem): Response
    {
        return $this->entregar(fn (Tenant $tenant): ArquivoParaEntregar => $this->arquivos->rubrica($tenant, $id, $ordem));
    }

    #[Route('/assinatura/documentos/{id}/original', name: 'assinatura_eletronica_original', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function original(int $id, ObterOriginalComAssinaturasUseCase $original): Response
    {
        return $this->entregar(fn (Tenant $tenant): ArquivoParaEntregar => $original->executar($tenant, $id), inline: false);
    }

    #[Route('/assinatura/documentos/{id}/pdf', name: 'assinatura_eletronica_pdf', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function pdf(
        int $id,
        ObterPdfDaAssinaturaUseCase $obter,
        EntregaDoPdfDaAssinatura $entrega,
        RateLimiterFactoryInterface $assinaturaPdfLimiter,
        TranslatorInterface $tradutor,
    ): Response {
        [$usuario, $tenant] = $this->acesso->quemPode() ?? throw $this->createAccessDeniedException();
        $cabeNaCota = static fn (): bool => $assinaturaPdfLimiter->create('usuario-' . $usuario->getId())->consume()->isAccepted();
        EntregaDoPdfDaAssinatura::limitar();

        try {
            return $entrega->resposta($obter->doEscritorio($tenant, $id, $cabeNaCota));
        } catch (DocumentoNaoEncontrado $e) {
            throw $this->createNotFoundException($e->getMessage(), $e);
        } catch (GeracaoDoPdfRecusada $e) {
            return new JsonResponse(['erro' => $tradutor->trans($e->chave())], Response::HTTP_TOO_MANY_REQUESTS);
        }
    }

    #[Route('/assinatura/logo-padrao', name: 'assinatura_eletronica_logo_padrao', methods: ['GET'])]
    public function logoPadrao(): Response
    {
        return $this->entregar(fn (Tenant $tenant): ArquivoParaEntregar => $this->arquivos->logoPadrao($tenant));
    }

    /** @param \Closure(Tenant): ArquivoParaEntregar $qual */
    private function entregar(\Closure $qual, bool $inline = true): Response
    {
        [, $tenant] = $this->acesso->quemPode() ?? throw $this->createAccessDeniedException();

        try {
            $arquivo = $qual($tenant);
        } catch (DocumentoNaoEncontrado $e) {
            throw $this->createNotFoundException($e->getMessage(), $e);
        }

        return $this->entrega->resposta($arquivo->chave, $arquivo->nome, $inline);
    }
}
