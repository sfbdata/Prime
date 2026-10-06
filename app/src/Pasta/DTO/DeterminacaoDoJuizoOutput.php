<?php

declare(strict_types=1);

namespace App\Pasta\DTO;

/**
 * Uma determinação achada por REGRA no teor de uma publicação do Push (DOC-79): a frase com verbo
 * de determinação ("intime-se", "junte", "apresente"…), o ato que ela pede, o prazo — só quando o
 * próprio texto diz "prazo de N dias" — e os tipos de documento do catálogo que ela manda juntar.
 *
 * Nada aqui é calculado além do que o texto diz: não há data final de prazo (contagem de dias
 * úteis exige calendário forense, que o sistema não tem), nem prazo legal presumido.
 */
final class DeterminacaoDoJuizoOutput
{
    /**
     * @param list<string> $documentos chaves de `CatalogoDeDocumentos::TIPOS` que a frase manda juntar
     */
    public function __construct(
        public readonly int $publicacaoId,
        public readonly string $tipoDaOrigem,
        public readonly ?\DateTimeImmutable $dataDaOrigem,
        public readonly ?string $idDoDocumento,
        public readonly string $ato,
        public readonly string $trecho,
        public readonly ?int $prazoQuantidade,
        public readonly ?string $prazoUnidade,
        public readonly ?string $prazoContagem,
        public readonly array $documentos,
    ) {
    }

    public function temPrazo(): bool
    {
        return $this->prazoQuantidade !== null;
    }

    /** "prazo de 15 dias úteis" — as palavras do teor, normalizadas; null sem prazo explícito. */
    public function prazoTexto(): ?string
    {
        if ($this->prazoQuantidade === null || $this->prazoUnidade === null) {
            return null;
        }

        $um      = $this->prazoQuantidade === 1;
        $unidade = $this->prazoUnidade === 'horas' ? ($um ? 'hora' : 'horas') : ($um ? 'dia' : 'dias');

        $contagem = match ($this->prazoContagem) {
            'uteis'    => $um ? ' útil' : ' úteis',
            'corridos' => $um ? ' corrido' : ' corridos',
            default    => '',
        };

        return 'prazo de ' . $this->prazoQuantidade . ' ' . $unidade . $contagem;
    }

    /** "Origem: Decisão de 03/09/2026 (ID 123456)" — o texto do botão do desenho (dsVals, dc L4033). */
    public function origemTexto(): string
    {
        return 'Origem: ' . $this->tipoDaOrigem
            . ($this->dataDaOrigem !== null ? ' de ' . $this->dataDaOrigem->format('d/m/Y') : '')
            . ($this->idDoDocumento !== null && $this->idDoDocumento !== '' ? ' (ID ' . $this->idDoDocumento . ')' : '');
    }

    /**
     * O prazo CERTAMENTE ainda corre em `$hoje`: a disponibilização mais N dias corridos não passou.
     *
     * É um limite inferior, de propósito: a contagem começa depois da publicação (dia útil seguinte
     * à disponibilização) e, em dias úteis, N úteis são no mínimo N corridos — então o prazo real
     * termina DEPOIS desta data. Pode deixar de mostrar um prazo que ainda corre; nunca mostra como
     * "em curso" um prazo que já acabou. Sem data ou sem prazo explícito: não se afirma nada.
     */
    public function prazoCertamenteEmCursoEm(\DateTimeImmutable $hoje): bool
    {
        if ($this->prazoQuantidade === null || $this->dataDaOrigem === null) {
            return false;
        }

        $dias = $this->prazoUnidade === 'horas'
            ? (int) ceil($this->prazoQuantidade / 24)
            : $this->prazoQuantidade;

        $limite = $this->dataDaOrigem->setTime(0, 0)->modify('+' . $dias . ' days');

        return $hoje->setTime(0, 0) <= $limite;
    }

    /** Linha de "Prazos em curso" (dc L2134): o ato, o prazo do teor e de onde ele veio. */
    public function linhaDePrazo(): string
    {
        return $this->ato . ': ' . (string) $this->prazoTexto()
            . ' (' . $this->tipoDaOrigem . ($this->dataDaOrigem !== null ? ' de ' . $this->dataDaOrigem->format('d/m/Y') : '') . ')';
    }
}
