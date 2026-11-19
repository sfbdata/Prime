<?php

declare(strict_types=1);

namespace App\Pasta\Controller;

use App\Entity\Auth\User;
use App\Pasta\DTO\PastaRecebida;
use App\Pasta\Entity\Pasta;
use App\Pasta\Entity\PastaDocumento;
use App\Pasta\Exception\PastaRecebidaNaoEncontradaException;
use App\Pasta\Service\LeitorDaPastaRecebida;
use App\Pasta\Service\PastaRecebidaDoUsuario;
use App\Pasta\Service\TrilhaDoAcessoRestrito;
use App\Shared\Armazenamento\ArmazenamentoDeArquivos;
use App\Shared\Http\EntregaDeArquivo;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Abrir a pasta recebida de outra conta, só leitura (spec acesso-restrito-e-compartilhar §4.5.1, fatia 9a; desenho 03
 * L11). A sessão continua no escritório de quem recebeu (B); a pasta é do escritório de origem (A) e é lida pelo
 * `LeitorDaPastaRecebida`, nunca pelas rotas `pasta_*`.
 *
 * Uma porta só: toda ação começa por `PastaRecebidaDoUsuario::localizar()` (o `PastaRecebidaRotasArquiteturaTest`
 * recusa rota `expediente_recebida*` que não o chame). Falhou: 404 sem motivo.
 *
 * Trilha (§4.6): abrir a página e o documento (`ver` e `baixar`) gravam `leitura` no escritório A, com o usuário de
 * B; o 403 do `baixar` sem "Baixar documentos" grava `negado`. A recusa do localizador não grava.
 */
#[Route('/expediente/recebidas/{compartilhamentoId}', requirements: ['compartilhamentoId' => '\d+'])]
final class PastaRecebidaController extends AbstractController
{
    public function __construct(
        private readonly PastaRecebidaDoUsuario $localizador,
        private readonly LeitorDaPastaRecebida $leitor,
        private readonly TrilhaDoAcessoRestrito $trilha,
        private readonly ArmazenamentoDeArquivos $armazenamento,
        private readonly EntregaDeArquivo $entrega,
    ) {
    }

    /** A janela "Pasta recebida N" (L11), em JSON. */
    #[Route('', name: 'expediente_recebida_ver', methods: ['GET'])]
    public function ver(int $compartilhamentoId): JsonResponse
    {
        $recebida = $this->localizar($compartilhamentoId);
        $resumo = $this->leitor->resumo($recebida);

        return $this->json($resumo->paraJson() + [
            'urlPasta' => $this->generateUrl('expediente_recebida_pasta', ['compartilhamentoId' => $recebida->compartilhamentoId()]),
            'urlMetas' => $this->generateUrl('expediente_recebida_pasta', ['compartilhamentoId' => $recebida->compartilhamentoId(), 'aba' => 'metas']),
        ]);
    }

    /** A página reduzida da pasta (`?aba=metas` abre direto nas metas, o "Abrir metas" da janela). */
    #[Route('/pasta', name: 'expediente_recebida_pasta', methods: ['GET'])]
    public function pasta(int $compartilhamentoId, Request $request): Response
    {
        $recebida = $this->localizar($compartilhamentoId);
        $leitura = $this->leitor->ler($recebida);
        $this->trilha->leitura($this->pessoa(), $recebida->tenant, $request, Pasta::class, $recebida->pastaId());

        $aba = $request->query->get('aba') === 'metas' && $leitura->abaMetas ? 'metas' : $leitura->primeiraAba();

        return $this->render('pasta/recebida.html.twig', ['pasta' => $leitura, 'aba' => $aba]);
    }

    /** Um documento da pasta: `ver` abre no navegador (todo convite vê os documentos), `baixar` exige "Baixar documentos". */
    #[Route('/documento/{documentoId}/{modo}', name: 'expediente_recebida_documento', requirements: ['documentoId' => '\d+', 'modo' => 'ver|baixar'], methods: ['GET'])]
    public function documento(int $compartilhamentoId, int $documentoId, string $modo, Request $request): Response
    {
        $recebida = $this->localizar($compartilhamentoId);
        $documento = $this->leitor->documento($recebida, $documentoId);
        if ($documento === null) {
            throw $this->createNotFoundException();
        }
        $baixar = $modo === 'baixar';
        if ($baixar && !$recebida->compartilhamento->permissoes()->baixarDocumentos) {
            $this->trilha->negado($this->pessoa(), $recebida->tenant, $request, PastaDocumento::class, $documento->id);

            throw $this->createAccessDeniedException();
        }
        $chave = $documento->chave;
        if (!$this->armazenamento->existe($chave)) {
            throw $this->createNotFoundException();
        }
        $this->trilha->leitura($this->pessoa(), $recebida->tenant, $request, PastaDocumento::class, $documento->id);

        return $this->entrega->resposta($chave, $documento->nomeOriginal, inline: !$baixar);
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
