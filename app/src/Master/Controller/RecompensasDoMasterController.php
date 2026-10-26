<?php

declare(strict_types=1);

namespace App\Master\Controller;

use App\Entity\Auth\User;
use App\Recompensa\DTO\AlterarRegrasDoProgramaInput;
use App\Recompensa\DTO\DefinirCondicaoIndividualInput;
use App\Recompensa\DTO\RemoverCondicaoIndividualInput;
use App\Recompensa\Exception\IndicadorNaoEncontrado;
use App\Recompensa\Exception\RecompensaRecusada;
use App\Recompensa\UseCase\AlterarRegrasDoProgramaUseCase;
use App\Recompensa\UseCase\ConsultarPainelDeRecompensasUseCase;
use App\Recompensa\UseCase\DefinirCondicaoIndividualUseCase;
use App\Recompensa\UseCase\RemoverCondicaoIndividualUseCase;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Validator\Validator\ValidatorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Seção "Recompensas" do Painel do Master, F1 (desenho 05 Perfis 1.2 VS 25.10.26, L306-338; spec
 * docs/specs/perfis-1.2-vs-25-10-recompensas-e-planos.md). Só o Master: ROLE_SUPER_ADMIN na classe.
 *
 * JSON. Os POST/DELETE levam o token CSRF `master_recompensas` no header `X-CSRF-Token` (o padrão das outras seções do
 * /master) e respondem o mesmo corpo do GET, já com o estado novo; 403 sem token válido, 404 para indicador que não
 * existe, 422 com `{erro}` para pedido recusado. Idioma: as recusas próprias saem do catálogo (master.recompensas.*).
 */
#[IsGranted('ROLE_SUPER_ADMIN')]
final class RecompensasDoMasterController extends AbstractController
{
    public const TOKEN = 'master_recompensas';

    public function __construct(
        private readonly ConsultarPainelDeRecompensasUseCase $painel,
        private readonly TranslatorInterface $tradutor,
        private readonly ValidatorInterface $validador,
    ) {
    }

    #[Route('/master/recompensas', name: 'master_recompensas_dados', methods: ['GET'])]
    public function dados(): JsonResponse
    {
        return $this->json($this->painel->executar()->paraArray());
    }

    /** As seis regras da regra geral. Corpo `{ativo, valor ("10,00"), minEscritorios, carenciaDias, alertaQtd, exigePasta}`. */
    #[Route('/master/recompensas/regras', name: 'master_recompensas_regras', methods: ['POST'])]
    public function regras(Request $request, AlterarRegrasDoProgramaUseCase $alterar): JsonResponse
    {
        return $this->mutar($request, function () use ($request, $alterar): void {
            $input = AlterarRegrasDoProgramaInput::doJson($this->ator(), self::corpo($request) ?? []);
            $this->exigirValido($input);
            $alterar->executar($input);
        });
    }

    /** Condição individual do indicador. Corpo `{valor, minEscritorios, carenciaDias, recorrente, mensal, motivo}`. */
    #[Route('/master/recompensas/usuarios/{id}/condicao', name: 'master_recompensas_condicao', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function definirCondicao(int $id, Request $request, DefinirCondicaoIndividualUseCase $definir): JsonResponse
    {
        return $this->mutar($request, function () use ($id, $request, $definir): void {
            $input = DefinirCondicaoIndividualInput::doJson($this->ator(), $id, self::corpo($request) ?? throw new RecompensaRecusada($this->tradutor->trans('master.recompensas.corpo_invalido')));
            $this->exigirValido($input);
            $definir->executar($input);
        });
    }

    /** "Voltar à regra geral". */
    #[Route('/master/recompensas/usuarios/{id}/condicao', name: 'master_recompensas_condicao_remover', requirements: ['id' => '\d+'], methods: ['DELETE'])]
    public function removerCondicao(int $id, Request $request, RemoverCondicaoIndividualUseCase $remover): JsonResponse
    {
        return $this->mutar($request, fn () => $remover->executar(new RemoverCondicaoIndividualInput($this->ator(), $id)));
    }

    /** CSRF, a mudança e a resposta com o estado novo; as recusas viram 404 e 422. */
    private function mutar(Request $request, \Closure $mudanca): JsonResponse
    {
        if (!$this->isCsrfTokenValid(self::TOKEN, (string) $request->headers->get('X-CSRF-Token', ''))) {
            return $this->json(['erro' => $this->tradutor->trans('master.recompensas.token_invalido')], Response::HTTP_FORBIDDEN);
        }

        try {
            $mudanca();
        } catch (IndicadorNaoEncontrado) {
            return $this->json(['erro' => $this->tradutor->trans('master.recompensas.indicador_nao_encontrado')], Response::HTTP_NOT_FOUND);
        } catch (RecompensaRecusada $e) {
            return $this->json(['erro' => $e->getMessage()], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return $this->json($this->painel->executar()->paraArray());
    }

    /** @throws RecompensaRecusada a primeira violação do #[Assert] do DTO */
    private function exigirValido(object $input): void
    {
        $violacoes = $this->validador->validate($input);
        if (count($violacoes) > 0) {
            throw new RecompensaRecusada((string) $violacoes->get(0)->getMessage());
        }
    }

    private function ator(): ?User
    {
        $ator = $this->getUser();

        return $ator instanceof User ? $ator : null;
    }

    /** @return array<mixed>|null nulo quando o corpo não é um JSON de objeto */
    private static function corpo(Request $request): ?array
    {
        try {
            $dados = json_decode($request->getContent(), true, 8, \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }

        return is_array($dados) ? $dados : null;
    }
}
