<?php

declare(strict_types=1);

namespace App\Voto\DTO;

/**
 * Uma pauta da assembleia como a tela envia: título, descrição e as linhas de opção (número e nome, como digitados).
 * `regras` nulo mantém as regras da pauta (nova: as padrão). A abstenção não vem daqui: é criada sozinha. Linha com número e nome vazios é ignorada.
 */
final readonly class PautaInput
{
    /** As opções do cenário condomínio (pauta padrão do desenho). */
    public const OPCOES_PADRAO = [['numero' => '1', 'rotulo' => 'Sim'], ['numero' => '2', 'rotulo' => 'Não']];

    /**
     * @param list<array{numero: string, rotulo: string}> $opcoes
     */
    public function __construct(
        public string $titulo,
        public ?string $descricao,
        public array $opcoes,
        public ?RegrasDaPautaInput $regras = null,
    ) {
    }

    /** "Cargos e pautas frequentes": a pauta padrão (1 Sim, 2 Não) com o nome do atalho. */
    public static function padrao(string $titulo): self
    {
        return new self($titulo, null, self::OPCOES_PADRAO);
    }

    public function descricaoLimpa(): ?string
    {
        $descricao = trim((string) $this->descricao);

        return $descricao === '' ? null : $descricao;
    }
}
