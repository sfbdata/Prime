<?php

declare(strict_types=1);

namespace App\TrabalhoExterno\Controller;

use App\Shared\Trait\ValidaCsrfAjaxTrait;
use App\TrabalhoExterno\Service\Contexto;
use App\TrabalhoExterno\Service\FabricaDeContexto;
use App\TrabalhoExterno\UseCase\AlternarContatoWhatsappUseCase;
use App\TrabalhoExterno\UseCase\AlternarFaseDoContatoUseCase;
use App\TrabalhoExterno\UseCase\AvisarFaseAtualUseCase;
use App\TrabalhoExterno\UseCase\ConectarWhatsappUseCase;
use App\TrabalhoExterno\UseCase\DescartarAvisoWhatsappUseCase;
use App\TrabalhoExterno\UseCase\DetalharDemandaUseCase;
use App\TrabalhoExterno\UseCase\MarcarAvisoEnviadoUseCase;
use App\TrabalhoExterno\UseCase\RemoverContatoWhatsappUseCase;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Aba WhatsApp da demanda (F2). Nenhuma rota envia mensagem: o BlueJus prepara o texto e o link
 * `wa.me`, e quem envia é a pessoa, pelo próprio WhatsApp (D4). Mesmas guardas das demais escritas.
 */
#[Route('/trabalho-externo/api/demandas/{codigo}/whatsapp', requirements: ['codigo' => 'TE-\d{1,9}'])]
final class WhatsappDaDemandaController extends AbstractController
{
    use RespondeJsonTrait;
    use ValidaCsrfAjaxTrait;

    public function __construct(
        private readonly FabricaDeContexto $contextos,
        private readonly DetalharDemandaUseCase $detalhar,
    ) {
    }

    #[Route('/contatos', name: 'trabalho_externo_whatsapp_conectar', methods: ['POST'])]
    public function conectar(Request $request, string $codigo, ConectarWhatsappUseCase $conectar): JsonResponse
    {
        return $this->escrever($request, $codigo, function (Contexto $ctx) use ($request, $codigo, $conectar): void {
            $corpo = $this->corpo($request);
            $conectar->executar($ctx, $codigo, (string) ($corpo['nome'] ?? ''), (string) ($corpo['telefone'] ?? ''), (bool) ($corpo['autorizou'] ?? false));
        });
    }

    #[Route('/contatos/{contato}/alternar', name: 'trabalho_externo_whatsapp_alternar', methods: ['POST'], requirements: ['contato' => '\d+'])]
    public function alternar(Request $request, string $codigo, int $contato, AlternarContatoWhatsappUseCase $alternar): JsonResponse
    {
        return $this->escrever($request, $codigo, static fn (Contexto $ctx) => $alternar->executar($ctx, $codigo, $contato));
    }

    #[Route('/contatos/{contato}/fases/{fase}', name: 'trabalho_externo_whatsapp_fase', methods: ['POST'], requirements: ['contato' => '\d+', 'fase' => '[a-z]+'])]
    public function fase(Request $request, string $codigo, int $contato, string $fase, AlternarFaseDoContatoUseCase $alternar): JsonResponse
    {
        return $this->escrever($request, $codigo, static fn (Contexto $ctx) => $alternar->executar($ctx, $codigo, $contato, $fase));
    }

    #[Route('/contatos/{contato}/remover', name: 'trabalho_externo_whatsapp_remover', methods: ['POST'], requirements: ['contato' => '\d+'])]
    public function remover(Request $request, string $codigo, int $contato, RemoverContatoWhatsappUseCase $remover): JsonResponse
    {
        return $this->escrever($request, $codigo, static fn (Contexto $ctx) => $remover->executar($ctx, $codigo, $contato));
    }

    #[Route('/avisar-fase-atual', name: 'trabalho_externo_whatsapp_avisar', methods: ['POST'])]
    public function avisar(Request $request, string $codigo, AvisarFaseAtualUseCase $avisar): JsonResponse
    {
        return $this->escrever($request, $codigo, static fn (Contexto $ctx) => $avisar->executar($ctx, $codigo));
    }

    #[Route('/avisos/{aviso}/enviado', name: 'trabalho_externo_whatsapp_enviado', methods: ['POST'], requirements: ['aviso' => '\d+'])]
    public function enviado(Request $request, string $codigo, int $aviso, MarcarAvisoEnviadoUseCase $marcar): JsonResponse
    {
        return $this->escrever($request, $codigo, static fn (Contexto $ctx) => $marcar->executar($ctx, $codigo, $aviso));
    }

    #[Route('/avisos/{aviso}/descartar', name: 'trabalho_externo_whatsapp_descartar', methods: ['POST'], requirements: ['aviso' => '\d+'])]
    public function descartar(Request $request, string $codigo, int $aviso, DescartarAvisoWhatsappUseCase $descartar): JsonResponse
    {
        return $this->escrever($request, $codigo, static fn (Contexto $ctx) => $descartar->executar($ctx, $codigo, $aviso));
    }

    /** @param callable(Contexto): mixed $acao */
    private function escrever(Request $request, string $codigo, callable $acao): JsonResponse
    {
        $ctx = $this->contextoOuNulo();
        if ($ctx === null) {
            return $this->semAcesso();
        }
        $this->validarCsrfAjax($request);

        return $this->responder(function () use ($ctx, $codigo, $acao): array {
            $acao($ctx);

            return ['demanda' => $this->detalhar->executar($ctx, $codigo)];
        });
    }
}
