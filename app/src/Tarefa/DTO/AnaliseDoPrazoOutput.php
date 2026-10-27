<?php

declare(strict_types=1);

namespace App\Tarefa\DTO;

use App\Tarefa\Enum\EstadoDoPrazoJuridico;

/** A análise do prazo como a tela do modal "Nova meta" a lê (desenho 02): datas em ISO, rótulos prontos, sem o rótulo "I.A.". */
final readonly class AnaliseDoPrazoOutput
{
    /**
     * @param array<string, mixed> $dados
     */
    private function __construct(public array $dados)
    {
    }

    public static function fromAnalise(AnaliseDoPrazo $a): self
    {
        $data = static fn (?\DateTimeImmutable $d): ?string => $d?->format('Y-m-d');

        return new self([
            'tipo' => $a->tipo,
            'fonteLegal' => $a->fonteLegal,
            'diasUteisLegais' => $a->diasUteisLegais,
            'prazoJuridico' => $data($a->prazoJuridico),
            'aproximado' => $a->aproximado,
            'estado' => $a->estado->value,
            'estadoRotulo' => self::rotuloDoEstado($a->estado),
            'precisaConferir' => EstadoDoPrazoJuridico::NecessitaConferencia === $a->estado,
            'precisaConfirmar' => EstadoDoPrazoJuridico::Estimado === $a->estado || EstadoDoPrazoJuridico::NecessitaConferencia === $a->estado,
            'prazoInterno' => $data($a->prazoInterno),
            'folga' => $a->folga,
            'prioridade' => $a->prioridade,
            'urgente' => $a->urgente,
            'diasAteOInterno' => $a->diasAteOInterno,
            'diasUteisAteOJuridico' => $a->diasUteisAteOJuridico,
            'feriadosNoPeriodo' => $a->feriadosNoPeriodo,
            'termoInicial' => $data($a->termoInicial),
            'origem' => $a->origem,
            'alternativa' => $data($a->alternativa),
            'porque' => $a->porque,
        ]);
    }

    private static function rotuloDoEstado(EstadoDoPrazoJuridico $estado): string
    {
        return match ($estado) {
            EstadoDoPrazoJuridico::Informado => 'Informado pelo usuário',
            EstadoDoPrazoJuridico::Confirmado => 'Confirmado',
            EstadoDoPrazoJuridico::Estimado => 'Estimado',
            EstadoDoPrazoJuridico::NecessitaConferencia => 'Necessita conferência',
            EstadoDoPrazoJuridico::SemPrazoLegal => 'Sem prazo legal identificado',
        };
    }
}
