<?php

declare(strict_types=1);

namespace App\Cobranca\Twig;

use Twig\Extension\AbstractExtension;
use Twig\TwigFilter;

/**
 * Apresentação da Gestão de Cobranças: dinheiro e tempo. O domínio guarda dinheiro em CENTAVOS
 * inteiros (invariável de precisão financeira das Etapas 2–3); esta é a ÚNICA camada que
 * converte para exibição — os Output DTOs carregam o int cru e o Twig formata com `|centavos`.
 * Nunca fazer aritmética de dinheiro no template; só formatar.
 */
final class CobrancaExtension extends AbstractExtension
{
    public function getFilters(): array
    {
        return [
            new TwigFilter('centavos', $this->centavos(...)),
            new TwigFilter('centavos_curto', $this->centavosCurto(...)),
            new TwigFilter('tempo_relativo', $this->tempoRelativo(...)),
        ];
    }

    /** Centavos inteiros → "R$ 1.234,56". */
    public function centavos(?int $centavos): string
    {
        return 'R$ ' . $this->centavosCurto($centavos);
    }

    /**
     * Centavos inteiros → "1.234,56", SEM o prefixo "R$" (encargos separados, spec §11).
     *
     * A linha da obrigação passou a ter seis colunas de dinheiro (Original · Juros · Multa · Correção ·
     * Honorários · Total) e o subtexto que as resume nas telas estreitas. Repetir "R$" em cada célula
     * gasta a largura que falta para as colunas caberem e não acrescenta informação: o cabeçalho já diz
     * que a coluna é dinheiro e o Total — a âncora da leitura — continua com o símbolo.
     * Mesma aritmética do `centavos` (é ele que delega para cá), para as duas formas nunca divergirem.
     */
    public function centavosCurto(?int $centavos): string
    {
        return number_format(($centavos ?? 0) / 100, 2, ',', '.');
    }

    /**
     * Distância em dias até hoje, em português ("há 128 dias" / "em 25 dias" / "hoje") — o eixo temporal
     * das listas do objeto (Ajuste 10, spec §4.7).
     *
     * Compara DATAS, não instantes: o default é `today` (meia-noite). Com "agora", uma obrigação que vence
     * hoje viraria "há 1 dia" à tarde — a resposta mudaria conforme a hora de abrir a página.
     * `$hoje` é injetável só para teste.
     */
    public function tempoRelativo(\DateTimeInterface $data, ?\DateTimeInterface $hoje = null): string
    {
        $hoje ??= new \DateTimeImmutable('today');
        $dias = (int) $hoje->diff($data)->format('%r%a');

        if ($dias === 0) {
            return 'hoje';
        }

        if ($dias < 0) {
            $passados = abs($dias);

            return sprintf('há %d %s', $passados, $passados === 1 ? 'dia' : 'dias');
        }

        return sprintf('em %d %s', $dias, $dias === 1 ? 'dia' : 'dias');
    }
}
