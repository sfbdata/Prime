<?php

declare(strict_types=1);

namespace App\TrabalhoExterno\Controller;

use App\Shared\Trait\ValidaCsrfAjaxTrait;
use App\TrabalhoExterno\Exception\DadosInvalidos;
use App\TrabalhoExterno\Service\FabricaDeContexto;
use App\TrabalhoExterno\Service\PreviaDePerfil;
use App\TrabalhoExterno\UseCase\DefinirAlertaDeVagasUseCase;
use App\TrabalhoExterno\UseCase\MontarEngajamentoUseCase;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

/**
 * A visão de engajamento (spec VS 18.10.26 §4.7): o banner, os selos, o lembrete, as boas-vindas e o
 * aviso de vaga nova. A leitura aceita `?como=` da prévia do Master; a escrita, nunca, e tem CSRF no
 * header `X-CSRF-Token`.
 */
#[Route('/trabalho-externo/api')]
final class EngajamentoController extends AbstractController
{
    use RespondeJsonTrait;
    use ValidaCsrfAjaxTrait;

    public function __construct(
        private readonly FabricaDeContexto $contextos,
        private readonly PreviaDePerfil $previa,
    ) {
    }

    /**
     * `?origem=link[&vagas=VG-1,VG-2][&cadastro=1]` (boas-vindas pelo link, l.1078: sem `vagas`, o texto de
     * "já foram preenchidas") e `?ofertas=VG-3` (situação das ofertas acompanhadas).
     */
    #[Route('/engajamento', name: 'trabalho_externo_engajamento', methods: ['GET'])]
    public function engajamento(Request $request, MontarEngajamentoUseCase $montar): JsonResponse
    {
        $ctx = $this->contextoOuNulo();
        if ($ctx === null) {
            return $this->semAcesso();
        }
        $como = $this->previa->perfilPedido($ctx, $request->query->get('como'));
        $ctx = $como !== null ? $this->previa->contexto($ctx, $como) : $ctx;
        $q = $request->query;
        $indicadas = $q->get('origem') === 'link' ? self::codigos($q->get('vagas')) : null;

        return $this->responder(static fn (): array => $montar->executar($ctx, $indicadas, $q->get('cadastro') === '1', self::codigos($q->get('ofertas'))));
    }

    #[Route('/vagas/alerta', name: 'trabalho_externo_vagas_alerta', methods: ['POST'])]
    public function alerta(Request $request, DefinirAlertaDeVagasUseCase $definir): JsonResponse
    {
        $ctx = $this->contextoOuNulo();
        if ($ctx === null) {
            return $this->semAcesso();
        }
        $this->validarCsrfAjax($request);

        return $this->responder(function () use ($request, $ctx, $definir): array {
            $ligado = $this->corpo($request)['ligado'] ?? null;

            return $definir->executar($ctx, is_bool($ligado) ? $ligado : throw new DadosInvalidos('Informe se o aviso fica ligado.'));
        });
    }

    /** @return list<string> os códigos `VG-n` da lista separada por vírgula; o resto é ignorado */
    private static function codigos(mixed $lista): array
    {
        $codigos = [];
        foreach (explode(',', is_string($lista) ? $lista : '') as $codigo) {
            $codigo = trim($codigo);
            if (preg_match('/^VG-\d{1,9}$/', $codigo) === 1) {
                $codigos[] = $codigo;
            }
        }

        return array_values(array_unique($codigos));
    }
}
