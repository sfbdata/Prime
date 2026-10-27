<?php

declare(strict_types=1);

namespace App\Master\Service;

use App\Master\Exception\ConfiguracaoRecusada;
use Symfony\Component\Mime\Address;

/**
 * Catálogo das configurações globais do Master (desenho 05 Perfis 1.2 VS 25.10.26, `cgGrupos` e `DEF`): grupos,
 * campos, padrões e as regras de cada valor. A tela e o UseCase leem daqui; os rótulos saem do catálogo de
 * traduções (master.config.*).
 *
 * Dois campos têm outra fonte e não são gravados na ConfiguracaoGlobal: `freeGb` (cota do Plano Free) e `aviso`
 * (faixa de Alerta do espaço). O catálogo só os descreve e valida.
 */
final class CatalogoDeConfiguracoes
{
    public const TEXTO = 'text';
    public const EMAIL = 'email';
    public const NUMERO = 'number';
    public const OPCOES = 'select';
    public const DATA_HORA = 'datetime-local';

    public const CAMPO_FREE_GB = 'freeGb';
    public const CAMPO_AVISO = 'aviso';

    /** Formato do `datetime-local` do navegador. */
    public const FORMATO_DATA_HORA = 'Y-m-d\TH:i';

    /**
     * Grupo => campos, na ordem do desenho. Cada campo: tipo, padrão e, conforme o tipo, `min`/`max` (número),
     * `opcoes` (lista de chaves aceitas) ou `max` (tamanho do texto). `ajuda` marca os campos com texto de apoio.
     *
     * @var array<string, array<string, array{tipo: string, padrao: string, min?: int, max?: int, opcoes?: list<string>, ajuda?: bool}>>
     */
    private const GRUPOS = [
        'identidade' => [
            'nome' => ['tipo' => self::TEXTO, 'padrao' => 'BlueJus', 'max' => 60],
            'remetente' => ['tipo' => self::EMAIL, 'padrao' => 'nao-responda@bluejus.com.br', 'max' => 180, 'ajuda' => true],
            'suporte' => ['tipo' => self::EMAIL, 'padrao' => 'bluejus.86@gmail.com', 'max' => 180],
        ],
        'contas' => [
            'cadastro' => ['tipo' => self::OPCOES, 'padrao' => 'on', 'opcoes' => ['on', 'convite', 'off']],
            self::CAMPO_FREE_GB => ['tipo' => self::NUMERO, 'padrao' => '10', 'min' => 1, 'max' => 100000],
            self::CAMPO_AVISO => ['tipo' => self::NUMERO, 'padrao' => '90', 'min' => 1, 'max' => 100],
        ],
        'seguranca' => [
            'sessao' => ['tipo' => self::NUMERO, 'padrao' => '480', 'min' => 5, 'max' => 43200],
            'tentativas' => ['tipo' => self::NUMERO, 'padrao' => '5', 'min' => 1, 'max' => 50],
            'dois' => ['tipo' => self::OPCOES, 'padrao' => 'master', 'opcoes' => ['master', 'prim', 'todos', 'opc']],
            'retencao' => ['tipo' => self::NUMERO, 'padrao' => '365', 'min' => 30, 'max' => 3650],
            'avisoLoginNovo' => ['tipo' => self::OPCOES, 'padrao' => 'on', 'opcoes' => ['on', 'off']],
        ],
        'regiao' => [
            'idioma' => ['tipo' => self::OPCOES, 'padrao' => 'pt-BR', 'opcoes' => ['pt-BR', 'en', 'es']],
            'fuso' => ['tipo' => self::OPCOES, 'padrao' => 'America/Sao_Paulo', 'opcoes' => ['America/Sao_Paulo', 'America/Manaus', 'America/Rio_Branco', 'America/Noronha']],
        ],
        'manutencao' => [
            'manut' => ['tipo' => self::TEXTO, 'padrao' => '', 'max' => 300, 'ajuda' => true],
            'manutAte' => ['tipo' => self::DATA_HORA, 'padrao' => ''],
        ],
    ];

    /**
     * Padrões que dependem de configuração do ambiente: `sessao` (parâmetro `app.sessao_padrao_minutos`) e `remetente`
     * (o endereço do MAILER_FROM; o Master só muda se editar). O catálogo segue estático; quem tem o container (leitor,
     * consulta e salvar do Master) passa isto a `padroes()`/`grupos()`/`normalizar()`. Argumento ausente, nada a sobrepor.
     *
     * @return array<string, string>
     */
    public static function sobreposicoes(?int $sessaoPadraoMinutos, ?string $mailerFrom = null): array
    {
        $sobrepor = [];
        if ($sessaoPadraoMinutos !== null) {
            $sobrepor['sessao'] = (string) $sessaoPadraoMinutos;
        }
        $endereco = self::enderecoDoMailerFrom($mailerFrom);
        if ($endereco !== null) {
            $sobrepor['remetente'] = $endereco;
        }

        return $sobrepor;
    }

    /** O endereço (sem o nome) do MAILER_FROM; inválido ou vazio, nulo (vale o padrão estático). */
    private static function enderecoDoMailerFrom(?string $mailerFrom): ?string
    {
        if ($mailerFrom === null || trim($mailerFrom) === '') {
            return null;
        }
        try {
            return Address::create($mailerFrom)->getAddress();
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * @param array<string, string> $sobrepor chave => padrão que vale no lugar do estático
     *
     * @return array<string, array<string, array{tipo: string, padrao: string, min?: int, max?: int, opcoes?: list<string>, ajuda?: bool}>>
     */
    public static function grupos(array $sobrepor = []): array
    {
        $grupos = self::GRUPOS;
        foreach ($grupos as $grupo => $campos) {
            foreach ($campos as $chave => $campo) {
                if (array_key_exists($chave, $sobrepor)) {
                    $grupos[$grupo][$chave]['padrao'] = $sobrepor[$chave];
                }
            }
        }

        return $grupos;
    }

    /**
     * @param array<string, string> $sobrepor chave => padrão que vale no lugar do estático
     *
     * @return array<string, string> chave => padrão, todos os campos
     */
    public static function padroes(array $sobrepor = []): array
    {
        $padroes = [];
        foreach (self::GRUPOS as $campos) {
            foreach ($campos as $chave => $campo) {
                $padroes[$chave] = $sobrepor[$chave] ?? $campo['padrao'];
            }
        }

        return $padroes;
    }

    /** @return list<string> as chaves que a ConfiguracaoGlobal grava (as demais têm outra fonte) */
    public static function chavesGravadas(): array
    {
        return array_values(array_diff(array_keys(self::padroes()), [self::CAMPO_FREE_GB, self::CAMPO_AVISO]));
    }

    /**
     * Valida o pedido inteiro e devolve os valores normalizados (texto aparado, número como inteiro em texto). Chave
     * que falta cai no padrão; chave desconhecida é recusada.
     *
     * @param array<mixed>          $entrada
     * @param array<string, string> $sobrepor padrões do ambiente que valem no lugar dos estáticos
     *
     * @return array<string, string>
     */
    public static function normalizar(array $entrada, array $sobrepor = []): array
    {
        $padroes = self::padroes($sobrepor);
        foreach (array_keys($entrada) as $chave) {
            if (!array_key_exists((string) $chave, $padroes)) {
                throw new ConfiguracaoRecusada('Configuração desconhecida.');
            }
        }

        $valores = [];
        foreach (self::GRUPOS as $campos) {
            foreach ($campos as $chave => $campo) {
                $bruto = $entrada[$chave] ?? $padroes[$chave];
                if (!is_string($bruto) && !is_int($bruto)) {
                    throw new ConfiguracaoRecusada('Valor inválido.');
                }
                $valores[$chave] = self::normalizarCampo($chave, $campo, trim((string) $bruto));
            }
        }

        return $valores;
    }

    /**
     * @param array{tipo: string, padrao: string, min?: int, max?: int, opcoes?: list<string>, ajuda?: bool} $campo
     */
    private static function normalizarCampo(string $chave, array $campo, string $valor): string
    {
        return match ($campo['tipo']) {
            self::NUMERO => self::numero($chave, $campo['min'] ?? 0, $campo['max'] ?? 0, $valor),
            self::OPCOES => self::opcao($chave, $campo['opcoes'] ?? [], $valor),
            self::EMAIL => self::email($campo['max'] ?? 0, $valor),
            self::DATA_HORA => self::dataHora($valor),
            default => self::texto($chave, $campo['padrao'], $campo['max'] ?? 0, $valor),
        };
    }

    private static function numero(string $chave, int $min, int $max, string $valor): string
    {
        if (!preg_match('/^\d{1,9}$/', $valor) || (int) $valor < $min || (int) $valor > $max) {
            throw new ConfiguracaoRecusada(sprintf('Informe um número de %d a %d em "%s".', $min, $max, $chave));
        }

        return (string) (int) $valor;
    }

    /** @param list<string> $opcoes */
    private static function opcao(string $chave, array $opcoes, string $valor): string
    {
        if (!in_array($valor, $opcoes, true)) {
            throw new ConfiguracaoRecusada(sprintf('Opção inválida em "%s".', $chave));
        }

        return $valor;
    }

    private static function email(int $max, string $valor): string
    {
        if ($valor === '' || mb_strlen($valor) > $max || filter_var($valor, \FILTER_VALIDATE_EMAIL) === false) {
            throw new ConfiguracaoRecusada('Informe um e-mail válido.');
        }

        return $valor;
    }

    /** Em branco vale: sem prazo. */
    private static function dataHora(string $valor): string
    {
        if ($valor === '') {
            return '';
        }

        $data = \DateTimeImmutable::createFromFormat('!' . self::FORMATO_DATA_HORA, $valor);
        if ($data === false || $data->format(self::FORMATO_DATA_HORA) !== $valor) {
            throw new ConfiguracaoRecusada('Informe uma data e hora válidas.');
        }

        return $valor;
    }

    private static function texto(string $chave, string $padrao, int $max, string $valor): string
    {
        if ($valor === '' && $padrao !== '') {
            throw new ConfiguracaoRecusada(sprintf('Preencha "%s".', $chave));
        }
        if (mb_strlen($valor) > $max) {
            throw new ConfiguracaoRecusada(sprintf('Use até %d caracteres em "%s".', $max, $chave));
        }

        return $valor;
    }
}
