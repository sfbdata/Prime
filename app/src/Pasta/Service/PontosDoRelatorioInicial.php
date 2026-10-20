<?php

declare(strict_types=1);

namespace App\Pasta\Service;

use App\Pasta\Entity\PastaObservacaoDetalhes;

/**
 * Card "Pontos do relatório inicial" da aba Detalhes e a pendência da aba que sai dele.
 *
 * É a regra de `relInicial()` do desenho (02 - EXPEDIENTES - VS 18.10.26, dc L.5486-5490),
 * copiada sem enfeite: junta o texto de TODAS as observações do Relatório inicial de
 * Atendimento (o `ctx === 'observacoes'` do desenho, respostas inclusive), normaliza
 * (minúsculas e sem acento, a mesma `SimilaridadeDeNomes::normalizar` do desenho) e testa
 * três padrões, cada um isoladamente:
 *
 *  · objetivos → "Objetivos do cliente";
 *  · acordos   → "Acordos iniciais";
 *  · resultado → "Resultado esperado pelo cliente".
 *
 * Vazio = nenhum texto (sem observação, ou só marcação). Completo = os três casaram.
 *
 * É regra de palavra, não prova: "objetivo" em qualquer frase conta como registrado. O
 * conteúdo chega em HTML sanitizado (editor rico) com tokens `@[Nome](user:ID)`: as tags
 * viram quebra de linha (os parágrafos do desenho se juntam com "\n", e assim as palavras de
 * parágrafos vizinhos não colam), as entidades são decodificadas e o token vira "@Nome",
 * que é o que a tela mostra.
 */
final readonly class PontosDoRelatorioInicial
{
    /** dc L.5489: chave, rótulo e padrão (sobre o texto normalizado), na ordem do card. */
    private const PONTOS = [
        ['objetivos', 'Objetivos do cliente', '/objetivo|pretens|pretende|busca(r)? |deseja|finalidade|o cliente quer|ajuizamento de/u'],
        ['acordos', 'Acordos iniciais', '/acord|combinad|ajustad|autoriz|ficou (definido|acertado)|definiu-se|alinhad|orientad/u'],
        ['resultado', 'Resultado esperado pelo cliente', '/resultado|expectativa|espera(-se| que| obter)|almeja|exito pretendido|pedido final/u'],
    ];

    /** dc L.6932 (`D`): a dica do ponto que falta. O registrado diz "Registrado no relatório". */
    private const DICAS = [
        'objetivos' => 'O que o cliente busca com a demanda',
        'acordos'   => 'O que foi combinado no atendimento',
        'resultado' => 'O que o cliente espera ao final',
    ];

    /**
     * @param list<array{k: string, rot: string, ok: bool, dica: string}> $itens os três pontos, na ordem do desenho
     */
    private function __construct(
        public array $itens,
        public bool $vazio,
    ) {
    }

    /** @param iterable<PastaObservacaoDetalhes> $observacoes */
    public static function dasObservacoes(iterable $observacoes): self
    {
        $textos = [];
        foreach ($observacoes as $observacao) {
            $textos[] = $observacao->getConteudo();
        }

        return self::dosTextos($textos);
    }

    /** @param list<string> $textos conteúdo das observações (HTML do editor rico ou texto puro) */
    public static function dosTextos(array $textos): self
    {
        $texto       = implode("\n", array_map(self::textoPuro(...), $textos));
        $normalizado = SimilaridadeDeNomes::normalizar($texto);

        $itens = [];
        foreach (self::PONTOS as [$chave, $rotulo, $padrao]) {
            $ok      = preg_match($padrao, $normalizado) === 1;
            $itens[] = [
                'k'    => $chave,
                'rot'  => $rotulo,
                'ok'   => $ok,
                'dica' => $ok ? 'Registrado no relatório' : self::DICAS[$chave],
            ];
        }

        // `T.trim()` do JS também apara o espaço inquebrável (o `&nbsp;` do editor); o trim do PHP, não.
        return new self($itens, preg_match('/[^\s\p{Z}]/u', $texto) !== 1);
    }

    /** @return list<array{k: string, rot: string, ok: bool, dica: string}> */
    public function faltam(): array
    {
        return array_values(array_filter($this->itens, static fn (array $item): bool => !$item['ok']));
    }

    public function completo(): bool
    {
        return $this->faltam() === [];
    }

    /** dc L.6932: "Completo" ou "N pendente(s)". */
    public function selo(): string
    {
        $n = count($this->faltam());
        if ($n === 0) {
            return 'Completo';
        }

        return $n . ' pendente' . ($n > 1 ? 's' : '');
    }

    /**
     * dc L.5504 (`out.detalhes`): a pendência da aba Detalhes, ou null quando está completo.
     *
     * @return array{n: int, txt: string}|null
     */
    public function pendenciaDaAba(): ?array
    {
        $faltam = $this->faltam();
        if ($faltam === []) {
            return null;
        }

        if ($this->vazio) {
            return ['n' => count($faltam), 'txt' => 'relatório inicial de atendimento ainda não preenchido'];
        }

        $rotulos = array_map(static fn (array $item): string => mb_strtolower($item['rot']), $faltam);
        $ultimo  = array_pop($rotulos);
        $lista   = $rotulos === [] ? $ultimo : implode(', ', $rotulos) . ' e ' . $ultimo;

        return ['n' => count($faltam), 'txt' => 'relatório inicial sem ' . $lista];
    }

    private static function textoPuro(string $conteudo): string
    {
        $semMencao = (string) preg_replace('/@\[([^\]]*)\]\(user:\d+\)/u', '@$1', $conteudo);
        $semTags   = strip_tags((string) preg_replace('/<[^>]*>/u', "\n$0", $semMencao));

        return html_entity_decode($semTags, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }
}
