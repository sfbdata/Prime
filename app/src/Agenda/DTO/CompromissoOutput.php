<?php

declare(strict_types=1);

namespace App\Agenda\DTO;

/**
 * Um item da agenda que vem de cadastro: compromisso (Evento), prazo ou audiência de pasta
 * (PastaPrazo) ou aniversário de colaborador (perfil). Uma linha por dia em que aparece.
 */
final readonly class CompromissoOutput
{
    public const CAL_MEUS   = 'meus';
    public const CAL_AUD    = 'aud';
    public const CAL_PRAZO  = 'prazo';
    public const CAL_REUNIAO = 'reun';
    public const CAL_ANIV   = 'aniv';
    public const CAL_URGENTE = 'urg';
    public const CAL_PESSOAL = 'pess';
    public const CAL_LEMBRETE = 'lemb';

    public const ORIGEM_AGENDA = 'agenda';
    public const ORIGEM_PASTA  = 'pasta';
    public const ORIGEM_ANIV   = 'aniv';

    /**
     * @param list<int> $avisados
     * @param list<int> $participantes
     */
    public function __construct(
        public string $id,
        public string $data,
        public string $titulo,
        public string $calendario,
        public string $origem,
        public bool $dia,
        public string $h1 = '',
        public string $h2 = '',
        public bool $meu = false,
        public ?string $donoNome = null,
        public bool $privado = false,
        public ?string $observacao = null,
        public ?string $status = null,
        public bool $recorrente = false,
        public ?int $eventoId = null,
        public ?int $pastaId = null,
        public ?string $pastaNup = null,
        public ?int $usuarioId = null,
        public ?string $tipo = null,
        public ?int $lembreteMinutos = null,
        public array $avisados = [],
        public array $participantes = [],
        public ?string $repetir = null,
        public ?string $repetirAte = null,
        public bool $editavel = false,
        /** Dia e horário do evento (não da ocorrência), para o modal de edição. */
        public ?string $inicioOriginal = null,
        public ?string $fimOriginal = null,
        /** Nome dado pelo escritório (aniversário renomeado pelo botão direito). */
        public bool $editado = false,
        /** Nasceu no Google Agenda da pessoa e veio pela sincronia (entrega 6): "Importado do Google Agenda." */
        public bool $doGoogle = false,
        /** Local e descrição separados (a observação os junta); a tela 1A mostra cada um no seu lugar. */
        public ?string $local = null,
        public ?string $descricao = null,
    ) {
    }

    /** @return array<string, string|int|bool|list<int>|null> */
    public function toArray(): array
    {
        return get_object_vars($this);
    }
}
