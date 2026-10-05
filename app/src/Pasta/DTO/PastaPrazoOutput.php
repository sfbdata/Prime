<?php

declare(strict_types=1);

namespace App\Pasta\DTO;

use App\Entity\Tarefa\Tarefa;

/**
 * Uma linha do cartão "Próximos prazos" do trilho da aba Dados, já pronta para
 * impressão — a tela não calcula prazo nem decide cor.
 *
 * O cartão é um resumo das metas ABERTAS ordenadas pelo prazo mais próximo. Meta
 * sem prazo não entra: ela não tem posição nenhuma numa lista ordenada por data,
 * e inventar uma (fim da fila, começo da fila) diria ao usuário algo que o
 * sistema não sabe.
 */
final readonly class PastaPrazoOutput
{
    public const TOM_URGENTE   = 'urgente';
    public const TOM_PROXIMO   = 'proximo';
    public const TOM_TRANQUILO = 'tranquilo';

    public function __construct(
        public int $tarefaId,
        public string $titulo,
        /** "Jéssica Martins · 28/08" — responsável e dia, como no desenho. */
        public string $meta,
        /** "Faltam 2 dias", "Vence hoje", "Vencido há 3 dias" (textos do desenho 1.2.3). */
        public string $selo,
        /** urgente | proximo | tranquilo — decide a cor do pip e do selo. */
        public string $tom,
    ) {}

    /**
     * Monta as N metas abertas mais próximas do vencimento.
     *
     * @param iterable<Tarefa> $tarefas todas as metas da pasta
     *
     * @return list<self>
     */
    public static function proximas(iterable $tarefas, int $limite = 3): array
    {
        $abertas = [];
        foreach ($tarefas as $tarefa) {
            if ($tarefa->getStatus() === Tarefa::STATUS_CONCLUIDA) {
                continue;
            }
            if ($tarefa->getPrazo() === null) {
                continue;
            }
            $abertas[] = $tarefa;
        }

        usort(
            $abertas,
            static fn (Tarefa $a, Tarefa $b) => $a->getPrazo() <=> $b->getPrazo(),
        );

        return array_map(
            static fn (Tarefa $t) => self::deTarefa($t),
            array_slice($abertas, 0, $limite),
        );
    }

    public static function deTarefa(Tarefa $tarefa): self
    {
        $prazo = $tarefa->getPrazo();
        if ($prazo === null) {
            throw new \InvalidArgumentException('Meta sem prazo não vira linha de "Próximos prazos".');
        }

        $dias = self::diasAte($prazo);

        return new self(
            tarefaId: (int) $tarefa->getId(),
            titulo: $tarefa->getTitulo(),
            meta: self::montarMeta($tarefa, $prazo),
            selo: self::montarSelo($dias),
            tom: self::tomDosDias($dias),
        );
    }

    /**
     * Dias inteiros de HOJE até o prazo. Negativo = atrasado.
     *
     * A conta é feita entre datas (sem hora) dos dois lados: comparar "agora"
     * com um prazo gravado à meia-noite faria "vence hoje" virar "1 dia de
     * atraso" a partir das 00:01.
     */
    private static function diasAte(\DateTimeImmutable $prazo): int
    {
        $hoje = new \DateTimeImmutable('today');
        $alvo = $prazo->setTime(0, 0);

        return (int) $hoje->diff($alvo)->format('%r%a');
    }

    private static function montarMeta(Tarefa $tarefa, \DateTimeImmutable $prazo): string
    {
        $responsavel = $tarefa->getResponsavel()?->getFullName();

        $data = $prazo->format('d/m');

        return $responsavel !== null && $responsavel !== ''
            ? $responsavel . ' · ' . $data
            : $data;
    }

    private static function montarSelo(int $dias): string
    {
        if ($dias < 0) {
            $atraso = abs($dias);

            return $atraso === 1 ? 'Vencido há 1 dia' : 'Vencido há ' . $atraso . ' dias';
        }

        if ($dias === 0) {
            return 'Vence hoje';
        }

        return $dias === 1 ? 'Falta 1 dia' : 'Faltam ' . $dias . ' dias';
    }

    /**
     * Faixas do desenho 1.2.3: vermelho até 3 dias, âmbar até 7, cinza acima.
     * Atrasado cai no vermelho por ser o caso mais grave dos três.
     */
    private static function tomDosDias(int $dias): string
    {
        if ($dias <= 3) {
            return self::TOM_URGENTE;
        }

        if ($dias <= 7) {
            return self::TOM_PROXIMO;
        }

        return self::TOM_TRANQUILO;
    }
}
