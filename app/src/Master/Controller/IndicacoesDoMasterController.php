<?php

declare(strict_types=1);

namespace App\Master\Controller;

use App\Entity\Auth\User;
use App\Indicacao\DTO\AlterarLimitesDeIndicacaoInput;
use App\Indicacao\DTO\LimitesDeIndicacaoOutput;
use App\Indicacao\UseCase\AlterarLimitesDeIndicacaoUseCase;
use App\Indicacao\UseCase\ObterPainelDeIndicacoesUseCase;
use App\Indicacao\UseCase\PausarIndicacoesParaTodosUseCase;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/**
 * Seção "Indicações do BlueJus" do Painel do Master (desenho docs/design/perfis-1.2/vs-21-10-26/05 - Perfis 1.2 -
 * VS 21.10.26.dc.html, marcação L234-242 e lógica L683-689; spec docs/specs/indicar-bluejus.md, contrato com a 5d).
 * Só o Master: ROLE_SUPER_ADMIN na classe (os UseCases conferem de novo, pela GuardaDoMaster).
 *
 * JSON. Os POST levam o token CSRF `master_indicacoes` no header `X-CSRF-Token` (o padrão das outras seções do
 * /master) e respondem o mesmo corpo do GET, já com o estado novo; recusa em 422 com `{erro}`.
 * O desenho de 21/10 tirou a tabela por pessoa e a lista de alertas: o corpo leva só o cabeçalho e os limites.
 */
#[IsGranted('ROLE_SUPER_ADMIN')]
final class IndicacoesDoMasterController extends AbstractController
{
    public const TOKEN = 'master_indicacoes';
    private const CSRF_INVALIDO = 'Token CSRF inválido. Recarregue a página.';

    public function __construct(private readonly ObterPainelDeIndicacoesUseCase $painel)
    {
    }

    #[Route('/master/indicacoes', name: 'master_indicacoes_dados', methods: ['GET'])]
    public function dados(): JsonResponse
    {
        return $this->json($this->resumo());
    }

    /** A chave "Convites liberados" / "Convites pausados para todos" (dc L236). Corpo `{pausar: bool}`. */
    #[Route('/master/indicacoes/pausa', name: 'master_indicacoes_pausa', methods: ['POST'])]
    public function pausa(Request $request, PausarIndicacoesParaTodosUseCase $pausar): JsonResponse
    {
        if (!$this->tokenValido($request)) {
            return $this->json(['erro' => self::CSRF_INVALIDO], Response::HTTP_FORBIDDEN);
        }
        $corpo = self::corpo($request);
        if (!is_bool($corpo['pausar'] ?? null)) {
            return $this->json(['erro' => 'Informe se os convites ficam pausados ou liberados.'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }
        $pausar->executar($corpo['pausar'], $this->master());

        return $this->json($this->resumo());
    }

    /** Os três limites (dc L237). Corpo `{porDia, porMes, alertaEm24h}`, inteiros de pelo menos 1. */
    #[Route('/master/indicacoes/limites', name: 'master_indicacoes_limites', methods: ['POST'])]
    public function limites(Request $request, AlterarLimitesDeIndicacaoUseCase $alterar, ValidatorInterface $validador): JsonResponse
    {
        if (!$this->tokenValido($request)) {
            return $this->json(['erro' => self::CSRF_INVALIDO], Response::HTTP_FORBIDDEN);
        }
        $corpo = self::corpo($request);
        $valores = array_map(self::inteiro(...), [$corpo['porDia'] ?? null, $corpo['porMes'] ?? null, $corpo['alertaEm24h'] ?? null]);
        if (in_array(null, $valores, true)) {
            return $this->json(['erro' => 'Informe os limites em números inteiros.'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }
        /** @var array{int, int, int} $valores */
        $input = new AlterarLimitesDeIndicacaoInput(...$valores);
        $violacoes = $validador->validate($input);
        if (count($violacoes) > 0) {
            return $this->json(['erro' => (string) $violacoes->get(0)->getMessage()], Response::HTTP_UNPROCESSABLE_ENTITY);
        }
        try {
            $alterar->executar($input, $this->master());
        } catch (\InvalidArgumentException $e) {
            return $this->json(['erro' => $e->getMessage()], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return $this->json($this->resumo());
    }

    /**
     * O cabeçalho "N enviadas · N hoje · N viraram conta" e os limites (dc L686-687). A página de indicadores vem com
     * uma linha só: o UseCase sempre a monta, e a tela de 21/10 não mostra a tabela.
     *
     * @return array{enviadas: int, hoje: int, viraramConta: int, limites: array{pausadoParaTodos: bool, porDia: int, porMes: int, alertaEm24h: int}}
     */
    private function resumo(): array
    {
        $saida = $this->painel->executar(1, 1);

        return ['enviadas' => $saida->enviadas, 'hoje' => $saida->ultimas24h, 'viraramConta' => $saida->viraramConta, 'limites' => self::limitesEmJson($saida->limites)];
    }

    /** @return array{pausadoParaTodos: bool, porDia: int, porMes: int, alertaEm24h: int} */
    private static function limitesEmJson(LimitesDeIndicacaoOutput $l): array
    {
        return ['pausadoParaTodos' => $l->pausadoParaTodos, 'porDia' => $l->porDia, 'porMes' => $l->porMes, 'alertaEm24h' => $l->alertaEm24h];
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

    /** Inteiro do JSON, ou texto só com dígitos (o valor do campo numérico); o resto é recusado. */
    private static function inteiro(mixed $valor): ?int
    {
        if (is_int($valor)) {
            return $valor;
        }
        if (is_string($valor) && preg_match('/^-?\d{1,9}$/', trim($valor)) === 1) {
            return (int) trim($valor);
        }

        return null;
    }
}
