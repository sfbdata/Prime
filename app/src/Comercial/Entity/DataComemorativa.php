<?php

declare(strict_types=1);

namespace App\Comercial\Entity;

use App\Comercial\Repository\DataComemorativaRepository;
use App\Entity\Auth\User;
use App\Shared\Contract\Auditavel;
use Doctrine\ORM\Mapping as ORM;

/**
 * O estado de uma data comemorativa da Central de envios (spec `comercial-e-emails.md` §13, F4; desenho 05 VS
 * 21.10.26 L678-682, o `bj-com-datas` do dc que vira servidor): uma linha por chave de
 * {@see \App\Comercial\Campanha\CalendarioDasDatasComemorativas}, semeada pela migration com o padrão do dc (ligada,
 * no dia, para Todos). Auditável: a mudança do Master fica na trilha (o AuditLogSubscriber grava no flush).
 *
 * Plataforma (sem escritório, do Master): não é TenantAware.
 */
#[ORM\Entity(repositoryClass: DataComemorativaRepository::class)]
#[ORM\Table(name: 'comercial_data_comemorativa')]
class DataComemorativa implements Auditavel
{
    public const PUBLICOS = ['todos', 'prim', 'leads'];
    /**
     * A regra única da data do calendário sem linha no banco (a tela, o disparo e a alteração): o padrão do dc, ligada,
     * no dia, para Todos. Os valores iniciais das propriedades são os mesmos.
     */
    public const PADRAO = ['ligada' => true, 'antecedencia' => 0, 'publico' => 'todos'];

    #[ORM\Id]
    #[ORM\Column(length: 20)]
    private string $chave = '';

    #[ORM\Column(type: 'boolean')]
    private bool $ligada = true;

    /** Os dias de antecedência do envio: 0 (no dia), 1 ou 3. */
    #[ORM\Column(type: 'smallint')]
    private int $antecedencia = 0;

    /** `todos` (primários e secundários), `prim` ou `leads` (Base de contatos). */
    #[ORM\Column(length: 10)]
    private string $publico = 'todos';

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'alterada_por_id', nullable: true, onDelete: 'SET NULL')]
    private ?User $alteradaPor = null;

    #[ORM\Column(name: 'alterada_em', type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $alteradaEm = null;

    /** As linhas nascem na migration; o construtor serve a quem cria uma à mão (testes). */
    public function __construct(string $chave)
    {
        $this->chave = $chave;
    }

    /**
     * @param array<string, array{ligada: bool, antecedencia: int, publico: string}> $estados os do banco, por chave
     *
     * @return array{ligada: bool, antecedencia: int, publico: string}
     */
    public static function estadoOuPadrao(array $estados, string $chave): array
    {
        return $estados[$chave] ?? self::PADRAO;
    }

    public function getChave(): string
    {
        return $this->chave;
    }

    public function isLigada(): bool
    {
        return $this->ligada;
    }

    public function getAntecedencia(): int
    {
        return $this->antecedencia;
    }

    public function getPublico(): string
    {
        return $this->publico;
    }

    public function alterar(bool $ligada, int $antecedencia, string $publico, User $por, \DateTimeImmutable $agora): void
    {
        $this->ligada = $ligada;
        $this->antecedencia = $antecedencia;
        $this->publico = $publico;
        $this->alteradaPor = $por;
        $this->alteradaEm = $agora;
    }
}
