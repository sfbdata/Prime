<?php

declare(strict_types=1);

namespace App\AssinaturaEletronica\Service\Cadeia;

use App\AssinaturaEletronica\Entity\VersaoDoPdf;
use App\AssinaturaEletronica\Enum\SituacaoDaCadeia;

/**
 * O desfecho da regra da E6-6 para um PDF devolvido (spec `docs/specs/assinatura-documentos-etapa-6.md`): confirmada
 * (todas as assinaturas novas passaram) ou recusada. Indisponível não é desfecho: é {@see CadeiaIndisponivel}, e nada
 * é gravado (E6-4).
 *
 * Só com {@see confirmou()} o CPF que o motor leu passa a valer. O {@see $motivo} é um código para o log (o primeiro
 * problema encontrado), nunca texto para a tela.
 */
final readonly class DecisaoDaCadeia
{
    private function __construct(
        public SituacaoDaCadeia $situacao,
        /** O primeiro problema encontrado; nulo quando confirmada. */
        public ?string $motivo,
        /** A resposta do validador (vai para a coluna `cadeia`, E6-7); nula quando a regra recusou sem chamá-lo. */
        public ?ResultadoDaCadeia $cadeia,
        /** A assinatura nova, como o validador a conferiu, que fez a recusa (quando foi uma delas). */
        public ?AssinaturaDaCadeia $recusada,
    ) {
    }

    public static function confirmada(ResultadoDaCadeia $cadeia): self
    {
        return new self(SituacaoDaCadeia::Confirmada, null, $cadeia, null);
    }

    public static function recusada(string $motivo, ?ResultadoDaCadeia $cadeia, ?AssinaturaDaCadeia $recusada = null): self
    {
        return new self(SituacaoDaCadeia::Recusada, $motivo, $cadeia, $recusada);
    }

    public function confirmou(): bool
    {
        return $this->situacao === SituacaoDaCadeia::Confirmada;
    }

    /** Se o validador foi chamado (há resposta para gravar, E6-7). A recusa do motor antes dele não tem. */
    public function temCadeia(): bool
    {
        return $this->cadeia !== null;
    }

    /**
     * Grava na versão devolvida o que o validador disse (E6-7): `cadeia`, `cadeia_situacao` e `cadeia_conferida_em`.
     *
     * @throws \LogicException recusa sem resposta do validador (nada a gravar) ou versão já conferida
     */
    public function gravarEm(VersaoDoPdf $versao, \DateTimeImmutable $agora): void
    {
        if ($this->cadeia === null) {
            throw new \LogicException('A recusa antes do validador de cadeia não tem cadeia para gravar.');
        }
        $versao->registrarCadeia($this->situacao, $this->cadeia->paraJson(), $agora);
    }
}
