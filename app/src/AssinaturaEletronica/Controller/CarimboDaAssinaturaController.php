<?php

declare(strict_types=1);

namespace App\AssinaturaEletronica\Controller;

use App\AssinaturaEletronica\Exception\DocumentoNaoEncontrado;
use App\AssinaturaEletronica\Service\AcessoAAssinatura;
use App\AssinaturaEletronica\UseCase\ObterCopiaComCarimboUseCase;
use App\Shared\Http\NomeSeguroDeArquivo;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * A "Cópia com carimbo visível" do Detalhe (etapa 5, bloco 5B; dc:384 e 466): o PDF assinado mais recente do documento
 * com o carimbo das assinaturas eletrônicas do BlueJus. Só lê: nunca grava (nem banco, nem trilha, nem arquivo).
 *
 * Contrato da rota, para o botão "Cópia com carimbo visível" (bloco 5A), que a chama por `fetch`:
 *
 * - 200: o PDF carimbado, `application/pdf`, como anexo "{nome do documento} - com carimbo.pdf", sem cache;
 * - 422 sem nenhuma assinatura eletrônica do BlueJus: JSON
 *   `{"erro": "Ainda não há assinatura eletrônica feita pelo BlueJus para carimbar.", "semAssinatura": true}`; o botão
 *   mostra o texto como veio (no desenho este aviso não leva " Use o original.", dc:384);
 * - 422 recusa do carimbo: JSON `{"erro": "<motivo>"}`, só o motivo, sem `semAssinatura` e sem o " Use o original."
 *   (por exemplo "Arquivo protegido por senha."); o botão acrescenta " Use o original.", como o `presCarimbo` do
 *   desenho (dc:384);
 * - 404 sem PDF assinado guardado (só o PDF para assinar não conta) ou com o arquivo ausente: JSON
 *   `{"erro": "Original não encontrado."}`;
 * - 404 comum (sem JSON): documento de outro escritório ou inexistente;
 * - 403: sem acesso à Assinatura.
 *
 * A chave `erro` é a que o `pedir()` do `assinatura.js` lê. Outra falha (404 comum, 500, rede) o botão mostra como
 * "Não foi possível carimbar. Use o original." (o `catch` do desenho).
 */
final class CarimboDaAssinaturaController extends AbstractController
{
    public function __construct(
        private readonly AcessoAAssinatura $acesso,
        private readonly ObterCopiaComCarimboUseCase $copia,
    ) {
    }

    #[Route('/assinatura/documentos/{id}/carimbo', name: 'assinatura_eletronica_carimbo', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function carimbo(int $id): Response
    {
        [, $tenant] = $this->acesso->quemPode() ?? throw $this->createAccessDeniedException();

        // O PDF de até 20 MB (teto do motor) passa pela memória mais de uma vez. Precedente: o "Baixar PDF com selo" do
        // bloco 1a da etapa 3 (`EntregaDoPdfDaAssinatura::limitar()`): só nesta ação, 300 s fora da CLI (na CLI o limite
        // valeria para o processo inteiro do ParaTest) e `memory_limit` de 256M, como o worker (o FPM tem 128M e 30 s).
        if (\PHP_SAPI !== 'cli') {
            set_time_limit(300);
        }
        $memoria = ini_parse_quantity((string) ini_get('memory_limit'));
        if ($memoria !== -1 && $memoria < 256 * 1024 * 1024) {
            ini_set('memory_limit', '256M');
        }

        try {
            $copia = $this->copia->executar($tenant, $id);
        } catch (DocumentoNaoEncontrado $e) {
            throw $this->createNotFoundException($e->getMessage(), $e);
        }

        if ($copia->pdf === null) {
            $status = $copia->semOriginal ? Response::HTTP_NOT_FOUND : Response::HTTP_UNPROCESSABLE_ENTITY;

            return $this->json(['erro' => $copia->erro] + ($copia->semAssinatura ? ['semAssinatura' => true] : []), $status);
        }

        $nome = NomeSeguroDeArquivo::paraDownload((string) $copia->nome);

        return new Response($copia->pdf, Response::HTTP_OK, [
            'Content-Type' => 'application/pdf',
            // O fallback ASCII troca cada caractere fora de 0x20 a 0x7E (e o "%") por "_", como a BinaryFileResponse.
            'Content-Disposition' => HeaderUtils::makeDisposition(HeaderUtils::DISPOSITION_ATTACHMENT, $nome, (string) preg_replace('/[^\x20-\x7E]|%/u', '_', $nome)),
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
