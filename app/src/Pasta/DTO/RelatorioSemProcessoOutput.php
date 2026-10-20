<?php

declare(strict_types=1);

namespace App\Pasta\DTO;

/**
 * O relatório geral de processos das pastas (desenho 1.2.3, `spHTML` L4538-4555), pronto para a
 * folha: as análises em ordem de número, as contagens, o histórico dos envios automáticos (para a
 * "Evolução") e quantas decisões da equipe já ajustaram a leitura (nota final).
 */
final class RelatorioSemProcessoOutput
{
    public const TITULO = 'Relatório geral · Processos das pastas';

    /**
     * @param list<AnaliseSemProcessoDaPasta>                     $analises  em ordem de número da pasta
     * @param list<array{mes: string, resumo: ResumoSemProcesso}> $historico do envio mais antigo ao mais novo (até 6)
     */
    public function __construct(
        public readonly \DateTimeImmutable $hoje,
        public readonly array $analises,
        public readonly ResumoSemProcesso $resumo,
        public readonly array $historico,
        public readonly int $totalDecisoes,
    ) {
    }

    public function titulo(): string
    {
        return self::TITULO . ' · ' . $this->hoje->format('d/m/Y');
    }

    /**
     * Os cinco cartões do topo (L4542): rótulo, valor e o tom da cor do número.
     *
     * @return list<array{rotulo: string, valor: int, tom: string}>
     */
    public function cartoes(): array
    {
        return [
            ['rotulo' => 'Pastas', 'valor' => \count($this->analises), 'tom' => 'azul'],
            ['rotulo' => 'Sem processo', 'valor' => $this->resumo->sem, 'tom' => 'azul'],
            ['rotulo' => 'Deveriam ter processo', 'valor' => $this->resumo->deveria, 'tom' => 'laranja'],
            ['rotulo' => 'Administrativas', 'valor' => \count($this->daCategoria(AnaliseSemProcessoDaPasta::ADMINISTRATIVA, AnaliseSemProcessoDaPasta::REVER)), 'tom' => 'azul'],
            ['rotulo' => 'Rever cadastro', 'valor' => $this->resumo->rever, 'tom' => 'vermelho'],
        ];
    }

    /**
     * As pastas das categorias pedidas, NA ORDEM das categorias e, dentro de cada uma, por número
     * (`G('rever').concat(G('inconsistente'))`, L4550).
     *
     * @return list<AnaliseSemProcessoDaPasta>
     */
    public function daCategoria(string ...$categorias): array
    {
        $lista = [];
        foreach ($categorias as $categoria) {
            foreach ($this->analises as $analise) {
                if ($analise->categoria === $categoria) {
                    $lista[] = $analise;
                }
            }
        }

        return $lista;
    }

    /**
     * "Resumo para despacho" (L4543): só as pastas com resumo, por número.
     *
     * @return list<AnaliseSemProcessoDaPasta>
     */
    public function paraDespacho(): array
    {
        return array_values(array_filter($this->analises, static fn (AnaliseSemProcessoDaPasta $a): bool => $a->resumo !== ''));
    }

    public function ultimoEnvio(): ?ResumoSemProcesso
    {
        $chave = array_key_last($this->historico);

        return $chave !== null ? $this->historico[$chave]['resumo'] : null;
    }

    /** Hoje menos o último envio, numa coluna da Evolução (L4546, `dl`). Zero sem histórico. */
    public function delta(string $campo): int
    {
        $ultimo = $this->ultimoEnvio();

        return $ultimo !== null ? $this->resumo->valor($campo) - $ultimo->valor($campo) : 0;
    }
}
