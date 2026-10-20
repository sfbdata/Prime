<?php

declare(strict_types=1);

namespace App\Comercial\Controller;

use App\Comercial\DTO\MensagemDaCampanha;
use App\Comercial\DTO\ModeloSalvoOutput;
use App\Comercial\Exception\CampanhaRecusada;
use App\Comercial\UseCase\CriarModeloDeCampanhaUseCase;
use App\Comercial\UseCase\DuplicarModeloDeCampanhaUseCase;
use App\Comercial\UseCase\EnviarTesteDaCampanhaUseCase;
use App\Comercial\UseCase\ExcluirModeloDeCampanhaUseCase;
use App\Comercial\UseCase\ImportarModeloHtmlUseCase;
use App\Comercial\UseCase\ListarModelosDeCampanhaUseCase;
use App\Comercial\UseCase\PreviaDaCampanhaUseCase;
use App\Comercial\UseCase\SalvarModeloDeCampanhaUseCase;
use App\Entity\Auth\User;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * O bloco "Nova campanha de e-mail" da seção "Comercial e e-mails" do /master, fatia 2a (desenho 05 L.229-260;
 * spec comercial-e-emails.md §8.2 a §8.4 e §8.6): modelos, prévia e "Enviar teste". Nada sai para mais de uma pessoa.
 * Só o Master. JSON, com o token CSRF `master_comercial` no header `X-CSRF-Token` (o padrão da 1a); recusa em 422
 * com `{erro}`.
 */
#[IsGranted('ROLE_SUPER_ADMIN')]
final class CampanhaDoMasterController extends AbstractController
{
    private const CSRF_INVALIDO = 'Token CSRF inválido. Recarregue a página.';

    #[Route('/master/comercial/modelos', name: 'master_comercial_modelos', methods: ['GET'])]
    public function modelos(ListarModelosDeCampanhaUseCase $listar): JsonResponse
    {
        return $this->json(['modelos' => $listar->executar()]);
    }

    /** "Novo modelo", "Duplicar" e "Importar HTML" (dc L.549, L.550, L.553), pelo campo `acao`. */
    #[Route('/master/comercial/modelos', name: 'master_comercial_modelo_criar', methods: ['POST'])]
    public function criar(Request $request, CriarModeloDeCampanhaUseCase $novo, DuplicarModeloDeCampanhaUseCase $duplicar, ImportarModeloHtmlUseCase $importar): JsonResponse
    {
        return $this->protegido($request, function (array $corpo) use ($novo, $duplicar, $importar): JsonResponse {
            $saida = match ($corpo['acao'] ?? null) {
                'novo' => $novo->executar($this->master()),
                'duplicar' => $duplicar->executar(MensagemDaCampanha::deArray(self::objeto($corpo['modelo'] ?? null)), $this->master()),
                'importar' => $importar->executar(self::texto($corpo['arquivo'] ?? ''), self::texto($corpo['html'] ?? ''), $this->master()),
                default => throw new CampanhaRecusada('Ação desconhecida.'),
            };

            return self::salvo($saida);
        });
    }

    #[Route('/master/comercial/modelos/{id}', name: 'master_comercial_modelo_salvar', requirements: ['id' => '[a-z0-9]{1,20}'], methods: ['POST'])]
    public function salvar(string $id, Request $request, SalvarModeloDeCampanhaUseCase $salvar): JsonResponse
    {
        return $this->protegido($request, fn (array $corpo): JsonResponse => self::salvo($salvar->executar($id, MensagemDaCampanha::deArray($corpo), $this->master())));
    }

    #[Route('/master/comercial/modelos/{id}/excluir', name: 'master_comercial_modelo_excluir', requirements: ['id' => '[a-z0-9]{1,20}'], methods: ['POST'])]
    public function excluir(string $id, Request $request, ExcluirModeloDeCampanhaUseCase $excluir): JsonResponse
    {
        return $this->protegido($request, function () use ($id, $excluir): JsonResponse {
            $excluir->executar($id, $this->master());

            return new JsonResponse(['ok' => true]);
        });
    }

    /**
     * A prévia em JSON `{html}`: a tela põe o HTML no `srcdoc` de um iframe `sandbox=""` (sem scripts nem a mesma
     * origem). A resposta leva também `Content-Security-Policy: sandbox`, para o caso de alguém abri-la direto.
     */
    #[Route('/master/comercial/previa-campanha', name: 'master_comercial_previa_campanha', methods: ['POST'])]
    public function previa(Request $request, PreviaDaCampanhaUseCase $previa): JsonResponse
    {
        $resposta = $this->protegido($request, fn (array $corpo): JsonResponse => new JsonResponse(['html' => $previa->executar(MensagemDaCampanha::deArray($corpo))]));
        $resposta->headers->set('Content-Security-Policy', 'sandbox');

        return $resposta;
    }

    #[Route('/master/comercial/teste', name: 'master_comercial_teste', methods: ['POST'])]
    public function teste(Request $request, EnviarTesteDaCampanhaUseCase $teste): JsonResponse
    {
        return $this->protegido($request, fn (array $corpo): JsonResponse => new JsonResponse(['mensagem' => $teste->executar(MensagemDaCampanha::deArray($corpo), $this->master())]));
    }

    /**
     * CSRF no header, corpo JSON em objeto e a recusa do caso de uso em 422 com `{erro}`.
     *
     * @param \Closure(array<mixed>): JsonResponse $trabalho
     */
    private function protegido(Request $request, \Closure $trabalho): JsonResponse
    {
        if (!$this->isCsrfTokenValid(ComercialDoMasterController::TOKEN, (string) $request->headers->get('X-CSRF-Token', ''))) {
            return new JsonResponse(['erro' => self::CSRF_INVALIDO], Response::HTTP_FORBIDDEN);
        }
        try {
            return $trabalho(self::objeto(json_decode($request->getContent(), true)));
        } catch (CampanhaRecusada $e) {
            return new JsonResponse(['erro' => $e->getMessage()], Response::HTTP_UNPROCESSABLE_ENTITY);
        }
    }

    private function master(): User
    {
        $usuario = $this->getUser();

        return $usuario instanceof User ? $usuario : throw new CampanhaRecusada('Sessão inválida.');
    }

    /** @return array<mixed> */
    private static function objeto(mixed $valor): array
    {
        return \is_array($valor) ? $valor : throw new CampanhaRecusada('Pedido inválido.');
    }

    private static function texto(mixed $valor): string
    {
        return \is_string($valor) ? $valor : throw new CampanhaRecusada('Pedido inválido.');
    }

    private static function salvo(ModeloSalvoOutput $saida): JsonResponse
    {
        return new JsonResponse(['modelo' => $saida->modelo, 'mensagem' => $saida->mensagem]);
    }
}
