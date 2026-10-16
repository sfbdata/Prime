<?php

declare(strict_types=1);

namespace App\Pasta\DTO;

use App\Entity\Tarefa\Tarefa;
use App\Pasta\Entity\PastaPrazo;

/**
 * Uma linha do cartão "Próximos prazos" do trilho da aba Dados, já pronta para
 * impressão — a tela não calcula prazo nem decide cor.
 *
 * Desenho 1.2.3 (`pzLista`, E12b): o cartão junta os prazos e audiências registrados à mão (origem "Manual",
 * não cumpridos) e as metas ABERTAS com prazo (origem "Meta"), do mais próximo ao mais distante. Meta sem prazo
 * não entra: ela não tem posição numa lista ordenada por data. Os prazos lidos do Push e das datas escritas nos
 * registros ainda não entram (não há leitor de prazos no servidor).
 */
final readonly class PastaPrazoOutput
{
    public const TOM_URGENTE   = 'urgente';
    public const TOM_PROXIMO   = 'proximo';
    public const TOM_TRANQUILO = 'tranquilo';

    public function __construct(
        /** A meta de origem; null no prazo manual. */
        public ?int $tarefaId,
        /** Com "Audiência · " na frente quando é audiência, como o desenho. */
        public string $titulo,
        /** "Jéssica Martins · 28/08 14:30 · Manual" (responsável, dia, hora e origem, desenho 1.2.3). */
        public string $meta,
        /** "Faltam 2 dias", "Hoje", "Vencido há 3 dias" (textos do desenho 1.2.3). */
        public string $selo,
        /** urgente | proximo | tranquilo — decide a cor do pip e do selo. */
        public string $tom,
        /** O prazo manual; null na meta. */
        public ?int $prazoId = null,
        public string $tipo = PastaPrazo::TIPO_PRAZO,
        public string $origem = 'Meta',
        /** Y-m-d, para o .ics e para editar. */
        public string $dataIso = '',
        public ?string $hora = null,
        public ?string $responsavel = null,
        /** Descrição sem o prefixo de audiência (o que se edita). */
        public string $descricao = '',
        public int $dias = 0,
    ) {}

    /**
     * Todos os itens do cartão, do mais próximo ao mais distante: prazos manuais não cumpridos e metas abertas com
     * prazo. A tela mostra os 8 primeiros e conta todos.
     *
     * @param iterable<Tarefa>     $tarefas metas da pasta
     * @param iterable<PastaPrazo> $manuais prazos manuais da pasta
     *
     * @return list<self>
     */
    public static function daPasta(iterable $tarefas, iterable $manuais): array
    {
        $itens = [];
        foreach ($manuais as $prazo) {
            if (!$prazo->estaCumprido()) {
                $itens[] = self::dePrazo($prazo);
            }
        }
        foreach ($tarefas as $tarefa) {
            if ($tarefa->getStatus() !== Tarefa::STATUS_CONCLUIDA && $tarefa->getPrazo() !== null) {
                $itens[] = self::deTarefa($tarefa);
            }
        }
        usort($itens, static fn (self $a, self $b): int => [$a->dias, $a->hora ?? ''] <=> [$b->dias, $b->hora ?? '']);

        return $itens;
    }

    public static function dePrazo(PastaPrazo $prazo): self
    {
        $dias = self::diasAte($prazo->getData());
        $meta = ($prazo->getResponsavel() ?: 'Sem responsável') . ' · ' . $prazo->getData()->format('d/m')
            . ($prazo->getHora() ? ' ' . $prazo->getHora() : '') . ' · Manual';

        return new self(
            tarefaId: null,
            titulo: ($prazo->isAudiencia() ? 'Audiência · ' : '') . $prazo->getTitulo(),
            meta: $meta,
            selo: self::montarSelo($dias),
            tom: self::tomDosDias($dias),
            prazoId: $prazo->getId(),
            tipo: $prazo->getTipo(),
            origem: 'Manual',
            dataIso: $prazo->getData()->format('Y-m-d'),
            hora: $prazo->getHora(),
            responsavel: $prazo->getResponsavel(),
            descricao: $prazo->getTitulo(),
            dias: $dias,
        );
    }

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
            origem: 'Meta',
            dataIso: $prazo->format('Y-m-d'),
            responsavel: $tarefa->getResponsavel()?->getFullName(),
            descricao: $tarefa->getTitulo(),
            dias: $dias,
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

        return ($responsavel !== null && $responsavel !== '' ? $responsavel : 'Sem responsável')
            . ' · ' . $prazo->format('d/m') . ' · Meta';
    }

    private static function montarSelo(int $dias): string
    {
        if ($dias < 0) {
            $atraso = abs($dias);

            return $atraso === 1 ? 'Vencido há 1 dia' : 'Vencido há ' . $atraso . ' dias';
        }

        if ($dias === 0) {
            return 'Hoje';
        }

        return $dias === 1 ? 'Faltam 1 dia' : 'Faltam ' . $dias . ' dias';
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
