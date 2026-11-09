<?php

declare(strict_types=1);

namespace App\Ponto\Service;

/**
 * Os campos do envio na batida (spec ponto-desenho-0811 §3 "Hora do servidor" e §5 E13): o identificador do envio, feito
 * no navegador no toque e repetido em toda nova tentativa, e a hora do relógio do aparelho. Os dois são opcionais: sem
 * eles a batida segue como sempre (o atalho antigo e a página em cache continuam funcionando).
 *
 * A hora oficial é a do servidor na chegada, um único instante por pedido. A hora do aparelho fica só como informação:
 * inválida ou ausente, vira null e nunca recusa. Só o identificador fora do formato recusa (422).
 */
final class EnvioDaBatida
{
    public const MENSAGEM_ID_INVALIDO = 'Identificador do envio inválido.';
    public const MENSAGEM_JA_REGISTRADA = 'Esta batida já tinha sido registrada.';

    /** UUID v4, já em minúsculas. */
    private const FORMATO_DO_ID = '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/';

    /** O maior epoch em milissegundos com o ano em quatro dígitos (9999-12-31 23:59:59.999 UTC). */
    private const MAIOR_HORA_EM_MS = 253402300799999;

    /** @param array<mixed> $dados o JSON da batida */
    public function ler(array $dados, \DateTimeImmutable $chegada): EnvioLidoDaBatida
    {
        $idEnvio = $this->idEnvio($dados['idEnvio'] ?? null);
        if ($idEnvio === false) {
            return EnvioLidoDaBatida::recusado($chegada, new RecusaDoEnvio(422, self::MENSAGEM_ID_INVALIDO));
        }

        return new EnvioLidoDaBatida($chegada, $chegada, $idEnvio, $this->horaAparelho($dados['horaAparelho'] ?? null));
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
}
