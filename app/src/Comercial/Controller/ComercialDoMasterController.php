<?php

declare(strict_types=1);

namespace App\Comercial\Controller;

use App\Comercial\DTO\DisparoOutput;
use App\Comercial\DTO\EstadoDaAutomacao;
use App\Comercial\Enum\Automacao;
use App\Comercial\Envio\MensagemDeEmail;
use App\Comercial\Envio\TipoDeEnvio;
use App\Comercial\Exception\AutomacaoRecusada;
use App\Comercial\Repository\AutomacaoDeEmailRepository;
use App\Comercial\Service\ModelosDeAutomacao;
use App\Comercial\Service\MontadorDeEmail;
use App\Comercial\UseCase\AlterarAutomacaoUseCase;
use App\Comercial\UseCase\ListarAutomacoesUseCase;
use App\Comercial\UseCase\SimularNovoCadastroUseCase;
use App\Entity\Auth\User;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * O bloco "Automações" da seção "Comercial e e-mails" do /master (desenho 05 L.262-268; spec
 * comercial-e-emails.md §3.5). Só o Master. JSON, com o token CSRF `master_comercial` no header `X-CSRF-Token`.
 */
#[IsGranted('ROLE_SUPER_ADMIN')]
final class ComercialDoMasterController extends AbstractController
{
    public const TOKEN = 'master_comercial';

    #[Route('/master/comercial/automacoes', name: 'master_comercial_automacoes', methods: ['GET'])]
    public function listar(ListarAutomacoesUseCase $listar): JsonResponse
    {
        $saida = $listar->executar();

        return $this->json([
            'automacoes' => array_map(self::automacaoEmJson(...), $saida['automacoes']),
            'ultimos' => array_map(static fn (DisparoOutput $d): array => (array) $d, $saida['ultimos']),
        ]);
    }

    #[Route('/master/comercial/automacoes/{chave}', name: 'master_comercial_automacao', requirements: ['chave' => '[a-z0-9]{2,10}'], methods: ['POST'])]
    public function alterar(string $chave, Request $request, AlterarAutomacaoUseCase $alterar): JsonResponse
    {
        if (!$this->isCsrfTokenValid(self::TOKEN, (string) $request->headers->get('X-CSRF-Token', ''))) {
            return $this->json(['erro' => 'Token CSRF inválido. Recarregue a página.'], Response::HTTP_FORBIDDEN);
        }
        $corpo = json_decode($request->getContent(), true);
        if (!is_array($corpo) || !is_bool($corpo['ligada'] ?? null)) {
            return $this->json(['erro' => 'Informe se a automação fica ligada.'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }
        $usuario = $this->getUser();
        try {
            $estado = $alterar->executar($chave, $corpo['ligada'], is_string($corpo['atraso'] ?? null) ? $corpo['atraso'] : null, $usuario instanceof User ? $usuario : throw new AutomacaoRecusada('Sessão inválida.'));
        } catch (AutomacaoRecusada $e) {
            return $this->json(['erro' => $e->getMessage()], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return $this->json(self::automacaoEmJson($estado));
    }

    #[Route('/master/comercial/simular-cadastro', name: 'master_comercial_simular', methods: ['POST'])]
    public function simular(Request $request, SimularNovoCadastroUseCase $simular): JsonResponse
    {
        if (!$this->isCsrfTokenValid(self::TOKEN, (string) $request->headers->get('X-CSRF-Token', ''))) {
            return $this->json(['erro' => 'Token CSRF inválido. Recarregue a página.'], Response::HTTP_FORBIDDEN);
        }

        return $this->json(['mensagem' => $simular->executar()]);
    }

    /**
     * A prévia só de leitura do modelo de uma automação (o botão do modelo, dc L.564), com o nome e o endereço de
     * exemplo do dc (L.537). O `e90` mostra o limite escolhido. Nada é enviado nem gravado.
     */
    #[Route('/master/comercial/automacoes/{chave}/previa', name: 'master_comercial_previa', requirements: ['chave' => '[a-z0-9]{2,10}'], methods: ['GET'])]
    public function previa(string $chave, ModelosDeAutomacao $modelos, MontadorDeEmail $montador, AutomacaoDeEmailRepository $automacoes): Response
    {
        $automacao = Automacao::tryFrom($chave);
        if ($automacao === null) {
            throw $this->createNotFoundException();
        }
        $modelo = $modelos->montar($automacao, 'Dra. Ana', [
            'urlDeConfirmacao' => '#',
            'limite' => (string) ($automacao === Automacao::E90 ? $automacoes->estado($automacao)->atraso : ''),
        ]);
        $ehDica = $automacao === Automacao::Dica;
        $email = $montador->montar(new MensagemDeEmail(
            para: 'teste@bluejus.com.br',
            assunto: $modelo->assunto,
            corpoHtml: $modelo->corpoHtml,
            tipo: $ehDica ? TipoDeEnvio::Lista : TipoDeEnvio::Transacional,
            origem: 'teste',
            lista: $ehDica ? 'comercial_dicas' : null,
            nome: 'Dra. Ana',
            selo: $modelo->selo,
            titulo: $modelo->titulo,
        ), 'previa');

        return new Response((string) $email->getHtmlBody());
    }

    /** @return array<string, mixed> */
    private static function automacaoEmJson(EstadoDaAutomacao $e): array
    {
        $a = $e->automacao;

        return [
            'chave' => $a->value, 'titulo' => $a->titulo(), 'descricao' => $a->descricao(), 'modelo' => $a->modelo(),
            'opcoes' => $a->opcoesDeAtraso(), 'atraso' => $e->atraso, 'ligada' => $e->ligada, 'obrigatoria' => $a->obrigatoria(),
        ];
    }
}
