<?php

declare(strict_types=1);

namespace App\Recompensa\DTO;

/**
 * Uma linha da lista Indicadores da seção Recompensas. Só o que o desenho mostra do INDICADOR (nome, e-mail,
 * escritório); nada do indicado: do antifraude saem só contagens e motivos agregados. Dinheiro em centavos inteiros.
 * Situação em código: o texto é da tela. `liberado` é "ativas >= libera com" e independe da situação mostrada (um
 * indicador em alerta ou bloqueado continua contando como liberado nos KPIs e nos filtros).
 */
final readonly class IndicadorDeRecompensaOutput
{
    public const LIBERADO = 'liberado';
    public const CARENCIA = 'carencia';
    public const ANDAMENTO = 'andamento';
    public const BLOQUEADO = 'bloqueado';
    public const ANALISE = 'analise';
    public const ALERTA = 'alerta';

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
        public bool $liberado,
        public bool $bloqueado,
        /** Contas suspeitas que o Master ainda não aprovou (não contam). */
        public int $suspeitas,
        /** @var array<string, int> motivo (escritorio, cnpj, dominio) => quantas contas suspeitas pendentes */
        public array $motivosDeSuspeita,
        /** Contas aceitas nos últimos 7 dias (base do alerta). */
        public int $emSeteDias,
    ) {
    }

    /** @return array<string, mixed> */
    public function paraArray(): array
    {
        return [
            'userId' => $this->userId, 'nome' => $this->nome, 'email' => $this->email, 'escritorio' => $this->escritorio,
            'envios' => $this->envios, 'contas' => $this->contas, 'ativos' => $this->ativos, 'emCarencia' => $this->emCarencia,
            'minEscritorios' => $this->minEscritorios, 'valorCentavos' => $this->valorCentavos,
            'saldoCentavos' => $this->saldoCentavos, 'pagoCentavos' => $this->pagoCentavos,
            'liberado' => $this->liberado, 'situacao' => $this->situacao,
            'bloqueado' => $this->bloqueado, 'suspeitas' => $this->suspeitas, 'motivosDeSuspeita' => $this->motivosDeSuspeita,
            'emSeteDias' => $this->emSeteDias,
            'condicao' => $this->condicao?->paraArray(),
        ];
    }
}
