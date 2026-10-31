<?php

declare(strict_types=1);

namespace App\Master\Controller;

use App\Auth\Entity\InscricaoOab;
use App\Auth\Repository\InscricaoOabRepository;
use App\Auth\UseCase\ConferirInscricaoOabPeloCnaUseCase;
use App\Auth\UseCase\ConfirmarInscricaoOabUseCase;
use App\Master\UseCase\AlterarValidacaoAutomaticaOabUseCase;
use App\Master\UseCase\ConsultarRevisaoOabUseCase;
use App\Master\UseCase\ValidarFormatoDasInscricoesOabUseCase;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * A "Revisão de OAB" do Painel do Master (desenho 05 - Perfis 1.2 de 31/10): lista de todas as inscrições,
 * confirmar, conferir pelo nome no CNA, validação automática e "Validar agora". Só o Master. JSON, com o token CSRF
 * `master_revisao_oab` no header `X-CSRF-Token` em todo POST. A fila Twig antiga (/admin/platform/oab) continua.
 *
 * Confirmar e conferir recebem o número e a UF que a tela mostrou: se a inscrição mudou depois que a lista foi
 * aberta, a ação é recusada (mesma proteção da fila antiga).
 */
#[IsGranted('ROLE_SUPER_ADMIN')]
#[Route('/master/revisao-oab')]
final class RevisaoOabDoMasterController extends AbstractController
{
    public const TOKEN = 'master_revisao_oab';

    public function __construct(
        private readonly TranslatorInterface $tradutor,
        private readonly InscricaoOabRepository $inscricoes,
    ) {
    }

    #[Route('', name: 'master_revisao_oab_listar', methods: ['GET'])]
    public function listar(ConsultarRevisaoOabUseCase $consultar): JsonResponse
    {
        return $this->json($consultar->executar());
    }

    #[Route('/{id}/confirmar', name: 'master_revisao_oab_confirmar', requirements: ['id' => '\d{1,10}'], methods: ['POST'])]
    public function confirmar(int $id, Request $request, ConfirmarInscricaoOabUseCase $confirmar): JsonResponse
    {
        $recusa = $this->conferirPedido($id, $request);
        if ($recusa !== null) {
            return $recusa;
        }

        return $this->json(['ok' => true, 'status' => $confirmar->executar($id)->getStatus()->value]);
    }

    #[Route('/{id}/conferir', name: 'master_revisao_oab_conferir', requirements: ['id' => '\d{1,10}'], methods: ['POST'])]
    public function conferir(int $id, Request $request, ConferirInscricaoOabPeloCnaUseCase $conferir): JsonResponse
    {
        $recusa = $this->conferirPedido($id, $request);
        if ($recusa !== null) {
            return $recusa;
        }
        $nome = $this->corpo($request)['nomeCna'] ?? '';
        $inscricao = $conferir->executar($id, is_string($nome) ? mb_substr($nome, 0, 255) : '');

        return $this->json(['ok' => true, 'status' => $inscricao->getStatus()->value, 'nomeCna' => $inscricao->getNomeOficial()]);
    }

    #[Route('/validacao-automatica', name: 'master_revisao_oab_automatica', methods: ['POST'])]
    public function validacaoAutomatica(Request $request, AlterarValidacaoAutomaticaOabUseCase $alterar, ConsultarRevisaoOabUseCase $consultar): JsonResponse
    {
        if (!$this->tokenValido($request)) {
            return $this->recusa('master.revisao_oab.token_invalido', Response::HTTP_FORBIDDEN);
        }
        $corpo = $this->corpo($request);
        $frequencia = $corpo['frequencia'] ?? 'cadastro';
        try {
            $alterar->executar(($corpo['ligada'] ?? false) === true, is_string($frequencia) ? $frequencia : '');
        } catch (\DomainException) {
            return $this->recusa('master.revisao_oab.frequencia_sem_cna', Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return $this->json(['ok' => true, 'auto' => $consultar->auto()]);
    }

    #[Route('/validar-agora', name: 'master_revisao_oab_validar_agora', methods: ['POST'])]
    public function validarAgora(Request $request, ValidarFormatoDasInscricoesOabUseCase $validar): JsonResponse
    {
        if (!$this->tokenValido($request)) {
            return $this->recusa('master.revisao_oab.token_invalido', Response::HTTP_FORBIDDEN);
        }
        $rodada = $validar->executar();

        return $this->json(['ok' => true, 'mensagem' => $this->tradutor->trans('master.revisao_oab.rodada', $rodada)] + $rodada);
    }

    /** Token, inscrição existente e o número/UF que a tela mostrou ainda batem com o banco. */
    private function conferirPedido(int $id, Request $request): ?JsonResponse
    {
        if (!$this->tokenValido($request)) {
            return $this->recusa('master.revisao_oab.token_invalido', Response::HTTP_FORBIDDEN);
        }
        $inscricao = $this->inscricoes->find($id);
        if (!$inscricao instanceof InscricaoOab) {
            return $this->recusa('master.revisao_oab.nao_encontrada', Response::HTTP_NOT_FOUND);
        }
        $corpo = $this->corpo($request);
        if (($corpo['numero'] ?? null) !== $inscricao->getNumero() || ($corpo['uf'] ?? null) !== $inscricao->getUf()) {
            return $this->recusa('master.revisao_oab.inscricao_mudou', Response::HTTP_CONFLICT);
        }

        return null;
    }

    private function recusa(string $chave, int $status): JsonResponse
    {
        return $this->json(['erro' => $this->tradutor->trans($chave)], $status);
    }

    private function tokenValido(Request $request): bool
    {
        return $this->isCsrfTokenValid(self::TOKEN, (string) $request->headers->get('X-CSRF-Token', ''));
    }

    /** @return array<mixed> */
    private function corpo(Request $request): array
    {
        $corpo = json_decode($request->getContent(), true);

        return is_array($corpo) ? $corpo : [];
    }
}
