<?php

declare(strict_types=1);

namespace App\Master\Controller;

use App\Entity\Auth\User;
use App\Master\DTO\DesligarConvenioInput;
use App\Master\DTO\SalvarConvenioInput;
use App\Master\Exception\AvisoDeConvenioNaoEnfileirado;
use App\Master\Exception\ConvenioRecusado;
use App\Master\UseCase\ConsultarConveniosUseCase;
use App\Master\UseCase\DesligarConvenioUseCase;
use App\Master\UseCase\SalvarConvenioUseCase;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Lista de convênios do painel do Master (spec docs/specs/convenios.md, §7.2 a §7.5): listar, criar, editar, ligar,
 * desligar com aviso e desligar por instituição. Só o Master: ROLE_SUPER_ADMIN na classe.
 *
 * Token CSRF `master_convenios` no header `X-CSRF-Token`, corpo JSON. Toda resposta traz a lista inteira
 * ({@see ConsultarConveniosUseCase}); os POST trazem também `mensagem`. Recusa: 422; aviso que não entrou na fila: 500.
 */
#[IsGranted('ROLE_SUPER_ADMIN')]
final class ConveniosController extends AbstractController
{
    public const CSRF = 'master_convenios';

    public function __construct(
        private readonly ConsultarConveniosUseCase $consultar,
        private readonly TranslatorInterface $tradutor,
    ) {
    }

    #[Route('/master/convenios/lista', name: 'master_convenio_lista', methods: ['GET'])]
    public function lista(): JsonResponse
    {
        return $this->json($this->consultar->executar());
    }

    #[Route('/master/convenios/salvar', name: 'master_convenio_salvar', methods: ['POST'])]
    public function salvar(Request $request, SalvarConvenioUseCase $salvar): JsonResponse
    {
        return $this->responder($request, function (array $corpo) use ($salvar): array {
            $salvo = $salvar->executar(SalvarConvenioInput::doJson($this->ator(), $corpo));

            return ['mensagem' => $this->t($salvo['criado'] ? 'criado' : 'salvo', ['nome' => $salvo['convenio']->getNome()]), 'id' => $salvo['convenio']->getId()];
        });
    }

    #[Route('/master/convenios/{id}/ligar', name: 'master_convenio_ligar', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function ligar(int $id, Request $request, DesligarConvenioUseCase $desligar): JsonResponse
    {
        return $this->responder($request, function (array $corpo) use ($id, $desligar): array {
            $feito = $desligar->doConvenio($id, DesligarConvenioInput::doJson($this->ator(), $corpo));

            return ['mensagem' => $this->t($feito['acao'], ['n' => $feito['avisados']]), 'avisados' => $feito['avisados']];
        });
    }

    #[Route('/master/convenios/instituicao/{chave}/desligar', name: 'master_convenio_instituicao_desligar', requirements: ['chave' => '[a-z0-9_-]{1,40}'], methods: ['POST'])]
    public function desligarInstituicao(string $chave, Request $request, DesligarConvenioUseCase $desligar): JsonResponse
    {
        return $this->responder($request, function (array $corpo) use ($chave, $desligar): array {
            $feito = $desligar->daInstituicao($chave, DesligarConvenioInput::doJson($this->ator(), $corpo, sempreDesligar: true));

            return ['mensagem' => $this->t('instituicao', ['n' => $feito['avisados']]), 'avisados' => $feito['avisados']];
        });
    }

    /** @param callable(array<mixed>): array<string, mixed> $acao */
    private function responder(Request $request, callable $acao): JsonResponse
    {
        if (!$this->isCsrfTokenValid(self::CSRF, (string) $request->headers->get('X-CSRF-Token', ''))) {
            return $this->json(['erro' => 'Token CSRF inválido. Recarregue a página.'], Response::HTTP_FORBIDDEN);
        }

        try {
            $extra = $acao($this->corpo($request));
        } catch (ConvenioRecusado $e) {
            return $this->json(['erro' => $e->getMessage()], Response::HTTP_UNPROCESSABLE_ENTITY);
        } catch (AvisoDeConvenioNaoEnfileirado) {
            return $this->json(['erro' => $this->tradutor->trans('master.convenio_email.falha_ao_enfileirar')], Response::HTTP_INTERNAL_SERVER_ERROR);
        }

        return $this->json($this->consultar->executar() + $extra);
    }

    /** @param array<string, int|string|null> $params */
    private function t(string $chave, array $params): string
    {
        return $this->tradutor->trans('master.convenios.mensagem.' . $chave, $params);
    }

    private function ator(): ?User
    {
        $ator = $this->getUser();

        return $ator instanceof User ? $ator : null;
    }

    /** @return array<mixed> */
    private function corpo(Request $request): array
    {
        try {
            $dados = json_decode($request->getContent(), true, 8, \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return [];
        }

        return is_array($dados) ? $dados : [];
    }
}
