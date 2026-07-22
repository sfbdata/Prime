<?php

declare(strict_types=1);

namespace App\Cobranca\Service;

use App\Cobranca\DTO\DesfechoContatoOutput;
use App\Cobranca\Enum\CanalContato;
use App\Cobranca\Enum\ResultadoContato;

/**
 * Traduz a contagem crua do payload do contato (valor → quantidade, como sai do repositório) nas
 * pastilhas exibidas na Central: **desfechos** (`dados->>'resultado'`) e **canais** (`dados->>'canal'`).
 *
 * Mora aqui, e não em cada UseCase, porque as duas telas usam a mesma regra: a faixa de canais acima da
 * tabela (setor inteiro) e as pastilhas do detalhe (uma pessoa) têm de contar e rotular igual — se
 * divergirem, o gestor vê dois números para a mesma coisa.
 *
 * Regra comum às duas: a RÉGUA (o que o formulário oferece hoje) aparece inteira, mesmo zerada — é ela
 * que dá a noção de proporção. Fora da régua, só entra o que de fato ocorreu, e **nada some em
 * silêncio**: valor desconhecido é exibido cru, ausência de valor vira "Não informado".
 */
final class PastilhasDeContato
{
    public const CHAVE_RESULTADO = 'resultado';
    public const CHAVE_CANAL = 'canal';

    private const SEM_VALOR = 'Não informado';

    /**
     * Desfechos. "Atendido" vem primeiro por ser a medida de efetividade que a coluna "Falou com o
     * devedor" resume. `prometeu_pagar` ficou fora da régua (saiu do formulário no ajuste de 2026-07) e
     * só aparece se houver ocorrência — pastilha zerada dele seria pedir um desfecho que ninguém
     * consegue mais registrar.
     *
     * @param array<string, int> $contagem
     *
     * @return list<DesfechoContatoOutput>
     */
    public function desfechos(array $contagem): array
    {
        $regua = array_merge(
            [ResultadoContato::Atendido],
            array_values(array_filter(
                ResultadoContato::selecionaveis(),
                static fn (ResultadoContato $r): bool => $r !== ResultadoContato::Atendido,
            )),
        );

        return $this->montar(
            $contagem,
            array_map(static fn (ResultadoContato $r): array => [$r->value, $r->label()], $regua),
            static fn (string $valor): ?string => ResultadoContato::tryFrom($valor)?->label(),
        );
    }

    /**
     * Canais. A régua é o enum inteiro (`CanalContato` não tem caso aposentado), na ordem em que o
     * formulário oferece.
     *
     * @param array<string, int> $contagem
     *
     * @return list<DesfechoContatoOutput>
     */
    public function canais(array $contagem): array
    {
        return $this->montar(
            $contagem,
            array_map(static fn (CanalContato $c): array => [$c->value, $c->label()], CanalContato::cases()),
            static fn (string $valor): ?string => CanalContato::tryFrom($valor)?->label(),
        );
    }

    /**
     * @param array<string, int>          $contagem
     * @param list<array{0: string, 1: string}> $regua      pares valor→rótulo sempre exibidos
     * @param callable(string): ?string   $rotuloDeFora rótulo de um valor fora da régua, se conhecido
     *
     * @return list<DesfechoContatoOutput>
     */
    private function montar(array $contagem, array $regua, callable $rotuloDeFora): array
    {
        $pastilhas = [];

        foreach ($regua as [$valor, $rotulo]) {
            $pastilhas[] = new DesfechoContatoOutput($rotulo, $contagem[$valor] ?? 0);
            unset($contagem[$valor]);
        }

        foreach ($contagem as $valorCru => $quantidade) {
            if ($quantidade <= 0) {
                continue;
            }

            $valorCru = (string) $valorCru;
            $rotulo = $rotuloDeFora($valorCru) ?? ($valorCru === '' ? self::SEM_VALOR : $valorCru);

            $pastilhas[] = new DesfechoContatoOutput($rotulo, $quantidade);
        }

        return $pastilhas;
    }
}
