<?php

declare(strict_types=1);

namespace App\Master\Service;

/**
 * Um tema comemorativo do catálogo ({@see CatalogoDeTemas}): cores, fita, ícone, mensagem e a regra de data.
 * Valores literais do desenho (docs/design/perfis-1.2/vs-21-10-26/bj-tema.js); spec docs/specs/temas-comemorativos.md.
 *
 * A data é uma regra por ano: dia fixo (`dia`) ou o n-ésimo domingo do mês (`domingo`), com a duração em dias
 * contada a partir do início (o Réveillon começa em 26/12 e atravessa o ano até 01/01).
 */
final readonly class TemaComemorativo
{
    public function __construct(
        public string $id,
        public string $nome,
        /** Cor primária (cabeçalho, botão primário, links). */
        public string $p,
        /** Cor escura (hover do botão, nome no selo). */
        public string $d,
        /** Cor clara (borda do selo). */
        public string $l,
        /** Gradiente da faixa do topo. */
        public string $fita,
        /** Emoji, ou texto curto (Independência: "Brasil"), que muda o tamanho da letra. */
        public string $icone,
        public string $mensagem,
        public int $mes,
        /** Dia do mês do início; nulo quando a regra é de domingo. */
        public ?int $dia,
        /** n-ésimo domingo do mês (2 = segundo domingo); nulo quando o dia é fixo. */
        public ?int $domingo,
        public int $duracao = 1,
        /** Tema "do mês inteiro": perde para um tema de dia específico no mesmo dia. */
        public bool $fraco = false,
        /** Fita e botão redondo animados (Dia das Crianças). */
        public bool $animado = false,
    ) {
    }

    /** Ícone em texto (mais de 3 caracteres, como "Brasil"), que o desenho escreve em letra pequena e negrito. */
    public function iconeEhTexto(): bool
    {
        return mb_strlen($this->icone) > 3;
    }

    /** Início da ocorrência do ano informado, à meia-noite, no fuso da data de referência. */
    public function inicioEm(int $ano, \DateTimeZone $fuso): \DateTimeImmutable
    {
        $primeiro = new \DateTimeImmutable(sprintf('%04d-%02d-01 00:00:00', $ano, $this->mes), $fuso);
        if ($this->domingo === null) {
            return $primeiro->setDate($ano, $this->mes, (int) $this->dia);
        }

        // Como o nDom() do desenho: 1 + (7 - diaDaSemana) % 7 é o primeiro domingo; soma 7 por domingo seguinte.
        $diaDaSemana = (int) $primeiro->format('w');

        return $primeiro->setDate($ano, $this->mes, 1 + (7 - $diaDaSemana) % 7 + 7 * ($this->domingo - 1));
    }

    /** Último dia da ocorrência do ano informado (início + duração - 1). */
    public function fimEm(int $ano, \DateTimeZone $fuso): \DateTimeImmutable
    {
        return $this->inicioEm($ano, $fuso)->modify(sprintf('+%d days', $this->duracao - 1));
    }

    /**
     * O dia cai neste tema? Testa a ocorrência do ano anterior e a do próprio ano, como o noDia() do
     * desenho: em 01/01 vale o Réveillon que começou em 26/12 do ano anterior.
     */
    public function caiNoDia(\DateTimeImmutable $dia): bool
    {
        $hoje = $dia->format('Y-m-d');
        $ano = (int) $dia->format('Y');
        foreach ([$ano - 1, $ano] as $a) {
            if ($hoje >= $this->inicioEm($a, $dia->getTimezone())->format('Y-m-d') && $hoje <= $this->fimEm($a, $dia->getTimezone())->format('Y-m-d')) {
                return true;
            }
        }

        return false;
    }

    /**
     * Ocorrência em curso ou a próxima, a partir do dia informado (a que ainda não terminou e começa primeiro).
     *
     * @return array{0: \DateTimeImmutable, 1: \DateTimeImmutable} início e fim
     */
    public function proximaOcorrencia(\DateTimeImmutable $dia): array
    {
        $hoje = $dia->format('Y-m-d');
        $ano = (int) $dia->format('Y');
        $fuso = $dia->getTimezone();
        foreach ([$ano - 1, $ano, $ano + 1] as $a) {
            if ($this->fimEm($a, $fuso)->format('Y-m-d') >= $hoje) {
                return [$this->inicioEm($a, $fuso), $this->fimEm($a, $fuso)];
            }
        }

        // Inalcançável: a ocorrência do ano seguinte sempre termina depois de hoje.
        return [$this->inicioEm($ano + 1, $fuso), $this->fimEm($ano + 1, $fuso)];
    }

    /** "dd/mm" ou "dd/mm a dd/mm" da próxima ocorrência, como o "quando" do card do desenho. */
    public function quando(\DateTimeImmutable $dia): string
    {
        [$inicio, $fim] = $this->proximaOcorrencia($dia);

        return $inicio->format('d/m') . ($this->duracao > 1 ? ' a ' . $fim->format('d/m') : '');
    }

    /** "r, g, b" da cor primária, para `--bs-primary-rgb`. */
    public function rgb(string $hex): string
    {
        $hex = ltrim($hex, '#');

        return implode(', ', array_map(static fn (string $par): int => (int) hexdec($par), str_split($hex, 2)));
    }

    /**
     * Dados para o layout e para a prévia (sem a regra de data).
     *
     * @return array{id: string, r: string, p: string, d: string, l: string, pRgb: string, dRgb: string, fita: string, ic: string, icTexto: bool, msg: string, anim: bool}
     */
    public function paraJson(): array
    {
        return [
            'id' => $this->id,
            'r' => $this->nome,
            'p' => $this->p,
            'd' => $this->d,
            'l' => $this->l,
            'pRgb' => $this->rgb($this->p),
            'dRgb' => $this->rgb($this->d),
            'fita' => $this->fita,
            'ic' => $this->icone,
            'icTexto' => $this->iconeEhTexto(),
            'msg' => $this->mensagem,
            'anim' => $this->animado,
        ];
    }
}
