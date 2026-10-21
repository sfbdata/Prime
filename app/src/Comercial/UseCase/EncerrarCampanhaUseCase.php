<?php

declare(strict_types=1);

namespace App\Comercial\UseCase;

use App\Comercial\Exception\CampanhaRecusada;
use App\Comercial\Repository\CampanhaDeEmailRepository;
use App\Comercial\Repository\EnvioDeEmailRepository;
use Psr\Clock\ClockInterface;

/**
 * O freio sem deploy (spec §8.1.5): encerra uma campanha ativa (`cancelada`, com o motivo), e o expansor para no lote
 * seguinte; o que já estava na fila do Carteiro também não sai (o `EnviarEmailHandler` marca `cancelado`). Também
 * alcança a campanha já `enviada` que ainda tem envios `enfileirado` ou `enviando`: ela vira `cancelada` e esses
 * envios viram `cancelado` aqui mesmo (o handler só reivindica `enfileirado`). Os parâmetros do público somem. A trilha
 * manual (`camp_fim`, `{publico, frequencia, total}`) vai na mesma transação, sem autor (o console não tem sessão). É o
 * freio enquanto "Pausar" e "Encerrar" na tela esperam o dono (A3).
 */
final class EncerrarCampanhaUseCase
{
    public const MOTIVO = 'Encerrada pelo console';
    public const NAO_ATIVA = 'Campanha não encontrada ou já encerrada.';

    public function __construct(
        private readonly CampanhaDeEmailRepository $campanhas,
        private readonly EnvioDeEmailRepository $envios,
        private readonly ClockInterface $relogio,
    ) {
    }

    public function executar(int $id): string
    {
        $agora = $this->relogio->now();
        $naFila = $this->campanhas->emTransacao(function () use ($id, $agora): ?int {
            $changes = $this->campanhas->encerrar($id, self::MOTIVO) ?? $this->campanhas->encerrarEnviadaComFila($id, self::MOTIVO);
            if ($changes === null) {
                return null;
            }
            $this->campanhas->auditar($id, CampanhaDeEmailRepository::ENCERRADA, $changes, null, null, $agora);

            return $this->envios->cancelarNaFilaDaOrigem('campanha:' . $id);
        });
        if ($naFila === null) {
            throw new CampanhaRecusada(self::NAO_ATIVA);
        }

        return 'Campanha ' . $id . ' encerrada. ' . $naFila . ' e-mail(s) que estavam na fila foram cancelados.';
    }
}
