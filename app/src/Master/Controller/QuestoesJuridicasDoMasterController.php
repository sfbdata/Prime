<?php

declare(strict_types=1);

namespace App\Master\Controller;

use App\Entity\Auth\User;
use App\Inteligencia\DTO\MudancaDeStatusDaQuestaoOutput;
use App\Inteligencia\DTO\QuestaoJuridicaNoPainelOutput;
use App\Inteligencia\DTO\VersaoDeDocumentoJuridicoOutput;
use App\Inteligencia\Enum\DocumentoJuridico;
use App\Inteligencia\Enum\StatusDaQuestaoJuridica;
use App\Inteligencia\Exception\DocumentoJuridicoRecusadoException;
use App\Inteligencia\Exception\QuestaoJuridicaNaoEncontradaException;
use App\Inteligencia\Exception\VersaoDeDocumentoJuridicoNaoEncontradaException;
use App\Inteligencia\Juridico\DocumentosJuridicosAtuais;
use App\Inteligencia\UseCase\AlterarStatusDaQuestaoJuridicaUseCase;
use App\Inteligencia\UseCase\ContarQuestoesJuridicasEmAbertoUseCase;
use App\Inteligencia\UseCase\EnviarVersaoDeDocumentoJuridicoUseCase;
use App\Inteligencia\UseCase\ListarQuestoesJuridicasUseCase;
use App\Inteligencia\UseCase\ObterVersaoDeDocumentoJuridicoUseCase;
use App\Shared\Http\EntregaDeArquivo;
use App\Shared\Http\NomeSeguroDeArquivo;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Bloco "Questões jurídicas" da aba "Configurações da I.A." do Painel do Master: baixar a versão atual de cada
 * documento, enviar a versão ajustada (com histórico) e baixá-la, e mudar o status de cada questão (com histórico).
 * O bloco é desenhado no servidor (`master/_ia_juridico.html.twig`); estas rotas respondem no padrão do
 * {@see IaDoMasterController}: JSON, POST com o token CSRF `master_ia_juridico` no header `X-CSRF-Token` (403 sem ele),
 * recusa em 422 com `{erro}`, 404 para questão, documento ou versão que não existe.
 */
#[IsGranted('ROLE_SUPER_ADMIN')]
final class QuestoesJuridicasDoMasterController extends AbstractController
{
    public const TOKEN = 'master_ia_juridico';
    private const CSRF_INVALIDO = 'master.ia.recusa.token_invalido';

    public function __construct(
        private readonly ListarQuestoesJuridicasUseCase $listar,
        private readonly ContarQuestoesJuridicasEmAbertoUseCase $contar,
        private readonly TranslatorInterface $tradutor,
    ) {
    }

    /** A versão atual do documento (arquivo de `resources/legal/`, ou o texto do aceite gerado na hora). */
    #[Route('/master/ia/juridico/documentos/{documento}', name: 'master_ia_juridico_documento_atual', methods: ['GET'])]
    public function documentoAtual(string $documento, DocumentosJuridicosAtuais $atuais): Response
    {
        $doc = DocumentoJuridico::tryFrom($documento) ?? throw $this->createNotFoundException();
        $arquivo = $atuais->paraBaixar($doc);
        $resposta = new Response($arquivo->conteudo, Response::HTTP_OK, ['Content-Type' => $arquivo->mimeType, 'X-Content-Type-Options' => 'nosniff']);
        $nome = NomeSeguroDeArquivo::paraDownload($arquivo->nome);
        $resposta->headers->set('Content-Disposition', HeaderUtils::makeDisposition(ResponseHeaderBag::DISPOSITION_ATTACHMENT, $nome, self::ascii($nome)));

        return $resposta;
    }

    /** Envia a versão ajustada. Multipart: `arquivo` e, opcional, `questao` (a chave da questão de onde veio). */
    #[Route('/master/ia/juridico/documentos/{documento}/versoes', name: 'master_ia_juridico_versao_enviar', methods: ['POST'])]
    public function enviarVersao(string $documento, Request $request, EnviarVersaoDeDocumentoJuridicoUseCase $enviar): JsonResponse
    {
        if (!$this->tokenValido($request)) {
            return $this->recusa(self::CSRF_INVALIDO, Response::HTTP_FORBIDDEN);
        }
        $doc = DocumentoJuridico::tryFrom($documento);
        if ($doc === null) {
            return $this->recusa('master.ia.juridico.recusa.documento_nao_encontrado', Response::HTTP_NOT_FOUND);
        }
        $arquivo = $request->files->get('arquivo');
        if (!$arquivo instanceof UploadedFile) {
            return $this->recusa('master.ia.juridico.recusa.sem_arquivo', Response::HTTP_UNPROCESSABLE_ENTITY);
        }
        $questao = $request->request->getString('questao');
        try {
            $enviar->executar($doc, $questao === '' ? null : $questao, $arquivo, $this->master());
        } catch (QuestaoJuridicaNaoEncontradaException) {
            return $this->recusa('master.ia.juridico.recusa.questao_nao_encontrada', Response::HTTP_NOT_FOUND);
        } catch (DocumentoJuridicoRecusadoException $e) {
            return $this->json(['erro' => $e->getMessage()], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return $this->json(['documento' => $doc->value, 'versoes' => $this->versoesDe($doc)]);
    }

    /** Baixa uma versão ajustada já enviada, com o nome original. */
    #[Route('/master/ia/juridico/versoes/{id}', name: 'master_ia_juridico_versao_baixar', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function baixarVersao(int $id, ObterVersaoDeDocumentoJuridicoUseCase $obter, EntregaDeArquivo $entrega): Response
    {
        try {
            $arquivo = $obter->executar($id, $this->master());
        } catch (VersaoDeDocumentoJuridicoNaoEncontradaException) {
            throw $this->createNotFoundException();
        }

        return $entrega->resposta($arquivo->chave, $arquivo->nomeOriginal, inline: false);
    }

    /** Muda o status. Corpo `{status: "pendente" | "em_analise" | "resolvida"}`. */
    #[Route('/master/ia/juridico/questoes/{chave}/status', name: 'master_ia_juridico_status', requirements: ['chave' => '[a-z0-9_]{1,80}'], methods: ['POST'])]
    public function status(string $chave, Request $request, AlterarStatusDaQuestaoJuridicaUseCase $alterar): JsonResponse
    {
        if (!$this->tokenValido($request)) {
            return $this->recusa(self::CSRF_INVALIDO, Response::HTTP_FORBIDDEN);
        }
        $valor = self::corpo($request)['status'] ?? null;
        $status = is_string($valor) ? StatusDaQuestaoJuridica::tryFrom($valor) : null;
        if ($status === null) {
            return $this->recusa('master.ia.juridico.recusa.status_invalido', Response::HTTP_UNPROCESSABLE_ENTITY);
        }
        try {
            $alterar->executar($chave, $status, $this->master());
        } catch (QuestaoJuridicaNaoEncontradaException) {
            return $this->recusa('master.ia.juridico.recusa.questao_nao_encontrada', Response::HTTP_NOT_FOUND);
        }

        return $this->json($this->estadoDaQuestao($chave));
    }

    /** @return array<string, mixed> */
    private function estadoDaQuestao(string $chave): array
    {
        $questao = current(array_filter($this->listar->executar(), static fn (QuestaoJuridicaNoPainelOutput $q): bool => $q->questao->chave === $chave));
        if ($questao === false) {
            return [];
        }

        return [
            'chave' => $chave,
            'status' => $questao->status->value,
            'rotulo' => $questao->status->rotulo(),
            'historico' => array_map(self::mudancaEmJson(...), $questao->historico),
            'emAberto' => $this->contar->executar(),
        ];
    }

    /** @return list<array<string, mixed>> */
    private function versoesDe(DocumentoJuridico $documento): array
    {
        foreach ($this->listar->documentos() as $doc) {
            if ($doc->documento === $documento) {
                return array_map($this->versaoEmJson(...), $doc->versoes);
            }
        }

        return [];
    }

    /** @return array<string, mixed> */
    private static function mudancaEmJson(MudancaDeStatusDaQuestaoOutput $m): array
    {
        return ['de' => $m->de->rotulo(), 'para' => $m->para->rotulo(), 'por' => $m->por, 'quando' => $m->em->format('d/m/Y H:i')];
    }

    /** @return array<string, mixed> */
    private function versaoEmJson(VersaoDeDocumentoJuridicoOutput $v): array
    {
        return [
            'id' => $v->id,
            'nome' => $v->nomeOriginal,
            'por' => $v->enviadaPor,
            'quando' => $v->enviadaEm->format('d/m/Y H:i'),
            'questao' => $v->questaoTitulo,
            'url' => $this->generateUrl('master_ia_juridico_versao_baixar', ['id' => $v->id]),
        ];
    }

    private static function ascii(string $nome): string
    {
        $ascii = (string) preg_replace('/[^\x20-\x7E]/', '_', $nome);

        return str_replace(['%', '/', '\\', '"'], '_', $ascii);
    }

    private function recusa(string $chave, int $status): JsonResponse
    {
        return $this->json(['erro' => $this->tradutor->trans($chave)], $status);
    }

    private function tokenValido(Request $request): bool
    {
        return $this->isCsrfTokenValid(self::TOKEN, (string) $request->headers->get('X-CSRF-Token', ''));
    }

    private function master(): User
    {
        $usuario = $this->getUser();
        if (!$usuario instanceof User) {
            throw $this->createAccessDeniedException();
        }

        return $usuario;
    }

    /** @return array<mixed> */
    private static function corpo(Request $request): array
    {
        try {
            $dados = json_decode($request->getContent(), true, 8, \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return [];
        }

        return is_array($dados) ? $dados : [];
    }
}
