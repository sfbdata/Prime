<?php

declare(strict_types=1);

namespace App\TrabalhoExterno\UseCase;

use App\Entity\Auth\User;
use App\TrabalhoExterno\Entity\LeituraDemanda;
use App\TrabalhoExterno\Enum\OperacaoDeMarcacao;
use App\TrabalhoExterno\Enum\TipoItemMarcado;
use App\TrabalhoExterno\Repository\LeituraDemandaRepository;
use App\TrabalhoExterno\Repository\MarcacaoDoUsuarioRepository;
use App\TrabalhoExterno\Service\Contexto;
use App\TrabalhoExterno\Service\LocalizadorDeDemanda;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;

/**
 * Abrir a aba Chat marca a conversa como lida até a última mensagem (`marcarLido` do desenho) e
 * desliga a marca manual "não lida" (spec VS 18.10.26 §3.1): ler a conversa é marcá-la como lida.
 */
final class MarcarConversaLidaUseCase
{
    public function __construct(
        private readonly LocalizadorDeDemanda $localizador,
        private readonly LeituraDemandaRepository $leituras,
        private readonly MarcacaoDoUsuarioRepository $marcacoes,
    ) {
    }

    public function executar(Contexto $ctx, string $codigo): void
    {
        [$demanda, $ctx] = $this->localizador->localizar($ctx, $codigo);
        $mensagens = $demanda->getMensagens();
        $ultima = $mensagens === [] ? 0 : (int) end($mensagens)->getId();

        $leitura = $this->leituras->daDemanda($demanda, $ctx->usuario) ?? new LeituraDemanda($demanda, $ctx->usuario);
        $leitura->marcarAte($ultima);
        $this->desligarNaoLida($ctx->usuario, (int) $demanda->getId());
        try {
            $this->leituras->salvar($leitura, flush: true);
        } catch (UniqueConstraintViolationException) {
            // Duas abas abrindo o chat ao mesmo tempo: a outra já gravou a leitura. Idempotente.
        }
    }

    private function desligarNaoLida(User $usuario, int $demandaId): void
    {
        $marca = $this->marcacoes->doUsuario($usuario, TipoItemMarcado::Demanda, [$demandaId])[$demandaId] ?? null;
        if ($marca !== null && $marca->isNaoLida()) {
            $marca->aplicar(OperacaoDeMarcacao::Lida, new \DateTimeImmutable());
            $this->marcacoes->salvar($marca);
        }
    }
}
