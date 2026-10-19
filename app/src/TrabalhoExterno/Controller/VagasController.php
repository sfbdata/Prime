<?php

declare(strict_types=1);

namespace App\TrabalhoExterno\Controller;

use App\Shared\Trait\ValidaCsrfAjaxTrait;
use App\TrabalhoExterno\DTO\DadosDaVaga;
use App\TrabalhoExterno\Enum\AcaoVaga;
use App\TrabalhoExterno\Enum\OperacaoDeMarcacao;
use App\TrabalhoExterno\Enum\TipoItemMarcado;
use App\TrabalhoExterno\Exception\DadosInvalidos;
use App\TrabalhoExterno\Repository\MarcacaoDoUsuarioRepository;
use App\TrabalhoExterno\Service\ApresentadorDeVaga;
use App\TrabalhoExterno\Service\Contexto;
use App\TrabalhoExterno\Service\FabricaDeContexto;
use App\TrabalhoExterno\Service\LocalizadorDeVaga;
use App\TrabalhoExterno\Service\PreviaDePerfil;
use App\TrabalhoExterno\UseCase\AplicarAcaoNaVagaUseCase;
use App\TrabalhoExterno\UseCase\CompartilharVagasUseCase;
use App\TrabalhoExterno\UseCase\ListarVagasUseCase;
use App\TrabalhoExterno\UseCase\MarcarVagasEmLoteUseCase;
use App\TrabalhoExterno\UseCase\SalvarVagaUseCase;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * A aba Vagas (spec VS 18.10.26 §4.1), toda em JSON. Escritas com CSRF no header `X-CSRF-Token`,
 * como o `DemandaExternaController`. Leitura aceita `?como=` da prévia do Master; escrita, nunca.
 */
#[Route('/trabalho-externo/api/vagas')]
final class VagasController extends AbstractController
{
    use RespondeJsonTrait;
    use ValidaCsrfAjaxTrait;

    private const CODIGO = 'VG-\d{1,9}';

    public function __construct(
        private readonly FabricaDeContexto $contextos,
        private readonly PreviaDePerfil $previa,
        private readonly LocalizadorDeVaga $localizador,
        private readonly ApresentadorDeVaga $apresentador,
        private readonly MarcacaoDoUsuarioRepository $marcacoes,
    ) {
    }

    #[Route('', name: 'trabalho_externo_vagas', methods: ['GET'])]
    public function listar(Request $request, ListarVagasUseCase $listar): JsonResponse
    {
        return $this->ler($request, static fn (Contexto $ctx): array => ['vagas' => $listar->executar($ctx)]);
    }

    #[Route('/{codigo}', name: 'trabalho_externo_vaga', methods: ['GET'], requirements: ['codigo' => self::CODIGO])]
    public function detalhe(Request $request, string $codigo): JsonResponse
    {
        return $this->ler($request, fn (Contexto $ctx): array => ['vaga' => $this->detalhar($ctx, $codigo)]);
    }

    #[Route('', name: 'trabalho_externo_vaga_salvar', methods: ['POST'])]
    public function salvar(Request $request, SalvarVagaUseCase $salvar): JsonResponse
    {
        return $this->escrever($request, function (Contexto $ctx) use ($request, $salvar): array {
            $vaga = $salvar->executar($ctx, DadosDaVaga::deArray($this->corpo($request)));

            return ['codigo' => $vaga->getCodigo(), 'vaga' => $this->detalhar($ctx, $vaga->getCodigo())];
        });
    }

    #[Route('/{codigo}/acoes/{acao}', name: 'trabalho_externo_vaga_acao', methods: ['POST'], requirements: ['codigo' => self::CODIGO])]
    public function acao(Request $request, string $codigo, string $acao, AplicarAcaoNaVagaUseCase $aplicar): JsonResponse
    {
        return $this->escrever($request, function (Contexto $ctx) use ($request, $codigo, $acao, $aplicar): array {
            $acaoVaga = AcaoVaga::tryFrom($acao) ?? throw new DadosInvalidos('Ação desconhecida.');
            $motivo = $this->corpo($request)['motivo'] ?? null;
            $aplicar->executar($ctx, $codigo, $acaoVaga, is_string($motivo) ? $motivo : null);

            return ['vaga' => $this->detalhar($ctx, $codigo)];
        });
    }

    #[Route('/marcacoes', name: 'trabalho_externo_vagas_marcacoes', methods: ['POST'])]
    public function marcacoes(Request $request, MarcarVagasEmLoteUseCase $marcar): JsonResponse
    {
        return $this->escrever($request, function (Contexto $ctx) use ($request, $marcar): array {
            $corpo = $this->corpo($request);
            $op = OperacaoDeMarcacao::tryFrom((string) ($corpo['op'] ?? '')) ?? throw new DadosInvalidos('Operação desconhecida.');
            $codigos = is_array($corpo['codigos'] ?? null) ? array_values(array_map('strval', array_filter($corpo['codigos'], 'is_scalar'))) : [];

            return $marcar->executar($ctx, $codigos, $op);
        });
    }

    /** "Compartilhar vagas" (`tmShare`, spec §4.2): o link público com as vagas abertas escolhidas. */
    #[Route('/compartilhar', name: 'trabalho_externo_vagas_compartilhar', methods: ['POST'])]
    public function compartilhar(Request $request, CompartilharVagasUseCase $compartilhar): JsonResponse
    {
        return $this->escrever($request, function (Contexto $ctx) use ($request, $compartilhar): array {
            $corpo = $this->corpo($request);
            $codigos = is_array($corpo['codigos'] ?? null) ? array_values(array_map('strval', array_filter($corpo['codigos'], 'is_scalar'))) : [];
            $link = $compartilhar->executar($ctx, $codigos);

            return [
                'url' => $this->generateUrl('trabalho_externo_vagas_link', ['token' => $link['token']], UrlGeneratorInterface::ABSOLUTE_URL),
                'vagas' => $link['vagas'],
            ];
        });
    }

    /** @return array<string, mixed> */
    private function detalhar(Contexto $ctx, string $codigo): array
    {
        $agora = new \DateTimeImmutable();
        $vaga = $this->localizador->localizar($ctx, $codigo, $agora);
        $id = (int) $vaga->getId();
        $marca = $ctx->previa ? null : ($this->marcacoes->doUsuario($ctx->usuario, TipoItemMarcado::Vaga, [$id])[$id] ?? null);

        return $this->apresentador->detalhe($ctx, $vaga, $marca, $agora);
    }

    /** @param callable(Contexto): array<string, mixed> $acao */
    private function ler(Request $request, callable $acao): JsonResponse
    {
        $ctx = $this->contextoOuNulo();
        if ($ctx === null) {
            return $this->semAcesso();
        }
        $como = $this->previa->perfilPedido($ctx, $request->query->get('como'));
        $ctx = $como !== null ? $this->previa->contexto($ctx, $como) : $ctx;

        return $this->responder(static fn (): array => $acao($ctx));
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
