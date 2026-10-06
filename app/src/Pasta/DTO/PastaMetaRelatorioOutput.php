<?php

declare(strict_types=1);

namespace App\Pasta\DTO;

use App\Entity\Tarefa\Tarefa;

/**
 * Conteúdo do drawer "Relatório da meta" da aba Metas da pasta (desenho 1.2.3,
 * `metaVals`, dc L.3557-3600): pílula de situação, frase do prazo, a grade de seis
 * campos e o histórico. Tudo sai de colunas que a `Tarefa` já tem — `criadoPor`,
 * `dataCriacao`, `prazo`, `dataAlteracao`, `dataConclusao`, `responsaveis` — e
 * nada é inventado: evento sem data no banco não ganha data.
 *
 * Recebe o ESTADO e os DIAS já decididos por `PastaMetasResumoOutput` (a mesma conta
 * da linha, do filtro e do trilho), para o drawer nunca divergir da lista.
 *
 * "Outras metas desta pasta" e o "N de M" não moram aqui: saem das próprias linhas
 * no navegador (pasta-metas.js), para a página não repetir a lista inteira dentro de
 * cada relatório.
 */
final readonly class PastaMetaRelatorioOutput
{
    /**
     * @param list<array{rotulo: string, valor: string}>             $campos
     * @param list<array{texto: string, data: string, tom: string}> $historico
     */
    private function __construct(
        /** 'concluida' · 'atrasada' · 'aberta' — o mesmo `estado` da linha. */
        public string $tom,
        /** Texto da pílula: "Concluída", "Atrasada" ou o status da meta aberta ("Pendente", "Para Revisão"). */
        public string $situacao,
        /** Frase sob o título; null quando a meta não tem prazo (a linha não é desenhada). */
        public ?string $prazoTexto,
        public bool $prazoEmAtraso,
        public array $campos,
        public array $historico,
    ) {
    }

    public static function de(
        Tarefa $tarefa,
        string $estado,
        ?int $dias,
        ?string $prazoConcluida,
        ?string $identificadorDaPasta,
        \DateTimeImmutable $hoje,
    ): self {
        $concluida = $estado === 'concluida';
        $atrasada  = $estado === 'atrasada';
        $prazo     = $tarefa->getPrazo();
        $situacao  = $concluida ? 'Concluída' : ($atrasada ? 'Atrasada' : (Tarefa::STATUS_LABELS[$tarefa->getStatus()] ?? $tarefa->getStatus()));
        $atraso    = $atrasada && $dias !== null ? -$dias : 0;

        $criador      = self::nome($tarefa->getCriadoPor()?->getFullName());
        $responsaveis = self::responsaveis($tarefa);
        $alteracao    = $tarefa->getDataAlteracao();

        return new self(
            tom: $estado,
            situacao: $situacao,
            prazoTexto: self::prazoTexto($prazo, $concluida, $atraso, $prazoConcluida),
            prazoEmAtraso: $atrasada,
            campos: [
                ['rotulo' => 'Criada por',         'valor' => $criador ?? '—'],
                ['rotulo' => 'Responsáveis',       'valor' => $responsaveis !== '' ? $responsaveis : '—'],
                ['rotulo' => 'Prazo',              'valor' => $prazo !== null ? $prazo->format('d/m/Y') : 'Sem prazo'],
                ['rotulo' => 'Última modificação', 'valor' => $alteracao !== null ? $alteracao->format('d/m/Y') : 'Sem alterações'],
                ['rotulo' => 'Pasta',              'valor' => ($identificadorDaPasta ?? '') !== '' ? (string) $identificadorDaPasta : '—'],
                ['rotulo' => 'Situação',           'valor' => $situacao],
            ],
            historico: self::historico($tarefa, $criador, $responsaveis, $concluida, $atraso, $hoje),
        );
    }

    /**
     * Desenho: "Concluída no prazo X" · "N dias em atraso · prazo X" · "Vence X".
     * A concluída usa o rótulo REAL da lista (compara `dataConclusao` com o prazo),
     * com a inicial maiúscula — o desenho só conhece "no prazo".
     */
    private static function prazoTexto(?\DateTimeImmutable $prazo, bool $concluida, int $atraso, ?string $prazoConcluida): ?string
    {
        if ($prazo === null) {
            return null;
        }

        if ($concluida) {
            return $prazoConcluida !== null ? self::inicialMaiuscula($prazoConcluida) : null;
        }

        if ($atraso > 0) {
            return sprintf('%d %s em atraso · prazo %s', $atraso, $atraso === 1 ? 'dia' : 'dias', $prazo->format('d/m/Y'));
        }

        return 'Vence ' . $prazo->format('d/m/Y');
    }

    /**
     * Desenho: criada (azul) → prazo (cinza) → concluída (verde) ou atualizada
     * (âmbar) → atraso até hoje (vermelho). Só entra o que o banco registrou.
     *
     * @return list<array{texto: string, data: string, tom: string}>
     */
    private static function historico(
        Tarefa $tarefa,
        ?string $criador,
        string $responsaveis,
        bool $concluida,
        int $atraso,
        \DateTimeImmutable $hoje,
    ): array {
        $criada = 'Meta criada';
        if ($criador !== null) {
            $criada .= ' por ' . $criador;
        }
        if ($responsaveis !== '') {
            $criada .= ' para ' . $responsaveis;
        }

        $eventos = [['texto' => $criada, 'data' => $tarefa->getDataCriacao()->format('d/m/Y'), 'tom' => 'criada']];

        $prazo = $tarefa->getPrazo();
        if ($prazo !== null) {
            $eventos[] = ['texto' => 'Prazo definido para ' . $prazo->format('d/m/Y'), 'data' => $prazo->format('d/m/Y'), 'tom' => 'prazo'];
        }

        if ($concluida) {
            $conclusao = $tarefa->getDataConclusao();
            $eventos[] = [
                'texto' => 'Concluída',
                'data'  => $conclusao !== null ? $conclusao->format('d/m/Y') : 'data de conclusão não registrada',
                'tom'   => 'concluida',
            ];
        } elseif ($tarefa->getDataAlteracao() !== null) {
            $eventos[] = ['texto' => 'Atualizada', 'data' => $tarefa->getDataAlteracao()->format('d/m/Y'), 'tom' => 'atualizada'];
        }

        if ($atraso > 0) {
            $eventos[] = [
                'texto' => sprintf('%d %s em atraso até hoje', $atraso, $atraso === 1 ? 'dia' : 'dias'),
                'data'  => $hoje->format('d/m/Y'),
                'tom'   => 'atraso',
            ];
        }

        return $eventos;
    }

    private static function responsaveis(Tarefa $tarefa): string
    {
        $nomes = [];
        foreach ($tarefa->getResponsaveis() as $responsavel) {
            $nome = self::nome($responsavel->getFullName());
            if ($nome !== null) {
                $nomes[] = $nome;
            }
        }

        return implode(', ', $nomes);
    }

    private static function nome(?string $nome): ?string
    {
        $nome = trim((string) $nome);

        return $nome !== '' ? $nome : null;
    }

    private static function inicialMaiuscula(string $texto): string
    {
        return mb_strtoupper(mb_substr($texto, 0, 1)) . mb_substr($texto, 1);
    }
}
