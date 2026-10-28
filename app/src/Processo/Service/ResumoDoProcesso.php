<?php

declare(strict_types=1);

namespace App\Processo\Service;

use App\Cobranca\Service\ResolvedorIdentificadorDaPasta;
use App\Pasta\Entity\Pasta;
use App\Processo\Entity\Processo;
use App\Twig\NumeroProcessoExtension;

/**
 * As linhas do "Copiar resumo do processo", do "Compartilhar" e do "Imprimir resumo" (desenho, procMenu
 * N:3059): uma fonte só para a tela (`resumo_do_processo`) e para o PDF do servidor (PastaResumoController).
 *
 * P5d: as linhas do desenho SEMPRE, com "Não informado" no que falta (antes, escolha técnica, só o dado
 * real). Ação: a da pasta no processo principal; nos demais, ou sem ação, o assunto. Classe, órgão e
 * valor: o valor efetivo do cartão (o manual prevalece; P5b).
 */
final class ResumoDoProcesso
{
    public const NAO_INFORMADO = 'Não informado';

    public function __construct(
        private readonly MontadorDosDadosDoProcesso $montador,
        private readonly ResolvedorIdentificadorDaPasta $identificador,
    ) {
    }

    /** @return list<array{rotulo: string, valor: string}> a 1ª e a última sem valor ("Processo N", "Pasta N · cliente") */
    public function linhas(Pasta $pasta, Processo $processo): array
    {
        $base = $this->montador->valoresBase($processo, $pasta);
        $manual = $processo->getDadosManuais();
        $principal = $pasta->getVinculoPrincipal()?->getProcesso() === $processo;
        $assunto = $manual->assuntos !== null ? implode(', ', $manual->assuntos) : trim($processo->getAssuntoProcessual());
        $acao = $principal && trim((string) $pasta->getNomeAcao()) !== '' ? trim((string) $pasta->getNomeAcao()) : $assunto;
        $situacao = trim($processo->getSituacaoProcesso());

        $campos = [
            'Ação' => $acao,
            'Classe' => $manual->classe ?? trim($processo->getClasseProcessual()),
            'Tribunal' => trim($processo->getSiglaTribunal()),
            'Situação' => $situacao !== '' ? SituacaoProcessoRotulo::de($situacao) : '',
            'Órgão julgador' => (string) MontadorDosDadosDoProcesso::exibir('orgao', $manual->orgao ?? $base->orgao),
            'Valor da causa' => (string) MontadorDosDadosDoProcesso::exibir('valorCausa', $manual->valorCausa ?? $base->valorCausa),
        ];

        $linhas = [['rotulo' => 'Processo ' . (new NumeroProcessoExtension())->numeroCnj($processo->getNumeroProcesso()), 'valor' => '']];
        foreach ($campos as $rotulo => $valor) {
            $linhas[] = ['rotulo' => $rotulo, 'valor' => $valor !== '' ? $valor : self::NAO_INFORMADO];
        }
        $cliente = (string) $this->identificador->para($pasta);
        $linhas[] = ['rotulo' => 'Pasta ' . ($pasta->getNup() ?? ('#' . $pasta->getId())) . ($cliente !== '' && $cliente !== '0' ? ' · ' . $cliente : ''), 'valor' => ''];

        return $linhas;
    }

    /** @return list<string> "Rótulo: valor" (ou só o rótulo), como o JS junta para copiar */
    public function texto(Pasta $pasta, Processo $processo): array
    {
        return array_map(static fn (array $l): string => $l['rotulo'] . ($l['valor'] !== '' ? ': ' . $l['valor'] : ''), $this->linhas($pasta, $processo));
    }
}
