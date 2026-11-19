<?php

declare(strict_types=1);

namespace App\Pasta\Controller;

use App\Entity\Auth\User;
use App\Pasta\DTO\PastaRecebida;
use App\Pasta\Exception\EscritaNaPastaRecebidaNegadaException;
use App\Pasta\Exception\PastaRecebidaNaoEncontradaException;
use App\Pasta\Service\PastaRecebidaDoUsuario;
use App\Pasta\UseCase\AnotarNaPastaRecebidaUseCase;
use App\Pasta\UseCase\EnviarDocumentoNaPastaRecebidaUseCase;
use App\Pasta\UseCase\MudarFaseDaPastaRecebidaUseCase;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Escrever na pasta recebida de outra conta (spec acesso-restrito-e-compartilhar §4.5.1, fatia 9b): anotar, trocar a
 * fase e enviar arquivo, da página reduzida (`expediente_recebida_pasta`). A sessão continua no escritório de quem
 * recebeu (B); a pasta é do escritório de origem (A).
 *
 * Molde comum: POST com CSRF; a primeira coisa é `PastaRecebidaDoUsuario::localizar()` (404 sem motivo; o
 * `PastaRecebidaRotasArquiteturaTest` recusa rota `expediente_recebida*` que não comece por ele); depois o token
 * (403), e o UseCase confere a conta da pasta suspensa, a caixa do convite e a aba (403) e o pedido (422). O trabalho
 * e o flush rodam nos UseCases com o filtro do escritório desligado, sempre com A e a pasta do convite. Nada vem por
 * id solto: nem a resposta a um registro (fora da 9b; um `resposta_a` no pedido é ignorado) nem a seção do documento.
 *
 * Responde JSON: `{ok: true}` ou `{erro: "…"}`.
 */
#[Route('/expediente/recebidas/{compartilhamentoId}', requirements: ['compartilhamentoId' => '\d+'])]
final class EscritaNaPastaRecebidaController extends AbstractController
{
    public function __construct(
        private readonly PastaRecebidaDoUsuario $localizador,
        private readonly AnotarNaPastaRecebidaUseCase $anotarUseCase,
        private readonly MudarFaseDaPastaRecebidaUseCase $mudarFaseUseCase,
        private readonly EnviarDocumentoNaPastaRecebidaUseCase $enviarDocumentoUseCase,
        private readonly TranslatorInterface $tradutor,
    ) {
    }

    /** Anotação nova no Registro da pasta (aba Andamentos da página), com "Movimentar". */
    #[Route('/anotacao', name: 'expediente_recebida_anotar', methods: ['POST'])]
    public function anotar(int $compartilhamentoId, Request $request): JsonResponse
    {
        $recebida = $this->localizar($compartilhamentoId);

        return $this->escrever($request, $recebida, 'anotar', Response::HTTP_CREATED, function () use ($recebida, $request): void {
            $this->anotarUseCase->executar($recebida, $this->pessoa(), (string) $request->request->get('conteudo', ''));
        });
    }

    /** Troca de fase do andamento (cabeçalho da página), com "Movimentar": só as fases do mesmo lado do Contrato. */
    #[Route('/fase', name: 'expediente_recebida_fase', methods: ['POST'])]
    public function fase(int $compartilhamentoId, Request $request): JsonResponse
    {
        $recebida = $this->localizar($compartilhamentoId);

        return $this->escrever($request, $recebida, 'fase', Response::HTTP_OK, function () use ($recebida, $request): void {
            $fase = (string) $request->request->get('fase', '');
            if (!ctype_digit($fase)) {
                throw new \InvalidArgumentException($this->tradutor->trans('expediente.recebida.fase_invalida'));
            }
            $this->mudarFaseUseCase->executar($recebida, $this->pessoa(), (int) $fase);
        });
    }

    /** Um arquivo para a aba Documentos, com "Enviar documentos". */
    #[Route('/documento', name: 'expediente_recebida_documento_enviar', methods: ['POST'])]
    public function documentoEnviar(int $compartilhamentoId, Request $request): JsonResponse
    {
        $recebida = $this->localizar($compartilhamentoId);

        return $this->escrever($request, $recebida, 'documento', Response::HTTP_CREATED, function () use ($recebida, $request): void {
            $arquivo = $request->files->get('arquivo');
            if (!$arquivo instanceof UploadedFile) {
                throw new \InvalidArgumentException($this->tradutor->trans('expediente.recebida.sem_arquivo'));
            }
            $categoria = strtoupper(trim((string) $request->request->get('categoria', '')));
            $this->enviarDocumentoUseCase->executar($recebida, $this->pessoa(), $arquivo, $categoria);
        });
    }

    /**
     * O que as três escritas têm em comum depois do localizador: o token (`expediente_recebida_<acao>_<convite>`) e a
     * tradução das recusas. A negada (403) vem antes da recusada (422), as duas são `\DomainException`.
     *
     * @param \Closure(): void $trabalho
     */
    private function escrever(Request $request, PastaRecebida $recebida, string $acao, int $sucesso, \Closure $trabalho): JsonResponse
    {
        if (!$this->isCsrfTokenValid('expediente_recebida_' . $acao . '_' . $recebida->compartilhamentoId(), (string) $request->request->get('_token'))) {
            return $this->json(['erro' => $this->tradutor->trans('expediente.recebida.token_invalido')], Response::HTTP_FORBIDDEN);
        }

        try {
            $trabalho();
        } catch (EscritaNaPastaRecebidaNegadaException) {
            return $this->json(['erro' => $this->tradutor->trans('expediente.recebida.sem_permissao')], Response::HTTP_FORBIDDEN);
        } catch (\DomainException|\InvalidArgumentException $e) {
            return $this->json(['erro' => $e->getMessage()], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return $this->json(['ok' => true], $sucesso);
    }

    private function localizar(int $compartilhamentoId): PastaRecebida
    {
        try {
            return $this->localizador->localizar($compartilhamentoId, $this->pessoa());
        } catch (PastaRecebidaNaoEncontradaException) {
            throw $this->createNotFoundException();
        }
    }

    private function pessoa(): User
    {
        $pessoa = $this->getUser();
        if (!$pessoa instanceof User) {
            throw $this->createAccessDeniedException();
        }

        return $pessoa;
    }
}
