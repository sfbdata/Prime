<?php

declare(strict_types=1);

namespace App\Comercial\DTO;

use App\Comercial\Campanha\RenderizadorDeBlocos;
use App\Comercial\Campanha\SanitizadorDeHtmlDeEmail;
use App\Comercial\Entity\ImagemDaCampanha;
use App\Comercial\Exception\CampanhaRecusada;

/**
 * O que a tela manda do modelo em edição (spec `comercial-e-emails.md` §8.2 e §8.3): o modelo escolhido com as edições
 * por cima (dc L.530, `Object.assign({}, TODOS[mk], s.cmEd)`). Validada aqui; o conteúdo só vira HTML no
 * {@see \App\Comercial\Campanha\ComposicaoDaCampanha} (texto escapado, HTML sanitizado).
 *
 * F3i (§12): no modo simples, `imagem` é o token da imagem enviada ao BlueJus (dc L274, L596), nunca URL nem `data:`;
 * fora do formato é recusada aqui. Se o token existe e não venceu, quem confere é o caso de uso
 * ({@see \App\Comercial\Campanha\ImagensDaCampanha}), que tem o repositório.
 *
 * @phpstan-import-type Bloco from \App\Comercial\Campanha\ModelosDeCampanha
 */
final class MensagemDaCampanha
{
    public const MODOS = ['simples', 'blocos', 'html'];
    private const MAXIMO_DE_BLOCOS = 60;
    private const MAXIMO_DE_ITENS = 30;
    public const IMAGEM_INVALIDA = 'Imagem inválida.';

    /**
     * @param list<string> $itens
     * @param list<Bloco>  $blocos
     */
    public function __construct(
        public readonly string $chave,
        public readonly string $modo,
        public readonly string $assunto,
        public readonly string $selo,
        public readonly string $titulo,
        public readonly string $corpo = '',
        public readonly array $itens = [],
        public readonly string $cta = '',
        public readonly string $link = '',
        public readonly array $blocos = [],
        public readonly string $html = '',
        public readonly string $nome = '',
        public readonly string $icone = '',
        public readonly string $imagem = '',
    ) {
    }

    /** @param array<mixed> $dados */
    public static function deArray(array $dados): self
    {
        $modo = self::texto($dados, 'modo', 10);
        if (!\in_array($modo, self::MODOS, true)) {
            throw new CampanhaRecusada('Modo do modelo inválido.');
        }
        $chave = self::texto($dados, 'chave', 40);

        return new self(
            preg_match('/^[a-z0-9]{1,40}$/', $chave) === 1 ? $chave : 'campanha',
            $modo,
            self::texto($dados, 'assunto', 255),
            self::texto($dados, 'selo', 120),
            self::texto($dados, 'titulo', 255),
            self::texto($dados, 'corpo', 20000),
            self::itens($dados['itens'] ?? []),
            self::texto($dados, 'cta', 120),
            self::texto($dados, 'link', 2000),
            $modo === 'blocos' ? self::blocos($dados['blocos'] ?? []) : [],
            $modo === 'html' ? self::html($dados['html'] ?? '') : '',
            self::texto($dados, 'nome', 200),
            self::texto($dados, 'icone', 60),
            $modo === 'simples' ? self::imagem($dados['imagem'] ?? '') : '',
        );
    }

    /**
     * O conteúdo do modelo, como `comercial_modelo.conteudo` guarda: os campos do simples, a lista de blocos ou o HTML.
     * O simples só leva `imagem` quando há uma (o JSON de antes da F3i continua igual).
     *
     * @return array{corpo: string, itens: list<string>, cta: string, link: string, imagem?: string}|list<Bloco>|string
     */
    public function conteudo(): array|string
    {
        return match ($this->modo) {
            'simples' => ['corpo' => $this->corpo, 'itens' => $this->itens, 'cta' => $this->cta, 'link' => $this->link] + ($this->imagem !== '' ? ['imagem' => $this->imagem] : []),
            'blocos' => $this->blocos,
            default => $this->html,
        };
    }

    /** @param array<mixed> $dados */
    private static function texto(array $dados, string $campo, int $maximo): string
    {
        $valor = $dados[$campo] ?? '';
        if (!\is_string($valor)) {
            throw new CampanhaRecusada('Campo inválido: ' . $campo . '.');
        }

        return mb_substr(str_replace("\r\n", "\n", $valor), 0, $maximo);
    }

    /** Vazio (sem imagem) ou o token: URL, `data:` e qualquer outra coisa são recusadas. */
    private static function imagem(mixed $imagem): string
    {
        if ($imagem === '' || $imagem === null) {
            return '';
        }
        if (!\is_string($imagem) || preg_match(ImagemDaCampanha::PADRAO_DO_TOKEN, $imagem) !== 1) {
            throw new CampanhaRecusada(self::IMAGEM_INVALIDA);
        }

        return $imagem;
    }

    /** @return list<string> */
    private static function itens(mixed $itens): array
    {
        if (!\is_array($itens) || \count($itens) > self::MAXIMO_DE_ITENS) {
            throw new CampanhaRecusada('Itens inválidos.');
        }

        return array_values(array_map(static fn (mixed $i): string => \is_string($i) ? mb_substr($i, 0, 500) : throw new CampanhaRecusada('Itens inválidos.'), $itens));
    }

    /** @return list<Bloco> */
    private static function blocos(mixed $blocos): array
    {
        if (!\is_array($blocos) || \count($blocos) > self::MAXIMO_DE_BLOCOS) {
            throw new CampanhaRecusada('Blocos inválidos.');
        }
        $lista = [];
        foreach ($blocos as $bloco) {
            if (!\is_array($bloco) || !\in_array($bloco['t'] ?? null, RenderizadorDeBlocos::TIPOS, true)) {
                throw new CampanhaRecusada('Blocos inválidos.');
            }
            $lista[] = ['t' => (string) $bloco['t'], 'a' => self::texto($bloco, 'a', 20000), 'b' => self::texto($bloco, 'b', 2000)];
        }

        return $lista;
    }

    private static function html(mixed $html): string
    {
        if (!\is_string($html)) {
            throw new CampanhaRecusada('Campo inválido: html.');
        }
        if (\strlen($html) > SanitizadorDeHtmlDeEmail::LIMITE_EM_BYTES) {
            throw new CampanhaRecusada('O HTML passa de 200 KB.');
        }

        return $html;
    }
}
