<?php

declare(strict_types=1);

namespace App\Ponto\Exception;

/**
 * A aba Configurações da /ponto (spec `docs/specs/ponto-desenho-0811.md`, E6) recusou gravar ou remover uma sede ou um
 * feriado. Leva o status que o controller devolve (404 para o que não é do escritório da sessão, 422 para o dado
 * inválido) e, no 422, o erro de cada campo, com a mensagem escrita para quem configura o ponto.
 */
final class ConfiguracaoDoPontoRecusadaException extends \DomainException
{
    public const RECUSA_SEDE_NAO_ENCONTRADA = 'Sede não encontrada.';
    public const RECUSA_FERIADO_NAO_ENCONTRADO = 'Feriado não encontrado.';
    public const RECUSA_LOTACAO_NAO_ENCONTRADA = 'Lotação não encontrada.';
    public const RECUSA_FALTA_DATA_OU_NOME = 'Informe a data e o nome do feriado.';
    public const RECUSA_FERIADO_NOME_LONGO = 'O nome do feriado pode ter até 255 caracteres.';
    public const RECUSA_SEDE_NOME_OBRIGATORIO = 'Informe o nome da sede.';
    public const RECUSA_SEDE_NOME_LONGO = 'O nome da sede pode ter até 255 caracteres.';
    public const RECUSA_LATITUDE_OBRIGATORIA = 'Latitude é obrigatória.';
    public const RECUSA_LATITUDE_FAIXA = 'Latitude deve estar entre -90 e 90.';
    public const RECUSA_LATITUDE_NUMERO = 'Latitude deve ser um número.';
    public const RECUSA_LONGITUDE_OBRIGATORIA = 'Longitude é obrigatória.';
    public const RECUSA_LONGITUDE_FAIXA = 'Longitude deve estar entre -180 e 180.';
    public const RECUSA_LONGITUDE_NUMERO = 'Longitude deve ser um número.';
    public const RECUSA_RAIO_NUMERO = 'Raio permitido deve ser um número.';
    public const RECUSA_RAIO_FAIXA = 'Raio permitido deve estar entre 10 e 500 metros.';
    public const RECUSA_RAIO_INTEIRO = 'Raio permitido deve ser um número inteiro de metros.';

    /** @var array<string, string> recusa (o texto da constante RECUSA_*) => chave no catálogo */
    public const CHAVE_DA_RECUSA = [
        self::RECUSA_SEDE_NAO_ENCONTRADA => 'ponto_servidor.recusa.configuracao.sede_nao_encontrada',
        self::RECUSA_FERIADO_NAO_ENCONTRADO => 'ponto_servidor.recusa.configuracao.feriado_nao_encontrado',
        self::RECUSA_LOTACAO_NAO_ENCONTRADA => 'ponto_servidor.recusa.configuracao.lotacao_nao_encontrada',
        self::RECUSA_FALTA_DATA_OU_NOME => 'ponto_servidor.recusa.configuracao.falta_data_ou_nome',
        self::RECUSA_FERIADO_NOME_LONGO => 'ponto_servidor.recusa.configuracao.feriado_nome_longo',
        self::RECUSA_SEDE_NOME_OBRIGATORIO => 'ponto_servidor.recusa.configuracao.sede_nome_obrigatorio',
        self::RECUSA_SEDE_NOME_LONGO => 'ponto_servidor.recusa.configuracao.sede_nome_longo',
        self::RECUSA_LATITUDE_OBRIGATORIA => 'ponto_servidor.recusa.configuracao.latitude_obrigatoria',
        self::RECUSA_LATITUDE_FAIXA => 'ponto_servidor.recusa.configuracao.latitude_faixa',
        self::RECUSA_LATITUDE_NUMERO => 'ponto_servidor.recusa.configuracao.latitude_numero',
        self::RECUSA_LONGITUDE_OBRIGATORIA => 'ponto_servidor.recusa.configuracao.longitude_obrigatoria',
        self::RECUSA_LONGITUDE_FAIXA => 'ponto_servidor.recusa.configuracao.longitude_faixa',
        self::RECUSA_LONGITUDE_NUMERO => 'ponto_servidor.recusa.configuracao.longitude_numero',
        self::RECUSA_RAIO_NUMERO => 'ponto_servidor.recusa.configuracao.raio_numero',
        self::RECUSA_RAIO_FAIXA => 'ponto_servidor.recusa.configuracao.raio_faixa',
        self::RECUSA_RAIO_INTEIRO => 'ponto_servidor.recusa.configuracao.raio_inteiro',
    ];

    /** @param array<string, string> $erros campo => mensagem (uma das constantes RECUSA_*) */
    private function __construct(string $mensagem, private readonly int $status, private readonly array $erros)
    {
        parent::__construct($mensagem);
    }

    /** @param string $recusa uma das constantes RECUSA_* */
    public static function naoEncontrada(string $recusa): self
    {
        self::chaveDe($recusa);

        return new self($recusa, 404, []);
    }

    /** @param array<string, string> $erros campo => mensagem (uma das constantes RECUSA_*) */
    public static function invalida(array $erros): self
    {
        foreach ($erros as $recusa) {
            self::chaveDe($recusa);
        }

        return new self(implode(' ', array_unique(array_values($erros))), 422, $erros);
    }

    /** A chave no catálogo de uma das constantes RECUSA_*; qualquer outro texto é erro de programação. */
    public static function chaveDe(string $recusa): string
    {
        return self::CHAVE_DA_RECUSA[$recusa] ?? throw new \LogicException(sprintf('Recusa sem chave no catálogo: "%s".', $recusa));
    }

    public function status(): int
    {
        return $this->status;
    }

    /** @return array<string, string> campo => mensagem em português (use {@see chaveDe()} para traduzir) */
    public function erros(): array
    {
        return $this->erros;
    }
}
