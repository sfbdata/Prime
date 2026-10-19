<?php

declare(strict_types=1);

namespace App\Tarefa\Service;

use App\Agenda\Service\DiasUteisNacionais;
use App\Tarefa\DTO\AnaliseDoPrazo;
use App\Tarefa\DTO\EntradaDaAnaliseDoPrazo;
use App\Tarefa\Enum\EstadoDoPrazoJuridico;

/**
 * Motor da análise do prazo da meta (desenho 02, "Nova meta", `IA_PRAZO`, dc 3971-4026): entende o texto da
 * meta, separa o prazo jurídico do interno, mede a folga e a urgência. É REGRA, não modelo: tabela de tipos do
 * CPC, datas escritas no texto e o calendário de dias úteis do sistema (`DiasUteisNacionais`, a fonte única:
 * fim de semana, feriados nacionais com os móveis e o recesso de 20/12 a 20/01).
 *
 * Só o motor, sem tela: o rótulo ("I.A." numa regra) e mostrar um prazo legal sugerido estão com o dono
 * (spec `docs/specs/meta-analise-do-prazo.md`). "Nunca afirma o que não sabe": sem termo inicial, a data é
 * aproximada e o estado pede conferência.
 */
final class AnaliseDoPrazoDaMeta
{
    /**
     * [regex, tipo, dias úteis do prazo legal, fonte, folga em dias úteis, peso] (dc 3975-3984). A ordem conta:
     * o primeiro que casa vence.
     */
    private const TIPOS = [
        ['/contesta/', 'Contestação', 15, 'CPC, art. 335', 4, 3],
        ['/replica|impugnacao a contestacao/', 'Réplica', 15, 'CPC, art. 351', 3, 2],
        ['/embargos? de declara/', 'Embargos de declaração', 5, 'CPC, art. 1.023', 1, 3],
        ['/apela/', 'Apelação', 15, 'CPC, art. 1.003, § 5º', 4, 3],
        ['/agravo de instrumento/', 'Agravo de instrumento', 15, 'CPC, art. 1.003, § 5º', 4, 3],
        ['/agravo interno/', 'Agravo interno', 15, 'CPC, art. 1.070', 4, 3],
        ['/contrarraz/', 'Contrarrazões', 15, 'CPC, art. 1.010, § 1º', 4, 2],
        ['/impugnacao ao cumprimento/', 'Impugnação ao cumprimento', 15, 'CPC, art. 525', 4, 3],
        ['/recurso especial|recurso extraordinario/', 'Recurso excepcional', 15, 'CPC, art. 1.003, § 5º', 5, 3],
        ['/recurso/', 'Recurso', 15, 'CPC, art. 1.003, § 5º (conferir a espécie)', 4, 3],
        ['/manifesta|intima/', 'Manifestação', 5, 'CPC, art. 218, § 3º (quando o juiz não fixar prazo)', 1, 2],
        ['/audiencia/', 'Audiência', 0, '', 2, 3],
        ['/protocol|peticion|peticao/', 'Petição', 0, '', 1, 1],
        ['/publicac|analis/', 'Análise de publicação', 0, '', 0, 1],
        ['/cadastr|corrig|atualiz/', 'Ajuste de cadastro', 0, '', 0, 0],
        ['/document|junta|anex/', 'Juntada de documentos', 0, '', 0, 1],
        ['/client|ligar|contato|retorn/', 'Retorno ao cliente', 0, '', 0, 1],
    ];
    private const SEM_TIPO = [null, 'Tarefa interna', 0, '', 0, 0];
    private const DIAS_DA_SEMANA = ['domingo', 'segunda', 'terca', 'quarta', 'quinta', 'sexta', 'sabado'];
    private const PRAZO_INTERNO_POR_PESO = [0 => 2, 1 => 3, 2 => 2, 3 => 1];

    public function __construct(
        private readonly DiasUteisNacionais $diasUteis,
    ) {
    }

    /** Null quando o texto é curto demais para dizer algo (menos de 6 letras, como no desenho). */
    public function analisar(EntradaDaAnaliseDoPrazo $e): ?AnaliseDoPrazo
    {
        $texto = self::normalizar($e->texto);
        if (mb_strlen((string) preg_replace('/\s/u', '', $texto)) < 6) {
            return null;
        }

        $hoje = $e->hoje->setTime(0, 0);
        [, $tipo, $du, $fonte, $margem, $peso] = self::tipoDe($texto);
        [$explicita, $como] = $this->dataEscrita($texto, $hoje);
        $urgente = preg_match('/urgent|urgencia|imediat|critic|liminar|tutela/', $texto) === 1;
        $termo = $e->ciencia?->setTime(0, 0) ?? ($e->publicacao !== null ? $this->diasUteis->somarUteis($e->publicacao, 1) : null);
        $origem = $e->ciencia !== null ? 'ciencia' : ($e->publicacao !== null ? 'publicacao' : null);

        [$juridico, $estado, $aproximado, $alternativa] = $this->prazoJuridico($e, $explicita, $termo, $du, $hoje);
        [$interno, $antecipou] = $this->prazoInterno($juridico, $margem, $peso, $urgente, $hoje);
        $restam = $this->uteisEntre($hoje, $interno);
        [$diasJur, $feriados] = $juridico === null ? [null, 0] : $this->contarAte($hoje, $juridico);

        return new AnaliseDoPrazo(
            $tipo,
            $du,
            $fonte,
            $juridico,
            $aproximado,
            $estado,
            $interno,
            $juridico !== null ? $margem : null,
            self::prioridade($peso, $urgente, $restam),
            $urgente,
            $restam,
            $diasJur,
            $feriados,
            $termo,
            $origem,
            $alternativa,
            self::porque($e, $estado, $juridico, $explicita, $como, $termo, $origem, $tipo, $du, $fonte, $margem, $urgente, $antecipou),
        );
    }

    private static function normalizar(string $texto): string
    {
        $semAcento = (string) preg_replace('/\p{Mn}/u', '', (string) \Normalizer::normalize($texto, \Normalizer::FORM_D));

        return mb_strtolower($semAcento);
    }

    /** O tipo sai primeiro do título (1ª linha) e só depois do texto todo (dc 3986). @return array{?string, string, int, string, int, int} */
    private static function tipoDe(string $texto): array
    {
        $titulo = explode("\n", $texto)[0];
        foreach ([$titulo, $texto] as $onde) {
            foreach (self::TIPOS as $tipo) {
                if (preg_match($tipo[0], $onde) === 1) {
                    return $tipo;
                }
            }
        }

        return self::SEM_TIPO;
    }

    /**
     * Data escrita na meta (dc 3989-3997): "dd/mm[/aa]", "hoje", "amanhã", "em|prazo de|até N dias [úteis]" e
     * "até <dia da semana>". Devolve a data e como foi achada.
     *
     * @return array{?\DateTimeImmutable, string}
     */
    private function dataEscrita(string $t, \DateTimeImmutable $hoje): array
    {
        if (preg_match('/(\d{1,2})\/(\d{1,2})(?:\/(\d{2,4}))?/', $t, $m) === 1) {
            $data = self::dataDoTexto($m, $hoje);
            if ($data !== null) {
                return [$data, 'data ' . $data->format('d/m/Y') . ' escrita na meta'];
            }
        }
        if (preg_match('/\bhoje\b/', $t) === 1) {
            return [$hoje, 'a palavra "hoje"'];
        }
        if (preg_match('/\bamanha\b/', $t) === 1) {
            return [$this->diasUteis->somarUteis($hoje, 1), 'a palavra "amanhã"'];
        }
        if (preg_match('/(?:em|prazo de|ate)\s+(\d{1,2})\s+dias?(\s+uteis)?/', $t, $m) === 1) {
            $n = (int) $m[1];
            $data = ($m[2] ?? '') !== '' ? $this->diasUteis->somarUteis($hoje, $n) : $hoje->modify('+' . $n . ' days');

            return [$data, '"' . $m[0] . '"'];
        }
        if (preg_match('/ate (?:a |o )?(segunda|terca|quarta|quinta|sexta|sabado|domingo)/', $t, $m) === 1) {
            $alvo = array_search($m[1], self::DIAS_DA_SEMANA, true);
            $data = $hoje->modify('+1 day');
            while ((int) $data->format('w') !== $alvo) {
                $data = $data->modify('+1 day');
            }

            return [$data, '"até ' . $m[1] . '"'];
        }

        return [null, ''];
    }

    /** @param array<int, string> $m */
    private static function dataDoTexto(array $m, \DateTimeImmutable $hoje): ?\DateTimeImmutable
    {
        $ano = isset($m[3]) && $m[3] !== '' ? (int) $m[3] : (int) $hoje->format('Y');
        if ($ano < 100) {
            $ano += 2000;
        }
        if (!checkdate((int) $m[2], (int) $m[1], $ano)) {
            return null;
        }
        $data = $hoje->setDate($ano, (int) $m[2], (int) $m[1]);

        return (!isset($m[3]) || $m[3] === '') && $data < $hoje ? $data->modify('+1 year') : $data;
    }

    /**
     * Prioridade (dc 4004-4007): a data informada pela pessoa; a escrita no texto; contada do termo inicial pela
     * tabela; contada de hoje, aproximada. Sem prazo legal no tipo, não há prazo jurídico.
     *
     * @return array{?\DateTimeImmutable, EstadoDoPrazoJuridico, bool, ?\DateTimeImmutable}
     */
    private function prazoJuridico(EntradaDaAnaliseDoPrazo $e, ?\DateTimeImmutable $explicita, ?\DateTimeImmutable $termo, int $du, \DateTimeImmutable $hoje): array
    {
        if ($e->prazoJuridicoInformado !== null) {
            $informado = $e->prazoJuridicoInformado->setTime(0, 0);
            $pelaOrigem = $du > 0 && $termo !== null ? $this->diasUteis->somarUteis($termo, $du) : null;
            $alternativa = $pelaOrigem !== null && $pelaOrigem->format('Y-m-d') !== $informado->format('Y-m-d') ? $pelaOrigem : null;

            return [$informado, $e->confirmado ? EstadoDoPrazoJuridico::Confirmado : EstadoDoPrazoJuridico::Informado, false, $alternativa];
        }
        if ($explicita !== null) {
            return [$explicita, EstadoDoPrazoJuridico::Informado, false, null];
        }
        if ($du > 0 && $termo !== null) {
            return [$this->diasUteis->somarUteis($termo, $du), EstadoDoPrazoJuridico::Estimado, false, null];
        }
        if ($du > 0) {
            return [$this->diasUteis->somarUteis($hoje, $du), EstadoDoPrazoJuridico::NecessitaConferencia, true, null];
        }

        return [null, EstadoDoPrazoJuridico::SemPrazoLegal, false, null];
    }

    /**
     * Prazo interno (dc 4008-4012): o jurídico menos a folga; sem jurídico, 1 a 3 dias úteis conforme o peso.
     * Urgência antecipa para o próximo dia útil; dia não útil recua para o útil anterior.
     *
     * @return array{\DateTimeImmutable, bool}
     */
    private function prazoInterno(?\DateTimeImmutable $juridico, int $margem, int $peso, bool $urgente, \DateTimeImmutable $hoje): array
    {
        $interno = $juridico !== null
            ? $this->voltarUteis($juridico, $margem, $hoje)
            : $this->diasUteis->somarUteis($hoje, $urgente ? 1 : (self::PRAZO_INTERNO_POR_PESO[$peso] ?? 3));
        $proximoUtil = $this->diasUteis->somarUteis($hoje, 1);
        $antecipou = $urgente && $interno > $proximoUtil;
        if ($antecipou) {
            $interno = $proximoUtil;
        }
        if (!$this->diasUteis->ehDiaUtil($interno) && $interno > $hoje) {
            $interno = $this->voltarUteis($interno, 1, $hoje);
        }

        return [$interno, $antecipou];
    }

    /** `voltaUteis` do desenho: recua N dias úteis, sem passar de `$minimo`. */
    private function voltarUteis(\DateTimeImmutable $alvo, int $n, \DateTimeImmutable $minimo): \DateTimeImmutable
    {
        $dia = $alvo;
        $contados = 0;
        while ($contados < $n && $dia > $minimo) {
            $dia = $dia->modify('-1 day');
            if ($this->diasUteis->ehDiaUtil($dia)) {
                ++$contados;
            }
        }

        return $dia < $minimo ? $minimo : $dia;
    }

    private function uteisEntre(\DateTimeImmutable $de, \DateTimeImmutable $ate): int
    {
        return $this->contarAte($de, $ate)[0];
    }

    /**
     * Dias úteis depois de `$de` até `$ate` (inclusive) e os dias de semana não úteis no meio (feriados e
     * recesso), como o `diasJur`/`feriados` do desenho (dc 4024-4025).
     *
     * @return array{int, int}
     */
    private function contarAte(\DateTimeImmutable $de, \DateTimeImmutable $ate): array
    {
        $uteis = 0;
        $feriados = 0;
        for ($dia = $de->modify('+1 day'); $dia <= $ate; $dia = $dia->modify('+1 day')) {
            if ($this->diasUteis->ehDiaUtil($dia)) {
                ++$uteis;
            } elseif ((int) $dia->format('N') < 6) {
                ++$feriados;
            }
        }

        return [$uteis, $feriados];
    }

    /** Pontuação do desenho (dc 4013-4015): peso, urgência e proximidade do prazo interno. */
    private static function prioridade(int $peso, bool $urgente, int $restam): string
    {
        $proximidade = match (true) {
            $restam <= 0 => 4,
            $restam <= 1 => 3,
            $restam <= 3 => 2,
            $restam <= 6 => 1,
            default => 0,
        };
        $pontos = $peso * 2 + ($urgente ? 4 : 0) + $proximidade;

        return match (true) {
            $pontos >= 9 => 'Crítico',
            $pontos >= 7 => 'Urgente',
            $pontos >= 5 => 'Importante',
            $pontos >= 3 => 'Atenção',
            default => 'Normal',
        };
    }

    /** O "porquê" literal do desenho (dc 4017-4023). */
    private static function porque(EntradaDaAnaliseDoPrazo $e, EstadoDoPrazoJuridico $estado, ?\DateTimeImmutable $juridico, ?\DateTimeImmutable $explicita, string $como, ?\DateTimeImmutable $termo, ?string $origem, string $tipo, int $du, string $fonte, int $margem, bool $urgente, bool $antecipou): string
    {
        $folga = $margem . ($margem > 1 ? ' dias úteis' : ' dia útil');
        $reservei = $margem > 0 ? 'Reservei ' . $folga . ' antes para revisão e protocolo.' : 'Mantive a data como prazo interno.';
        $texto = match (true) {
            $e->prazoJuridicoInformado !== null && $juridico !== null => 'Usei o prazo jurídico ' . ($estado === EstadoDoPrazoJuridico::Confirmado ? 'confirmado' : 'informado') . ' por você (' . $juridico->format('d/m/Y') . '). ' . $reservei,
            $termo !== null && $du > 0 => 'Contei ' . $du . ' dias úteis (' . $fonte . ') a partir do termo inicial ' . $termo->format('d/m/Y') . ($origem === 'ciencia' ? ', dia da ciência/intimação informado por você' : ', 1º dia útil após a publicação de ' . $e->publicacao?->format('d/m/Y')) . '. Prazo interno com ' . $folga . ' de folga.',
            $explicita !== null => 'Encontrei ' . $como . '. ' . $reservei,
            $du > 0 => 'A meta indica ' . mb_strtolower($tipo) . ': ' . $du . ' dias úteis (' . $fonte . '). O termo inicial não está na meta, então contei a partir de hoje só como estimativa; confira a intimação. Prazo interno com ' . $folga . ' de folga.',
            default => 'Não há prazo legal ligado a ' . mb_strtolower($tipo) . '. Sugeri um prazo interno conforme a natureza da tarefa' . ($urgente ? ' e a urgência indicada no texto' : '') . '.',
        };
        if ($antecipou) {
            $texto .= ' Como o texto indica urgência, antecipei o prazo interno para o próximo dia útil.';
        }

        return $texto . ' Fins de semana, feriados nacionais e o recesso de 20/12 a 20/01 não contam.';
    }
}
