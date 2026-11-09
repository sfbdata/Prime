<?php

declare(strict_types=1);

namespace App\Ponto\DTO;

use App\Ponto\Entity\RegistroPonto;

/**
 * O que o comprovante da batida atesta, na forma canônica que o HMAC sela (E15, D9): o que a imagem e a validação
 * mostram, e nada além. Nome inteiro (P50), escritório e CNPJ, data e hora do servidor, tipo, método e NSR, mais o
 * código e o id do escritório: o par escritório e NSR é único (D2) e prende o selo a uma batida só. Sem CPF e sem
 * localização.
 *
 * A forma canônica é um JSON com as chaves sempre na mesma ordem, sem escapar acentos nem barras: o mesmo dado gera
 * sempre o mesmo texto, então o HMAC gravado confere enquanto nada mudar.
 */
final readonly class DadosDoComprovante
{
    public const FORMATO = 1;
    public const METODO_SISTEMA = 'sistema';
    public const METODO_FACIAL = 'facial';
    private const TIPOS = [
        RegistroPonto::TIPO_ENTRADA => 'Entrada',
        RegistroPonto::TIPO_REPOUSO => 'Repouso',
        RegistroPonto::TIPO_RETORNO => 'Retorno',
        RegistroPonto::TIPO_SAIDA   => 'Saída',
    ];
    private const CHAVES = ['formato', 'codigo', 'nsr', 'escritorio_id', 'nome', 'escritorio', 'cnpj', 'data_hora', 'tipo', 'metodo'];

    public function __construct(
        public string $codigo,
        public int $nsr,
        public int $escritorioId,
        public string $nome,
        public string $escritorio,
        public ?string $cnpj,
        public \DateTimeImmutable $dataHora,
        public string $tipo,
        public string $metodo,
    ) {
    }

    /** Os dados da batida que está sendo gravada (já com o NSR; o id ainda não precisa existir). */
    public static function daBatida(RegistroPonto $batida, string $codigo): self
    {
        $pessoa = $batida->getUser();
        $escritorio = $batida->getTenant();
        $nsr = $batida->getNsr();
        if ($pessoa === null || $escritorio === null || $nsr === null) {
            throw new \LogicException('O comprovante só sai de batida com pessoa, escritório e NSR.');
        }

        return new self(
            $codigo,
            $nsr,
            (int) $escritorio->getId(),
            trim((string) ($pessoa->getFullName() ?? $pessoa->getEmail())),
            trim((string) $escritorio->getName()),
            $escritorio->getCnpj(),
            \DateTimeImmutable::createFromInterface($batida->getDataHora() ?? new \DateTimeImmutable()),
            (string) $batida->getTipo(),
            self::METODO_SISTEMA,
        );
    }

    public function canonico(): string
    {
        return json_encode(array_combine(self::CHAVES, [
            self::FORMATO, $this->codigo, $this->nsr, $this->escritorioId, $this->nome,
            $this->escritorio, $this->cnpj, $this->dataHora->format(\DATE_ATOM), $this->tipo, $this->metodo,
        ]), \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES | \JSON_THROW_ON_ERROR);
    }

    /** @throws \UnexpectedValueException quando o texto não é a forma canônica deste formato */
    public static function deCanonico(string $canonico): self
    {
        $dados = json_decode($canonico, true);
        if (!is_array($dados) || array_keys($dados) !== self::CHAVES || $dados['formato'] !== self::FORMATO) {
            throw new \UnexpectedValueException('Dados do comprovante fora do formato canônico.');
        }
        $dataHora = \DateTimeImmutable::createFromFormat(\DATE_ATOM, (string) $dados['data_hora']);
        if ($dataHora === false) {
            throw new \UnexpectedValueException('Data e hora do comprovante inválidas.');
        }

        return new self(
            (string) $dados['codigo'],
            (int) $dados['nsr'],
            (int) $dados['escritorio_id'],
            (string) $dados['nome'],
            (string) $dados['escritorio'],
            $dados['cnpj'] === null ? null : (string) $dados['cnpj'],
            $dataHora,
            (string) $dados['tipo'],
            (string) $dados['metodo'],
        );
    }

    public function nomeDoTipo(): string
    {
        return self::TIPOS[$this->tipo] ?? $this->tipo;
    }

    /** "Facial" ou "Sistema", como a lista da aba (desenho 16 L796). */
    public function nomeDoMetodo(): string
    {
        return $this->metodo === self::METODO_FACIAL ? 'Facial' : 'Sistema';
    }

    /** NSR com 9 dígitos, como o desenho (L522). */
    public function nsrFormatado(): string
    {
        return sprintf('%09d', $this->nsr);
    }

    /** "Entrada de NOME em dd/mm/aaaa, HH:MM:SS, NSR 000000001." (o `toLocaleString('pt-BR')` do desenho 16 L803). */
    public function textoDaValidacao(): string
    {
        return sprintf('%s de %s em %s, NSR %s.', $this->nomeDoTipo(), $this->nome, $this->dataHora->format('d/m/Y, H:i:s'), $this->nsrFormatado());
    }

    /** CNPJ com a máscara quando tem 14 dígitos; senão, como foi cadastrado. Null quando o escritório não tem. */
    public function cnpjFormatado(): ?string
    {
        $digitos = preg_replace('/\D/', '', (string) $this->cnpj) ?? '';
        if (strlen($digitos) !== 14) {
            return $this->cnpj === null || trim($this->cnpj) === '' ? null : $this->cnpj;
        }

        return sprintf('%s.%s.%s/%s-%s', substr($digitos, 0, 2), substr($digitos, 2, 3), substr($digitos, 5, 3), substr($digitos, 8, 4), substr($digitos, 12, 2));
    }
}
