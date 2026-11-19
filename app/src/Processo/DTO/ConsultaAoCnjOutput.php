<?php

declare(strict_types=1);

namespace App\Processo\DTO;

use App\Processo\Enum\DesfechoDaConsultaAoCnj;
use App\Processo\Enum\MotivoFalhaDatajud;

/**
 * O resultado da consulta ao Datajud ({@see \App\Processo\UseCase\ConsultarProcessoNoCnjUseCase}), sem Response nem
 * sessão. Achou: {@see $dados} (os campos já mapeados, no formato da tela). Os demais desfechos: {@see $mensagem}
 * (texto amigável para a pessoa) e {@see $statusHttp} (o que a tela devolve hoje para o caso). Na falha vinda do
 * CNJ, {@see $motivo} diz a causa.
 */
final readonly class ConsultaAoCnjOutput
{
    /** @param array<string, mixed>|null $dados */
    private function __construct(
        public DesfechoDaConsultaAoCnj $desfecho,
        public ?array $dados,
        public ?string $mensagem,
        public int $statusHttp,
        public ?MotivoFalhaDatajud $motivo = null,
    ) {
    }

    /** @param array<string, mixed> $dados */
    public static function achou(array $dados): self
    {
        return new self(DesfechoDaConsultaAoCnj::Achou, $dados, null, 200);
    }

    public static function naoEncontrado(string $mensagem): self
    {
        return new self(DesfechoDaConsultaAoCnj::NaoEncontrado, null, $mensagem, 404);
    }

    public static function numeroInvalido(string $mensagem, int $statusHttp): self
    {
        return new self(DesfechoDaConsultaAoCnj::NumeroInvalido, null, $mensagem, $statusHttp);
    }

    public static function falha(string $mensagem, int $statusHttp, ?MotivoFalhaDatajud $motivo = null): self
    {
        return new self(DesfechoDaConsultaAoCnj::Falha, null, $mensagem, $statusHttp, $motivo);
    }

    public function achouOProcesso(): bool
    {
        return $this->desfecho === DesfechoDaConsultaAoCnj::Achou;
    }
}
