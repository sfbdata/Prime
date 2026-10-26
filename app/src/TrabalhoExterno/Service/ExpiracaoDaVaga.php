<?php

declare(strict_types=1);

namespace App\TrabalhoExterno\Service;

use App\TrabalhoExterno\Entity\Vaga;
use App\TrabalhoExterno\Enum\SituacaoVaga;

/**
 * A vaga expirada do desenho 06 de 24/10 (D4): `expDe` e `velha` (l.1124) e `expRec` (l.1063), com a situação
 * de agora ({@see Vaga::situacaoEm()}, o `v.status` que o desenho grava no tique). Sem estado: o mesmo cálculo
 * vale para o cartão, a lista e o corte de 30 dias.
 *
 * - `expDe`, a primeira que valer: oferta com `expira` vencido e situação aberta, encerrada ou expirada →
 *   `expira`; `inscricoesAte` antes de hoje e situação não pendente nem reprovada → `inscricoesAte` 23:59:59;
 *   encerrada ou expirada → `encerradaEm`, senão `expira`, senão a criação.
 * - `velha`: expirada há mais de 30 dias. Sai da lista de TODOS, inclusive dos filtros do interno (P3).
 * - `expRec` (o que entra na lista do externo além das abertas): a mesma ordem, mas sem conferir a situação nos
 *   dois primeiros ramos; vale se expirou há até 30 dias e a vaga não é pendente nem reprovada.
 */
final class ExpiracaoDaVaga
{
    public const DIAS = 30;

    /** `expDe` (l.1124): quando a vaga expirou para o cartão ("Expirou em"), ou null se não expirou. */
    public function expiradaEm(Vaga $v, \DateTimeImmutable $agora): ?\DateTimeImmutable
    {
        $s = $v->situacaoEm($agora);
        $expira = $v->getExpiraEm();
        if ($v->isOferta() && $expira !== null && $expira < $agora && in_array($s, [SituacaoVaga::Aberta, SituacaoVaga::Encerrada, SituacaoVaga::Expirada], true)) {
            return $expira;
        }
        if (self::prazoVencido($v, $agora) && !self::naoPublicada($s)) {
            return self::fimDoPrazo($v);
        }

        return self::fechada($s) ? self::dataDaFechada($v) : null;
    }

    /** `velha` (l.1124): expirada há mais de 30 dias. */
    public function velha(Vaga $v, \DateTimeImmutable $agora): bool
    {
        $e = $this->expiradaEm($v, $agora);

        return $e !== null && $agora->getTimestamp() - $e->getTimestamp() > self::DIAS * 86400;
    }

    /** `expRec` (l.1063): expirada há até 30 dias e não pendente nem reprovada; entra na lista do externo. */
    public function recente(Vaga $v, \DateTimeImmutable $agora): bool
    {
        $s = $v->situacaoEm($agora);
        $expira = $v->getExpiraEm();
        $e = match (true) {
            $v->isOferta() && $expira !== null && $expira < $agora => $expira,
            self::prazoVencido($v, $agora) => self::fimDoPrazo($v),
            self::fechada($s) => self::dataDaFechada($v),
            default => null,
        };

        return $e !== null && $agora->getTimestamp() - $e->getTimestamp() <= self::DIAS * 86400 && !self::naoPublicada($s);
    }

    /** `v.prazo < hoje`: o último dia de inscrição já passou. */
    private static function prazoVencido(Vaga $v, \DateTimeImmutable $agora): bool
    {
        $prazo = $v->getInscricoesAte();

        return $prazo !== null && $prazo->format('Y-m-d') < $agora->format('Y-m-d');
    }

    /** `Date.parse(v.prazo + 'T23:59:59')`. */
    private static function fimDoPrazo(Vaga $v): \DateTimeImmutable
    {
        return \DateTimeImmutable::createFromFormat('Y-m-d H:i:s', ((string) $v->getInscricoesAte()?->format('Y-m-d')) . ' 23:59:59') ?: new \DateTimeImmutable();
    }

    /** `v.encerradaEm || v.expira || v.em`; a encerrada pelo fim da divulgação sem a data gravada usa o `fimDivulgacao`. */
    private static function dataDaFechada(Vaga $v): \DateTimeImmutable
    {
        return $v->getEncerradaEm() ?? ($v->getSituacao() !== SituacaoVaga::Expirada ? $v->getFimDivulgacao() : null) ?? $v->getExpiraEm() ?? $v->getCriadaEm();
    }

    private static function fechada(SituacaoVaga $s): bool
    {
        return $s === SituacaoVaga::Encerrada || $s === SituacaoVaga::Expirada;
    }

    private static function naoPublicada(SituacaoVaga $s): bool
    {
        return $s === SituacaoVaga::Pendente || $s === SituacaoVaga::Reprovada;
    }
}
