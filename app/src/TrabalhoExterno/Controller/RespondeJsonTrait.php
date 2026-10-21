<?php

declare(strict_types=1);

namespace App\TrabalhoExterno\Controller;

use App\TrabalhoExterno\Exception\AcaoNaoPermitida;
use App\TrabalhoExterno\Exception\CandidaturaRecusada;
use App\TrabalhoExterno\Exception\DadosInvalidos;
use App\TrabalhoExterno\Exception\DemandaNaoEncontrada;
use App\TrabalhoExterno\Exception\SemCurriculo;
use App\TrabalhoExterno\Exception\TransicaoInvalida;
use App\TrabalhoExterno\Service\Contexto;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * Traduz as exceções do domínio em respostas JSON com a mensagem para o usuário:
 * não encontrada → 404 · ação do perfil errado → 403 · fora do estado → 409 · entrada → 422.
 * Na candidatura (spec VS 18.10.26 §4.6): sem currículo → 409 `semCurriculo`; recusa → 422 com o
 * título do toast e `quase` (o modal do desenho, l.392).
 */
trait RespondeJsonTrait
{
    /** @param callable(): array<string, mixed> $acao */
    private function responder(callable $acao): JsonResponse
    {
        try {
            return $this->json(['ok' => true] + $acao());
        } catch (SemCurriculo) {
            return $this->json(['ok' => false, 'semCurriculo' => true], 409);
        } catch (CandidaturaRecusada $e) {
            return $this->json(['ok' => false, 'titulo' => $e->titulo, 'mensagem' => $e->getMessage(), 'quase' => $e->quase] + ($e->curriculoSalvo ? ['curriculoSalvo' => true] : []), 422);
        } catch (DemandaNaoEncontrada $e) {
            return $this->json(['ok' => false, 'mensagem' => $e->getMessage()], 404);
        } catch (AcaoNaoPermitida $e) {
            return $this->json(['ok' => false, 'mensagem' => $e->getMessage()], 403);
        } catch (TransicaoInvalida $e) {
            return $this->json(['ok' => false, 'mensagem' => $e->getMessage()], 409);
        } catch (DadosInvalidos $e) {
            return $this->json(['ok' => false, 'mensagem' => $e->getMessage()], 422);
        }
    }

    private function semAcesso(): JsonResponse
    {
        return $this->json(['ok' => false, 'mensagem' => 'Você não tem acesso ao Trabalho Externo.'], 403);
    }

    /** @return array<string, mixed> */
    private function corpo(Request $request): array
    {
        $dados = json_decode($request->getContent() ?: '[]', true);

        return is_array($dados) ? $dados : [];
    }

    private function contextoOuNulo(): ?Contexto
    {
        return $this->contextos->para($this->getUser());
    }
}
