<?php

declare(strict_types=1);

namespace App\Djen\DTO;

use App\Djen\Enum\MotivoFalhaDjen;

/**
 * Acumulador do resultado de uma sincronização com o DJEN (por escritório, ou somado no comando).
 */
final class ResultadoSincronizacao
{
    private int $recebidas = 0;
    private int $novas = 0;
    private int $vinculadas = 0;
    private int $notificados = 0;

    /** @var array<string, string> rótulo da OAB => motivo (valor do enum) */
    private array $falhas = [];

    /** Houve alguma falha NÃO transitória (config/resposta inválida) — re-tentar não resolve. */
    private bool $falhaPermanente = false;

    public function registrarRecebidas(int $quantidade): void
    {
        $this->recebidas += $quantidade;
    }

    public function registrarNova(bool $vinculada): void
    {
        $this->novas++;

        if ($vinculada) {
            $this->vinculadas++;
        }
    }

    public function definirNotificados(int $quantidade): void
    {
        $this->notificados = $quantidade;
    }

    public function registrarFalha(string $rotuloOab, MotivoFalhaDjen $motivo): void
    {
        $this->falhas[$rotuloOab] = $motivo->value;

        if (!$motivo->ehTransitorio()) {
            $this->falhaPermanente = true;
        }
    }

    /** Soma outro resultado neste (usado pelo comando para o total entre escritórios). */
    public function somar(self $outro): void
    {
        $this->recebidas += $outro->recebidas;
        $this->novas += $outro->novas;
        $this->vinculadas += $outro->vinculadas;
        $this->notificados += $outro->notificados;

        foreach ($outro->falhas as $rotulo => $motivo) {
            $this->falhas[$rotulo] = $motivo;
        }

        $this->falhaPermanente = $this->falhaPermanente || $outro->falhaPermanente;
    }

    public function getRecebidas(): int
    {
        return $this->recebidas;
    }

    public function getNovas(): int
    {
        return $this->novas;
    }

    public function getVinculadas(): int
    {
        return $this->vinculadas;
    }

    public function getNotificados(): int
    {
        return $this->notificados;
    }

    public function getAvulsas(): int
    {
        return $this->novas - $this->vinculadas;
    }

    /** @return array<string, string> */
    public function getFalhas(): array
    {
        return $this->falhas;
    }

    public function temFalhas(): bool
    {
        return $this->falhas !== [];
    }

    /**
     * True se alguma falha foi NÃO transitória (ex.: DJEN_BASE_URL ausente, resposta inválida).
     * Falhas transitórias (429/sobrecarga/timeout) não contam — são recuperadas na próxima execução.
     */
    public function temFalhaPermanente(): bool
    {
        return $this->falhaPermanente;
    }

    public function resumo(): string
    {
        return sprintf(
            '%d nova(s) publicação(ões) (%d vinculada(s), %d avulsa(s)) de %d recebida(s); %d notificação(ões).',
            $this->novas,
            $this->vinculadas,
            $this->getAvulsas(),
            $this->recebidas,
            $this->notificados,
        );
    }
}
