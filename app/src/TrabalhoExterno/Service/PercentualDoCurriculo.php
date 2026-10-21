<?php

declare(strict_types=1);

namespace App\TrabalhoExterno\Service;

use App\TrabalhoExterno\Entity\Curriculo;
use App\TrabalhoExterno\Enum\TipoArquivoDoCurriculo;

/**
 * Quanto do "Meu currículo" está preenchido (desenho 06 l.1013 e 1014): onze itens, a cor da barra e
 * o "Complete: …" na ordem do desenho. O documento conforme a situação é a carteira da OAB (advogado
 * e estagiário) ou o comprovante do curso (estudante e bacharel).
 */
final class PercentualDoCurriculo
{
    private const TOTAL = 11;

    /** Os campos de texto que contam, na ordem do desenho; nome, e-mail e situação nunca faltam. */
    private const FALTAS = [
        'tel' => 'telefone',
        'cidade' => 'cidade',
        'cargo' => 'cargo pretendido',
        'resumo' => 'resumo',
    ];

    /** @return array{pct: int, cor: string, faltas: list<string>} */
    public function calcular(Curriculo $cv): array
    {
        $faltas = $this->documentoOk($cv) ? [] : [$cv->getSituacao()->temOab() ? 'carteira da OAB' : 'comprovante do curso'];
        foreach (self::FALTAS as $campo => $rotulo) {
            if ($cv->texto($campo) === '') {
                $faltas[] = $rotulo;
            }
        }
        if (!$cv->temArquivo(TipoArquivoDoCurriculo::Curriculo)) {
            $faltas[] = 'arquivo do currículo';
        }
        if (!$cv->temArquivo(TipoArquivoDoCurriculo::Foto)) {
            $faltas[] = 'foto de perfil';
        }
        if ($cv->getAreas() === []) {
            $faltas[] = 'áreas de atuação';
        }
        $razao = (self::TOTAL - count($faltas)) / self::TOTAL;

        return [
            'pct' => (int) round($razao * 100),
            'cor' => $razao >= 0.9 ? '#1e7b45' : ($razao >= 0.6 ? '#0a6fa0' : '#c76a12'),
            'faltas' => $faltas,
        ];
    }

    private function documentoOk(Curriculo $cv): bool
    {
        return $cv->temArquivo($cv->getSituacao()->temOab() ? TipoArquivoDoCurriculo::Oab : TipoArquivoDoCurriculo::Matricula);
    }
}
