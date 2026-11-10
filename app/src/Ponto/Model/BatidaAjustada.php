<?php

declare(strict_types=1);

namespace App\Ponto\Model;

/**
 * A batida como a folha a conta depois de ajustada (E10, plano-e10-ponto §6): a cabeça de uma linhagem de
 * `ponto_ajuste`, com o tipo e a data/hora que o último ajuste deu. A raiz é o registro original (alvo 'b{id}') ou a
 * inclusão feita pelo gestor (alvo 'a{id}', sem registro: `getId()` nulo).
 *
 * Da original ficam o home office e o trabalho externo (o lugar do trabalho, E12) e a observação, se quem monta quiser
 * preservá-la; a ajustada nunca é "sem internet" nem "feita pelo colaborador": quem deu a forma atual foi o gestor.
 * A inclusão do gestor não tem original: nem home office nem trabalho externo.
 */
final readonly class BatidaAjustada implements MarcacaoDoPonto
{
    public function __construct(
        private ?int $registroId,
        private ?int $inclusaoId,
        private int $cabecaId,
        private int $pessoaId,
        private string $tipo,
        private \DateTimeImmutable $dataHora,
        private AjusteDaMarcacao $ajuste,
        private bool $homeOffice = false,
        private ?string $observacao = null,
        private bool $trabalhoExterno = false,
    ) {
        if (($registroId === null) === ($inclusaoId === null)) {
            throw new \LogicException('A batida ajustada tem uma raiz só: o registro original ou a inclusão.');
        }
    }

    public function getId(): ?int
    {
        return $this->registroId;
    }

    public function getDataHora(): \DateTimeImmutable
    {
        return $this->dataHora;
    }

    public function getTipo(): string
    {
        return $this->tipo;
    }

    public function getObservacao(): ?string
    {
        return $this->observacao;
    }

    public function isHomeOffice(): bool
    {
        return $this->homeOffice;
    }

    public function isEnviadaSemInternet(): bool
    {
        return false;
    }

    public function isTrabalhoExterno(): bool
    {
        return $this->trabalhoExterno;
    }

    public function feitaPeloColaborador(): bool
    {
        return false;
    }

    public function pessoaId(): int
    {
        return $this->pessoaId;
    }

    public function alvo(): string
    {
        return $this->registroId !== null ? 'b' . $this->registroId : 'a' . $this->inclusaoId;
    }

    public function cabeca(): int
    {
        return $this->cabecaId;
    }

    public function ajuste(): AjusteDaMarcacao
    {
        return $this->ajuste;
    }
}
