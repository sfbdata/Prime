<?php

declare(strict_types=1);

namespace App\Comercial\Controller;

use App\Comercial\Campanha\PreparadorDeImagemDaCampanha;
use App\Comercial\DTO\DestinatarioDaCampanha;
use App\Comercial\DTO\MensagemDaCampanha;
use App\Comercial\DTO\ModeloSalvoOutput;
use App\Comercial\DTO\PedidoDeCampanha;
use App\Comercial\DTO\PedidoDePublico;
use App\Comercial\Exception\CampanhaRecusada;
use App\Comercial\UseCase\ConfirmarCampanhaUseCase;
use App\Comercial\UseCase\ContarPublicosUseCase;
use App\Comercial\UseCase\CriarModeloDeCampanhaUseCase;
use App\Comercial\UseCase\DuplicarModeloDeCampanhaUseCase;
use App\Comercial\UseCase\EnviarImagemDaCampanhaUseCase;
use App\Comercial\UseCase\EnviarTesteDaCampanhaUseCase;
use App\Comercial\UseCase\ExcluirModeloDeCampanhaUseCase;
use App\Comercial\UseCase\ImportarModeloHtmlUseCase;
use App\Comercial\UseCase\ListarModelosDeCampanhaUseCase;
use App\Comercial\UseCase\PreviaDaCampanhaUseCase;
use App\Comercial\UseCase\SalvarComoMeuModeloUseCase;
use App\Comercial\UseCase\SalvarModeloDeCampanhaUseCase;
use App\Entity\Auth\User;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * O bloco "Nova campanha de e-mail" da seção "Comercial e e-mails" do /master (desenho 05 L.229-260 e L.282-286;
 * spec comercial-e-emails.md §8.2 a §8.6). Fatia 2a: modelos, prévia e "Enviar teste" (só ao Master logado). Fatia 2b:
 * a contagem dos públicos, o "Enviar campanha" (a campanha nasce confirmada; o expansor entrega em lotes) e a lista
 * de campanhas. Só o Master. JSON, com o token CSRF `master_comercial` no header `X-CSRF-Token` (o padrão da 1a); recusa em 422
 * com `{erro}`.
 */
#[IsGranted('ROLE_SUPER_ADMIN')]
final class CampanhaDoMasterController extends AbstractController
{
    private const CSRF_INVALIDO = 'Token CSRF inválido. Recarregue a página.';
    public const SEM_IMAGEM = 'Escolha uma imagem JPG ou PNG.';

    #[Route('/master/comercial/modelos', name: 'master_comercial_modelos', methods: ['GET'])]
    public function modelos(ListarModelosDeCampanhaUseCase $listar): JsonResponse
    {
        return $this->json(['modelos' => $listar->executar()]);
    }

    /** "Novo modelo", "Duplicar", "Importar HTML" (dc L.549, L.550, L.553) e "Salvar como meu modelo" (dc 21.10 L653), pelo campo `acao`. */
    #[Route('/master/comercial/modelos', name: 'master_comercial_modelo_criar', methods: ['POST'])]
    public function criar(Request $request, CriarModeloDeCampanhaUseCase $novo, DuplicarModeloDeCampanhaUseCase $duplicar, ImportarModeloHtmlUseCase $importar, SalvarComoMeuModeloUseCase $salvarComo): JsonResponse
    {
        return $this->protegido($request, function (array $corpo) use ($novo, $duplicar, $importar, $salvarComo): JsonResponse {
            $saida = match ($corpo['acao'] ?? null) {
                'novo' => $novo->executar($this->master()),
                'duplicar' => $duplicar->executar(MensagemDaCampanha::deArray(self::objeto($corpo['modelo'] ?? null)), $this->master()),
                'importar' => $importar->executar(self::texto($corpo['arquivo'] ?? ''), self::texto($corpo['html'] ?? ''), $this->master()),
                'salvar_como' => $salvarComo->executar(MensagemDaCampanha::deArray(self::objeto($corpo['modelo'] ?? null)), self::texto($corpo['nome'] ?? ''), $this->master()),
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
        $resposta = $this->protegido($request, fn (array $corpo): JsonResponse => new JsonResponse(['html' => $previa->executar(MensagemDaCampanha::deArray($corpo), self::exemplo($corpo['exemplo'] ?? null), \is_string($corpo['nomeDaPrevia'] ?? null) ? $corpo['nomeDaPrevia'] : '')]));
        $resposta->headers->set('Content-Security-Policy', 'sandbox');

        return $resposta;
    }

    #[Route('/master/comercial/teste', name: 'master_comercial_teste', methods: ['POST'])]
    public function teste(Request $request, EnviarTesteDaCampanhaUseCase $teste): JsonResponse
    {
        return $this->protegido($request, fn (array $corpo): JsonResponse => new JsonResponse(['mensagem' => $teste->executar(MensagemDaCampanha::deArray($corpo), $this->master())]));
    }

    /** Os números de "2 · PARA QUEM" e da linha de destinatários (dc L.237, L.252), com o exemplo da prévia. */
    #[Route('/master/comercial/publicos/contagem', name: 'master_comercial_publicos_contagem', methods: ['POST'])]
    public function contagem(Request $request, ContarPublicosUseCase $contar): JsonResponse
    {
        return $this->protegido($request, fn (array $corpo): JsonResponse => new JsonResponse($contar->executar(PedidoDePublico::deArray($corpo))->paraArray()));
    }

    /** "Enviar campanha" (dc L.254, L.560), depois do `confirm` literal na tela. */
    #[Route('/master/comercial/campanhas', name: 'master_comercial_campanha_confirmar', methods: ['POST'])]
    public function confirmar(Request $request, ConfirmarCampanhaUseCase $confirmar): JsonResponse
    {
        return $this->protegido($request, function (array $corpo) use ($confirmar): JsonResponse {
            $saida = $confirmar->executar(PedidoDeCampanha::deArray($corpo), $this->master());

            return new JsonResponse(['id' => $saida->id, 'total' => $saida->total, 'mensagem' => $saida->mensagem]);
        });
    }

    /**
     * "Adicionar imagem" / "Trocar imagem" do modo simples (dc 21.10 L274, L659; spec §12, F3i): multipart com o campo
     * `imagem`, o mesmo CSRF no header. Devolve `{token, url}` (a URL absoluta do endereço público). O arquivo maior que
     * 1 MB é recusado antes de ser lido.
     */
    #[Route('/master/comercial/imagens', name: 'master_comercial_imagem_enviar', methods: ['POST'])]
    public function enviarImagem(Request $request, EnviarImagemDaCampanhaUseCase $enviar): JsonResponse
    {
        if (!$this->isCsrfTokenValid(ComercialDoMasterController::TOKEN, (string) $request->headers->get('X-CSRF-Token', ''))) {
            return new JsonResponse(['erro' => self::CSRF_INVALIDO], Response::HTTP_FORBIDDEN);
        }
        try {
            $arquivo = $request->files->get('imagem');
            if (!$arquivo instanceof UploadedFile || !$arquivo->isValid()) {
                throw new CampanhaRecusada(self::SEM_IMAGEM);
            }
            if ((int) $arquivo->getSize() > PreparadorDeImagemDaCampanha::LIMITE_EM_BYTES) {
                throw new CampanhaRecusada(PreparadorDeImagemDaCampanha::GRANDE_DEMAIS);
            }

            return new JsonResponse($enviar->executar((string) file_get_contents($arquivo->getPathname()), $this->master())->paraArray());
        } catch (CampanhaRecusada $e) {
            return new JsonResponse(['erro' => $e->getMessage()], Response::HTTP_UNPROCESSABLE_ENTITY);
        }
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

    /** O primeiro destinatário que a contagem devolveu, para a prévia (dc L.537); fora do formato, sem exemplo. */
    private static function exemplo(mixed $valor): ?DestinatarioDaCampanha
    {
        if (!\is_array($valor) || !\is_string($valor['email'] ?? null)) {
            return null;
        }
        $exemplo = new DestinatarioDaCampanha($valor['email'], \is_string($valor['nome'] ?? null) ? $valor['nome'] : '');

        return $exemplo->valido() ? $exemplo : null;
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
