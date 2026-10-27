<?php

declare(strict_types=1);

namespace App\Recompensa\DTO;

/**
 * Uma linha da lista Indicadores da seção Recompensas. Só o que o desenho mostra do INDICADOR (nome, e-mail,
 * escritório); nada do indicado. Dinheiro em centavos inteiros. Situação em código: o texto é da tela.
 */
final readonly class IndicadorDeRecompensaOutput
{
    public const LIBERADO = 'liberado';
    public const CARENCIA = 'carencia';
    public const ANDAMENTO = 'andamento';

    public function __construct(
        public int $userId,
        public string $nome,
        public string $email,
        public ?string $escritorio,
        public int $envios,
        public int $contas,
        public int $ativos,
        public int $emCarencia,
        public int $minEscritorios,
        public int $valorCentavos,
        public int $saldoCentavos,
        public int $pagoCentavos,
        public string $situacao,
        public ?CondicaoIndividualOutput $condicao,
    ) {
    }

    public function liberado(): bool
    {
        return $this->situacao === self::LIBERADO;
    }

    /** @return array<string, mixed> */
    public function paraArray(): array
    {
        return [
            'userId' => $this->userId, 'nome' => $this->nome, 'email' => $this->email, 'escritorio' => $this->escritorio,
            'envios' => $this->envios, 'contas' => $this->contas, 'ativos' => $this->ativos, 'emCarencia' => $this->emCarencia,
            'minEscritorios' => $this->minEscritorios, 'valorCentavos' => $this->valorCentavos,
            'saldoCentavos' => $this->saldoCentavos, 'pagoCentavos' => $this->pagoCentavos,
            'liberado' => $this->liberado(), 'situacao' => $this->situacao,
            'condicao' => $this->condicao?->paraArray(),
        ];
    }
}
