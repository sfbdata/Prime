<?php

declare(strict_types=1);

namespace App\Pasta\DTO;

use App\Pasta\Entity\CadeadoDaPasta;
use App\Pasta\Entity\ModoDoCadeado;
use App\Pasta\Entity\NivelDeAcesso;

/**
 * O estado do cadeado depois de salvar ou remover, e o que o ⋮ e o modal mostram: o selo (Livre,
 * Restrito, Todos) e o resumo do desenho (L540, `cd.resumo`).
 */
final class CadeadoDaPastaOutput
{
    public const RESUMO_TODOS = 'O usuário primário continua vendo e editando tudo. A regra fica registrada no histórico da pasta.';

    public function __construct(
        public readonly bool $temCadeado,
        public readonly ?string $modo,
        public readonly string $selo,
        public readonly int $editam,
        public readonly int $visualizam,
        public readonly int $naoVeem,
    ) {
    }

    public static function livre(): self
    {
        return new self(false, null, 'Livre', 0, 0, 0);
    }

    /** @param int $tamanhoDaEquipe quem o cadeado restringe (sem primários e Master) */
    public static function fromEntity(CadeadoDaPasta $cadeado, int $tamanhoDaEquipe): self
    {
        $editam = $visualizam = 0;
        if ($cadeado->getModo() === ModoDoCadeado::PorPessoa) {
            foreach ($cadeado->getAcessos() as $acesso) {
                $acesso->getNivel() === NivelDeAcesso::Editar ? ++$editam : ++$visualizam;
            }
        }

        return new self(
            true,
            $cadeado->getModo()->value,
            $cadeado->getModo()->selo(),
            $editam,
            $visualizam,
            max(0, $tamanhoDaEquipe - $editam - $visualizam),
        );
    }

    /** "N editam, N só visualizam, N não veem. O usuário primário sempre vê e edita tudo." ou o texto do modo Todos. */
    public function resumo(): string
    {
        if ($this->modo === ModoDoCadeado::Todos->value) {
            return self::RESUMO_TODOS;
        }

        return sprintf('%d editam, %d só visualizam, %d não veem. O usuário primário sempre vê e edita tudo.', $this->editam, $this->visualizam, $this->naoVeem);
    }

    /** O texto do histórico (`cadGravar` do desenho): "oculta para todos" ou "2 editam, 1 visualizam, 3 ocultos". */
    public function regraParaOHistorico(): string
    {
        if ($this->modo === ModoDoCadeado::Todos->value) {
            return 'oculta para todos';
        }

        return sprintf('%d editam, %d visualizam, %d ocultos', $this->editam, $this->visualizam, $this->naoVeem);
    }

    /** @return array<string, mixed> */
    public function paraJson(): array
    {
        return [
            'temCadeado' => $this->temCadeado,
            'modo'       => $this->modo,
            'selo'       => $this->selo,
            'resumo'     => $this->temCadeado ? $this->resumo() : '',
        ];
    }
}
