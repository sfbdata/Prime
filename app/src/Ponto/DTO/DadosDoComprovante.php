<?php

declare(strict_types=1);

namespace App\Ponto\DTO;

use App\Ponto\Entity\RegistroPonto;

/**
 * O que o comprovante da batida atesta, na forma canônica que o HMAC sela (E15, D9): o que a imagem mostra, e nada
 * além. Nome inteiro (P50), escritório e CNPJ, data e hora do servidor, tipo, método, o local do registro (P49: as
 * coordenadas com a precisão, ou "Geolocalização não exigida"), as marcas "sem internet" (E13 e E14) e "trabalho
 * externo" (E12) e o NSR, mais o código e o id do escritório: o par escritório e NSR é único (D2) e prende o selo a uma
 * batida só. Sem CPF.
 *
 * A validação (janela e página pública) mostra só {@see self::textoDaValidacao()}: o local fica na imagem, que é da
 * própria pessoa, e nunca sai na página aberta (P50).
 *
 * A forma canônica é um JSON com as chaves sempre na mesma ordem, sem escapar acentos nem barras: o mesmo dado gera
 * sempre o mesmo texto, então o HMAC gravado confere enquanto nada mudar. O número do formato vai junto: o leitor só
 * aceita o formato que conhece, e o resto não vira comprovante ("Código não localizado." na validação).
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
    public const SEM_GEOLOCALIZACAO = 'Geolocalização não exigida';
    private const CHAVES = ['formato', 'codigo', 'nsr', 'escritorio_id', 'nome', 'escritorio', 'cnpj', 'data_hora', 'tipo', 'metodo', 'local', 'sem_internet', 'trabalho_externo'];

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
        public string $local,
        public bool $semInternet,
        public bool $trabalhoExterno,
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
            trim((string) $pessoa->getFullName()),   // sem nome, vazio: nunca o e-mail ou outro dado pessoal
            trim((string) $escritorio->getName()),
            $escritorio->getCnpj(),
            \DateTimeImmutable::createFromInterface($batida->getDataHora() ?? new \DateTimeImmutable()),
            (string) $batida->getTipo(),
            self::METODO_SISTEMA,
            self::localDaBatida($batida),
            $batida->isEnviadaSemInternet(),
            $batida->isTrabalhoExterno(),   // E12: a batida fora do raio registrada como trabalho externo
        );
    }

    /** "-23.55000, -46.63000 · precisão 12 m", como o desenho (L581); sem coordenadas, "Geolocalização não exigida". */
    private static function localDaBatida(RegistroPonto $batida): string
    {
        [$latitude, $longitude, $precisao] = [$batida->getLatitude(), $batida->getLongitude(), $batida->getPrecisaoGps()];
        if ($latitude === null || $longitude === null) {
            return self::SEM_GEOLOCALIZACAO;
        }
        $local = sprintf('%.5F, %.5F', (float) $latitude, (float) $longitude);

        return $precisao === null ? $local : $local . sprintf(' · precisão %d m', (int) round((float) $precisao));
    }

    public function canonico(): string
    {
        return json_encode(array_combine(self::CHAVES, [
            self::FORMATO, $this->codigo, $this->nsr, $this->escritorioId, $this->nome, $this->escritorio,
            $this->cnpj, $this->dataHora->format(\DATE_ATOM), $this->tipo, $this->metodo, $this->local, $this->semInternet,
            $this->trabalhoExterno,
        ]), \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES | \JSON_THROW_ON_ERROR);
    }

    /** @throws \UnexpectedValueException quando o texto não é a forma canônica deste formato (outro número, outras chaves) */
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
            (string) $dados['local'],
            $dados['sem_internet'] === true,
            $dados['trabalho_externo'] === true,
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
