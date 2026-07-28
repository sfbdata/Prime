<?php

declare(strict_types=1);

namespace App\Cobranca\Twig;

use App\Cobranca\Enum\TipoTelefone;
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
            new TwigFilter('telefone_br', $this->telefoneBr(...)),
            new TwigFilter('telefone_url', $this->telefoneUrl(...)),
        ];
    }

    /**
     * Número cru → máscara brasileira (2026-07-28): `(99) 99999-9999` com 11 dígitos, `(99) 9999-9999`
     * com 10. Sem DDD, agrupa o que dá: `99999-9999` com 9 e `9999-9999` com 8.
     *
     * A regra é a CONTAGEM DE DÍGITOS, não o tipo do telefone: WhatsApp/fixo é capacidade de contato,
     * e um fixo comercial com WhatsApp continua tendo 10 dígitos.
     *
     * Qualquer outra contagem volta EXATAMENTE como está — inclusive lixo de importação ("teste",
     * 7 dígitos, 12 dígitos). Formatar o que não bate exigiria inventar ou descartar dígito, e o
     * número errado formatado bonito é pior que o número errado à vista: ele deixa de parecer errado.
     */
    public function telefoneBr(?string $numero): string
    {
        $numero = trim((string) $numero);
        $digitos = preg_replace('/\D/', '', $numero) ?? '';

        return match (strlen($digitos)) {
            11 => sprintf('(%s) %s-%s', substr($digitos, 0, 2), substr($digitos, 2, 5), substr($digitos, 7)),
            10 => sprintf('(%s) %s-%s', substr($digitos, 0, 2), substr($digitos, 2, 4), substr($digitos, 6)),
            9 => sprintf('%s-%s', substr($digitos, 0, 5), substr($digitos, 5)),
            8 => sprintf('%s-%s', substr($digitos, 0, 4), substr($digitos, 4)),
            default => $numero,
        };
    }

    /**
     * Para onde o número leva ao ser clicado. WhatsApp com número discável (10 ou 11 dígitos) abre a
     * conversa em `wa.me`; todo o resto continua em `tel:`, como sempre foi.
     *
     * O `55` é fixo porque `wa.me` exige DDI e a base é brasileira — o mesmo pressuposto que a máscara
     * de exibição já faz. Número torto marcado como WhatsApp (importação suja, 7 dígitos) NÃO vira
     * `wa.me`: o link abriria uma conversa com ninguém. Cai em `tel:`, que ao menos disca o que existe.
     */
    public function telefoneUrl(?string $numero, ?TipoTelefone $tipo = null): string
    {
        $numero = trim((string) $numero);
        $digitos = preg_replace('/\D/', '', $numero) ?? '';

        if ($tipo === TipoTelefone::WhatsApp && \in_array(strlen($digitos), [10, 11], true)) {
            return 'https://wa.me/55' . $digitos;
        }

        return 'tel:' . rawurlencode($numero);
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
