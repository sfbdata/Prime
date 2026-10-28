<?php

declare(strict_types=1);

namespace App\ChatIa\Controller;

use App\ChatIa\Exception\AnexoDoChatInvalidoException;
use App\ChatIa\UseCase\AnexarArquivoAoChatUseCase;
use App\ChatIa\UseCase\RemoverAnexoDoChatUseCase;
use App\Service\Tenant\TenantContext;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Anexos do Chat I.A. `POST /chat-ia/anexos` (multipart `arquivo` + `conversaId`) devolve `{id, nome, mime, tamanho}`;
 * `DELETE /chat-ia/anexos/{id}` descarta um anexo ainda não enviado numa pergunta (rota além do contrato, para o "x" do
 * clipe). Mutações exigem o CSRF `ajax` em `X-CSRF-Token`. O tipo do arquivo é decidido pelo conteúdo no UseCase.
 */
#[Route('/chat-ia/anexos')]
final class ChatIaAnexosController extends AbstractController
{
    use RespondeEmJsonDoChat;

    /** Maior arquivo que o chat aceita (PDF e DOCX): lido para a memória só abaixo disto. */
    private const TAMANHO_MAXIMO = 10 * 1024 * 1024;

    public function __construct(
        private readonly TenantContext $tenantContext,
        // Interface (não a classe): dublê em teste.
        private readonly RateLimiterFactoryInterface $chatIaEscritaLimiter,
        private readonly AnexarArquivoAoChatUseCase $anexar,
        private readonly RemoverAnexoDoChatUseCase $remover,
    ) {
    }

    #[Route('', name: 'chat_ia_anexos_enviar', methods: ['POST'])]
    public function enviar(Request $request): JsonResponse
    {
        if (($recusa = $this->semCsrf($request)) !== null) {
            return $recusa;
        }
        if (($recusa = $this->semRitmo($this->chatIaEscritaLimiter)) !== null) {
            return $recusa;
        }
        try {
            [$nome, $bytes] = $this->arquivoDe($request);
            $anexo = $this->anexar->executar($this->usuario(), $this->tenantContext->getCurrentTenant(), (int) $request->request->get('conversaId', 0), $nome, $bytes);
        } catch (\Throwable $e) {
            return $this->recusaDe($e);
        }

        return new JsonResponse($anexo->paraArray(), Response::HTTP_CREATED);
    }

    #[Route('/{id}', name: 'chat_ia_anexo_remover', requirements: ['id' => '\d{1,9}'], methods: ['DELETE'])]
    public function remover(int $id, Request $request): JsonResponse
    {
        if (($recusa = $this->semCsrf($request)) !== null) {
            return $recusa;
        }
        try {
            $this->remover->executar($this->usuario(), $this->tenantContext->getCurrentTenant(), $id);
        } catch (\Throwable $e) {
            return $this->recusaDe($e);
        }

        return new JsonResponse(['ok' => true]);
    }

    /**
     * @return array{string, string} o nome original e os bytes
     *
     * @throws AnexoDoChatInvalidoException
     */
    private function arquivoDe(Request $request): array
    {
        $arquivo = $request->files->get('arquivo');
        if (!$arquivo instanceof UploadedFile || !$arquivo->isValid()) {
            throw new AnexoDoChatInvalidoException('Não recebi o arquivo. Tente de novo.');
        }
        if ($arquivo->getSize() > self::TAMANHO_MAXIMO) {
            throw new AnexoDoChatInvalidoException('O arquivo passa de 10 MB.');
        }

        return [$arquivo->getClientOriginalName(), $arquivo->getContent()];
    }
}
