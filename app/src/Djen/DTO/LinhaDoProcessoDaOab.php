<?php

declare(strict_types=1);

namespace App\Djen\DTO;

/**
 * Uma linha da aba "Processos da OAB" (desenho 11): o processo acompanhado (cadastrado ou incluído só pelo
 * número) ou, no Relatório, o número que a OAB trouxe e ainda não é acompanhado.
 */
final readonly class LinhaDoProcessoDaOab
{
    public function __construct(
        /** Só dígitos (20). */
        public string $numero,
        public ?int $processoId,
        public string $tribunal,
        public string $partes,
        public string $orgao,
        public ?string $area,
        public ?string $fase,
        public ?string $observacao,
        public ?string $emailAlternativo,
        public ?\DateTimeImmutable $ultimoMovimento,
        /** "49613/DF" da OAB da publicação mais recente. */
        public ?string $oab,
        public ?int $pastaId,
        public ?string $pastaNup,
        public int $publicacoes,
    ) {
    }

    /** Dias desde o último movimento; nulo se nunca houve publicação. */
    public function diasParado(\DateTimeImmutable $hoje): ?int
    {
        if ($this->ultimoMovimento === null) {
            return null;
        }

        return max(0, (int) $this->ultimoMovimento->setTime(0, 0)->diff($hoje->setTime(0, 0))->format('%a'));
    }

    /** O número CNJ com máscara (NNNNNNN-DD.AAAA.J.TR.OOOO); fora do padrão, devolve como veio. */
    public function numeroComMascara(): string
    {
        if (preg_match('/^(\d{7})(\d{2})(\d{4})(\d)(\d{2})(\d{4})$/', $this->numero, $m) !== 1) {
            return $this->numero;
        }

        return sprintf('%s-%s.%s.%s.%s.%s', $m[1], $m[2], $m[3], $m[4], $m[5], $m[6]);
    }
}
