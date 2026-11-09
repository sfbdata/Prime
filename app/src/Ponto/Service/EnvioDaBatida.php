<?php

declare(strict_types=1);

namespace App\Ponto\Service;

/**
 * Os campos do envio na batida (spec ponto-desenho-0811 §3 "Hora do servidor" e "Fila sem internet", §5 E13 e E14): o
 * identificador do envio, feito no navegador no toque e repetido em toda nova tentativa, e a hora do relógio do
 * aparelho. Os dois são opcionais: sem eles a batida segue como sempre (o atalho antigo e a página em cache continuam
 * funcionando).
 *
 * Na batida normal a hora oficial é a do servidor na chegada, um único instante por pedido, e a hora do aparelho fica só
 * como informação: inválida ou ausente, vira null e nunca recusa.
 *
 * Na batida guardada sem internet (`semInternet: true`, E14) vale a hora do aparelho, dentro de dois limites: no máximo
 * {@see self::TETO_DA_FILA_HORAS} horas antes da chegada (decisão do Coordenador de 09/11/2026) e no máximo
 * {@see self::TOLERANCIA_DO_RELOGIO_SEGUNDOS} segundos à frente dela; dentro da tolerância, vale a chegada. A batida
 * guardada em outra conta ou outro escritório volta 409, e a fila a mantém guardada.
 */
final class EnvioDaBatida
{
    /** O teto de idade da batida guardada sem internet (P20, decisão do Coordenador de 09/11/2026). */
    public const TETO_DA_FILA_HORAS = 72;

    /** Quanto o relógio do aparelho pode estar à frente da hora oficial sem recusar a batida guardada. */
    public const TOLERANCIA_DO_RELOGIO_SEGUNDOS = 120;

    public const MENSAGEM_ID_INVALIDO = 'Identificador do envio inválido.';
    public const MENSAGEM_JA_REGISTRADA = 'Esta batida já tinha sido registrada.';
    public const MENSAGEM_FILA_INCOMPLETA = 'A batida guardada sem internet chegou incompleta e não foi registrada.';
    public const MENSAGEM_OUTRA_CONTA = 'Esta batida foi guardada em outra conta ou escritório e não foi enviada.';
    public const MENSAGEM_FILA_VENCIDA = 'Esta batida ficou guardada sem internet por mais de %d horas e não pode mais ser enviada. Peça o esquecimento de registro do dia.';
    public const MENSAGEM_RELOGIO_ADIANTADO = 'O relógio deste aparelho está adiantado em relação à hora oficial, e a batida guardada sem internet não pôde ser enviada. Acerte o relógio e peça o esquecimento de registro do dia.';

    /** UUID v4, já em minúsculas. */
    private const FORMATO_DO_ID = '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/';

    /** O maior epoch em milissegundos com o ano em quatro dígitos (9999-12-31 23:59:59.999 UTC). */
    private const MAIOR_HORA_EM_MS = 253402300799999;

    /**
     * @param array<mixed> $dados        o JSON da batida
     * @param int          $usuarioId    a pessoa logada
     * @param int          $escritorioId o escritório atual da sessão
     */
    public function ler(array $dados, int $usuarioId, int $escritorioId, \DateTimeImmutable $chegada): EnvioLidoDaBatida
    {
        $idEnvio = $this->idEnvio($dados['idEnvio'] ?? null);
        if ($idEnvio === false) {
            return EnvioLidoDaBatida::recusado($chegada, new RecusaDoEnvio(422, self::MENSAGEM_ID_INVALIDO));
        }
        $horaAparelho = $this->horaAparelho($dados['horaAparelho'] ?? null);
        if (($dados['semInternet'] ?? false) !== true) {
            return new EnvioLidoDaBatida($chegada, $chegada, $idEnvio, $horaAparelho);
        }
        $conta = $dados['usuarioId'] ?? null;
        $escritorio = $dados['escritorioId'] ?? null;
        if ($idEnvio === null || $horaAparelho === null || !is_int($conta) || !is_int($escritorio)) {
            return EnvioLidoDaBatida::recusado($chegada, new RecusaDoEnvio(422, self::MENSAGEM_FILA_INCOMPLETA));
        }
        $recusa = $conta !== $usuarioId || $escritorio !== $escritorioId
            ? new RecusaDoEnvio(409, self::MENSAGEM_OUTRA_CONTA)
            : $this->recusaPelaHora($horaAparelho, $chegada);
        $momento = $horaAparelho > $chegada ? $chegada : $horaAparelho;

        return new EnvioLidoDaBatida($chegada, $momento, $idEnvio, $horaAparelho, semInternet: true, recusaDaFila: $recusa);
    }

    /** A batida guardada além do teto de idade, ou com o relógio do aparelho adiantado além da tolerância. */
    private function recusaPelaHora(\DateTimeImmutable $horaAparelho, \DateTimeImmutable $chegada): ?RecusaDoEnvio
    {
        $atrasoEmMicrossegundos = self::microssegundos($chegada) - self::microssegundos($horaAparelho);
        if ($atrasoEmMicrossegundos > self::TETO_DA_FILA_HORAS * 3600 * 1_000_000) {
            return new RecusaDoEnvio(422, sprintf(self::MENSAGEM_FILA_VENCIDA, self::TETO_DA_FILA_HORAS));
        }
        if (-$atrasoEmMicrossegundos > self::TOLERANCIA_DO_RELOGIO_SEGUNDOS * 1_000_000) {
            return new RecusaDoEnvio(422, self::MENSAGEM_RELOGIO_ADIANTADO);
        }

        return null;
    }

    /** O identificador em minúsculas; null quando não veio; false quando veio fora do formato. */
    private function idEnvio(mixed $valor): string|false|null
    {
        if ($valor === null) {
            return null;
        }
        if (!is_string($valor)) {
            return false;
        }
        $normalizado = strtolower(trim($valor));

        return preg_match(self::FORMATO_DO_ID, $normalizado) === 1 ? $normalizado : false;
    }

    /** O epoch em milissegundos do aparelho, no fuso padrão do PHP; null quando não é um inteiro possível. */
    private function horaAparelho(mixed $valor): ?\DateTimeImmutable
    {
        if (!is_int($valor) || $valor < 0 || $valor > self::MAIOR_HORA_EM_MS) {
            return null;
        }
        $instante = \DateTimeImmutable::createFromFormat('U.u', sprintf('%d.%06d', intdiv($valor, 1000), ($valor % 1000) * 1000));

        return $instante === false ? null : $instante->setTimezone(new \DateTimeZone(date_default_timezone_get()));
    }

    /** O instante em microssegundos desde a época, sem os desvios de fuso e de horário de verão de `modify()`. */
    private static function microssegundos(\DateTimeImmutable $instante): int
    {
        return $instante->getTimestamp() * 1_000_000 + (int) $instante->format('u');
    }
}
