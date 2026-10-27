<?php

declare(strict_types=1);

namespace App\Agenda\DTO;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * Campos do modal "Novo compromisso" / "Editar compromisso" do desenho 09, mais os que a agenda
 * antiga já tinha e o desenho não mostra (repetição e participantes, convenção D1 da spec).
 */
final readonly class CompromissoInput
{
    public const VISIBILIDADES = ['equipe', 'privado'];
    public const LEMBRETES = [0, 10, 15, 30, 60, 1440];
    public const REPETICOES = ['', 'diario', 'semanal', 'mensal', 'anual'];

    /**
     * @param list<int> $avisados
     * @param list<int> $participantes
     * @param int       $dias          quantos dias depois do início o compromisso termina (vários dias ou passa da meia-noite)
     */
    public function __construct(
        #[Assert\NotBlank(message: 'Informe o título.')]
        #[Assert\Length(max: 255, maxMessage: 'O título passa de {{ limit }} caracteres.')]
        public string $titulo,
        #[Assert\Choice(choices: ['meus', 'aud', 'reun', 'prazo', 'urg', 'pess', 'lemb'], message: 'Tipo de compromisso inválido.')]
        public string $tipo,
        #[Assert\NotBlank(message: 'Informe a data.')]
        #[Assert\Date(message: 'Data inválida.')]
        public string $data,
        public bool $diaInteiro,
        #[Assert\Regex(pattern: '/^([01]\d|2[0-3]):[0-5]\d$/', message: 'Hora de início inválida.')]
        public string $inicio,
        #[Assert\Regex(pattern: '/^(([01]\d|2[0-3]):[0-5]\d)?$/', message: 'Hora de fim inválida.')]
        public string $fim,
        #[Assert\Choice(choices: self::VISIBILIDADES, message: 'Visibilidade inválida.')]
        public string $visibilidade,
        #[Assert\Length(max: 50, maxMessage: 'Número da pasta muito longo.')]
        public string $pasta,
        #[Assert\Choice(choices: self::LEMBRETES, message: 'Lembrete inválido.')]
        public int $lembreteMinutos,
        #[Assert\Length(max: 5000, maxMessage: 'As observações passam de {{ limit }} caracteres.')]
        public string $observacao,
        public array $avisados = [],
        #[Assert\Choice(choices: self::REPETICOES, message: 'Repetição inválida.')]
        public string $repetir = '',
        #[Assert\Date(message: 'Data final da repetição inválida.')]
        public ?string $repetirAte = null,
        public array $participantes = [],
        #[Assert\Range(min: 0, max: 366, notInRangeMessage: 'Duração inválida.')]
        public int $dias = 0,
    ) {
    }

    /** @param array<string, mixed> $j corpo JSON do modal */
    public static function doJson(array $j): self
    {
        $ids = static fn (mixed $v): array => array_values(array_map('intval', array_filter(is_array($v) ? $v : [], 'is_numeric')));
        $texto = static fn (string $k): string => trim(is_scalar($j[$k] ?? null) ? (string) $j[$k] : '');

        return new self(
            titulo: $texto('titulo'),
            tipo: $texto('tipo') ?: 'meus',
            data: $texto('data'),
            diaInteiro: true === ($j['diaInteiro'] ?? false),
            inicio: $texto('inicio') ?: '00:00',
            fim: $texto('fim'),
            visibilidade: $texto('visibilidade') ?: 'equipe',
            pasta: $texto('pasta'),
            lembreteMinutos: (int) ($j['lembreteMinutos'] ?? 15),
            observacao: $texto('observacao'),
            avisados: $ids($j['avisados'] ?? []),
            repetir: $texto('repetir'),
            repetirAte: $texto('repetirAte') ?: null,
            participantes: $ids($j['participantes'] ?? []),
            dias: (int) ($j['dias'] ?? 0),
        );
    }
}
