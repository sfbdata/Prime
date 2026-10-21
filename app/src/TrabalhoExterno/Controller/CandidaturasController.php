<?php

declare(strict_types=1);

namespace App\TrabalhoExterno\Controller;

use App\Shared\Trait\ValidaCsrfAjaxTrait;
use App\TrabalhoExterno\Enum\OperacaoDeMarcacao;
use App\TrabalhoExterno\Enum\StatusCandidatura;
use App\TrabalhoExterno\Exception\DadosInvalidos;
use App\TrabalhoExterno\Service\Contexto;
use App\TrabalhoExterno\Service\DetalheDaVaga;
use App\TrabalhoExterno\Service\FabricaDeContexto;
use App\TrabalhoExterno\UseCase\CandidatarOuAceitarUseCase;
use App\TrabalhoExterno\UseCase\EnviarCandidatosAoEscritorioUseCase;
use App\TrabalhoExterno\UseCase\MarcarCandidaturasEmLoteUseCase;
use App\TrabalhoExterno\UseCase\MudarStatusDaCandidaturaUseCase;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

/**
 * A candidatura (spec VS 18.10.26 §4.6), toda em JSON e só escrita: CSRF no header `X-CSRF-Token`,
 * nunca pela prévia do Master (a escrita não lê `?como=`). As respostas trazem a vaga atualizada no
 * recorte de quem pediu.
 */
#[Route('/trabalho-externo/api')]
final class CandidaturasController extends AbstractController
{
    use RespondeJsonTrait;
    use ValidaCsrfAjaxTrait;

    private const CODIGO = 'VG-\d{1,9}';

    public function __construct(
        private readonly FabricaDeContexto $contextos,
        private readonly DetalheDaVaga $detalhe,
    ) {
    }

    /** "Candidatar-se" ou "Aceitar oferta": o servidor decide pela oferta. */
    #[Route('/vagas/{codigo}/candidatura', name: 'trabalho_externo_vaga_candidatura', methods: ['POST'], requirements: ['codigo' => self::CODIGO])]
    public function candidatar(Request $request, string $codigo, CandidatarOuAceitarUseCase $candidatar): JsonResponse
    {
        return $this->escrever($request, function (Contexto $ctx) use ($codigo, $candidatar): array {
            $resultado = $candidatar->executar($ctx, $codigo);

            return ['vaga' => $this->detalhe->detalhar($ctx, $codigo), 'toast' => $resultado->toast()];
        });
    }

    /** "Enviar N ao escritório" com `{candidaturas: [id]}`. */
    #[Route('/vagas/{codigo}/enviar', name: 'trabalho_externo_vaga_enviar', methods: ['POST'], requirements: ['codigo' => self::CODIGO])]
    public function enviar(Request $request, string $codigo, EnviarCandidatosAoEscritorioUseCase $enviar): JsonResponse
    {
        return $this->escrever($request, function (Contexto $ctx) use ($request, $codigo, $enviar): array {
            $toast = $enviar->executar($ctx, $codigo, self::ids($this->corpo($request)['candidaturas'] ?? null));

            return ['vaga' => $this->detalhe->detalhar($ctx, $codigo), 'toast' => $toast];
        });
    }

    #[Route('/candidaturas/{id}/status', name: 'trabalho_externo_candidatura_status', methods: ['POST'], requirements: ['id' => '\d{1,9}'])]
    public function status(Request $request, int $id, MudarStatusDaCandidaturaUseCase $mudar): JsonResponse
    {
        return $this->escrever($request, function (Contexto $ctx) use ($request, $id, $mudar): array {
            $status = StatusCandidatura::tryFrom((string) ($this->corpo($request)['status'] ?? '')) ?? throw new DadosInvalidos('Status desconhecido.');
            $candidatura = $mudar->executar($ctx, $id, $status);

            return ['vaga' => $this->detalhe->detalhar($ctx, $candidatura->getVaga()->getCodigo())];
        });
    }

    /** As marcações de "Minhas candidaturas": `{codigos: [id], op}`, como as vagas. */
    #[Route('/candidaturas/marcacoes', name: 'trabalho_externo_candidaturas_marcacoes', methods: ['POST'])]
    public function marcacoes(Request $request, MarcarCandidaturasEmLoteUseCase $marcar): JsonResponse
    {
        return $this->escrever($request, function (Contexto $ctx) use ($request, $marcar): array {
            $corpo = $this->corpo($request);
            $op = OperacaoDeMarcacao::tryFrom((string) ($corpo['op'] ?? '')) ?? throw new DadosInvalidos('Operação desconhecida.');

            return $marcar->executar($ctx, self::ids($corpo['codigos'] ?? null), $op);
        });
    }

    /** Ids inteiros ou strings numéricas; o resto é ignorado. @return list<int> */
    private static function ids(mixed $lista): array
    {
        $ids = [];
        foreach (is_array($lista) ? $lista : [] as $id) {
            if (is_int($id) || (is_string($id) && ctype_digit($id))) {
                $ids[] = (int) $id;
            }
        }

        return $ids;
    }

    /** @param callable(Contexto): array<string, mixed> $acao */
    private function escrever(Request $request, callable $acao): JsonResponse
    {
        $ctx = $this->contextoOuNulo();
        if ($ctx === null) {
            return $this->semAcesso();
        }
        $this->validarCsrfAjax($request);

        return $this->responder(static fn (): array => $acao($ctx));
    }
}
