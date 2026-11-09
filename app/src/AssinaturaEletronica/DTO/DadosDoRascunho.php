<?php

declare(strict_types=1);

namespace App\AssinaturaEletronica\DTO;

use App\AssinaturaEletronica\Entity\DocumentoParaAssinar;
use App\AssinaturaEletronica\Enum\TipoDeLogo;
use App\AssinaturaEletronica\Exception\AcaoRecusada;

/**
 * O `Rascunho` que a tela manda no campo `dados` do "Salvar rascunho" (contrato da etapa 1, seção 4.1), já conferido
 * nos limites. Os blocos ficam crus aqui: a estrutura depende do PDF guardado e é conferida no
 * {@see \App\AssinaturaEletronica\Service\ValidadorDeBlocos}.
 */
final readonly class DadosDoRascunho
{
    public const NOME_MAXIMO = DocumentoParaAssinar::NOME_MAXIMO;
    public const PASTA_MAXIMO = 200;
    public const CATEGORIA_MAXIMO = 80;
    public const MODELO_MAXIMO = 60;
    public const LATERAL_MAXIMO = 20000;
    public const MENSAGEM_MAXIMO = 2000;
    public const PRAZO_MINIMO = 1;
    public const PRAZO_MAXIMO = 90;
    public const SIGNATARIOS_MAXIMO = 50;
    public const LAYOUTS = ['', '2col'];

    /**
     * @param array<mixed>             $blocos
     * @param list<DadosDoSignatario> $signatarios
     */
    private function __construct(
        public string $nome,
        public ?string $pasta,
        public string $categoria,
        public ?string $modelo,
        public array $blocos,
        public string $layout,
        public ?string $lateral,
        public bool $folhaBranca,
        public bool $emOrdem,
        public string $mensagem,
        public int $prazoDias,
        public TipoDeLogo $logo,
        public bool $usarLogoComoPadrao,
        public array $signatarios,
    ) {
    }

    /**
     * @param mixed $json o `dados` já decodificado
     *
     * @throws AcaoRecusada
     */
    public static function deJson(mixed $json): self
    {
        if (!\is_array($json) || (array_is_list($json) && $json !== [])) {
            throw new AcaoRecusada('assinatura_eletronica.erro.dados_invalidos');
        }

        $blocos = $json['blocos'] ?? [];
        if (!\is_array($blocos)) {
            throw new AcaoRecusada('assinatura_eletronica.erro.dados_invalidos');
        }

        return new self(
            self::nome($json),
            self::limitado($json, 'pasta', self::PASTA_MAXIMO, 'assinatura_eletronica.erro.pasta_longa'),
            self::limitado($json, 'categoria', self::CATEGORIA_MAXIMO, 'assinatura_eletronica.erro.categoria_longa') ?? '',
            self::limitado($json, 'modelo', self::MODELO_MAXIMO, 'assinatura_eletronica.erro.modelo_invalido'),
            $blocos,
            self::layout($json),
            self::lateral($json),
            LeitorDeCampos::booleano($json, 'folhaBranca'),
            LeitorDeCampos::booleano($json, 'emOrdem'),
            self::mensagem($json),
            self::prazo($json),
            self::logo($json),
            LeitorDeCampos::booleano($json, 'usarLogoComoPadrao'),
            self::signatarios($json),
        );
    }

    /**
     * @param array<mixed> $json
     *
     * @throws AcaoRecusada
     */
    private static function nome(array $json): string
    {
        $nome = LeitorDeCampos::texto($json, 'nome');
        LeitorDeCampos::exigirTamanho($nome, self::NOME_MAXIMO, 'assinatura_eletronica.erro.nome_longo');

        return $nome === '' ? DocumentoParaAssinar::NOME_PADRAO : $nome;
    }

    /**
     * @param array<mixed> $json
     *
     * @throws AcaoRecusada
     */
    private static function limitado(array $json, string $campo, int $maximo, string $erro): ?string
    {
        $valor = LeitorDeCampos::textoOuNulo($json, $campo);
        LeitorDeCampos::exigirTamanho($valor, $maximo, $erro);

        return $valor;
    }

    /**
     * @param array<mixed> $json
     *
     * @throws AcaoRecusada
     */
    private static function layout(array $json): string
    {
        $layout = LeitorDeCampos::texto($json, 'layout');
        if (!\in_array($layout, self::LAYOUTS, true)) {
            throw new AcaoRecusada('assinatura_eletronica.erro.layout_invalido');
        }

        return $layout;
    }

    /**
     * @param array<mixed> $json
     *
     * @throws AcaoRecusada
     */
    private static function lateral(array $json): ?string
    {
        $lateral = LeitorDeCampos::textoOuNulo($json, 'lateral', aparar: false);
        LeitorDeCampos::exigirTamanho($lateral, self::LATERAL_MAXIMO, 'assinatura_eletronica.erro.lateral_longa');

        return $lateral;
    }

    /**
     * @param array<mixed> $json
     *
     * @throws AcaoRecusada
     */
    private static function mensagem(array $json): string
    {
        $mensagem = LeitorDeCampos::texto($json, 'mensagem', aparar: false);
        LeitorDeCampos::exigirTamanho($mensagem, self::MENSAGEM_MAXIMO, 'assinatura_eletronica.erro.mensagem_longa');

        return $mensagem;
    }

    /**
     * Ausente vale o padrão do desenho (7 dias).
     *
     * @param array<mixed> $json
     *
     * @throws AcaoRecusada
     */
    private static function prazo(array $json): int
    {
        if (!isset($json['prazoDias'])) {
            return DocumentoParaAssinar::PRAZO_PADRAO;
        }

        $prazo = LeitorDeCampos::inteiroOuNulo($json, 'prazoDias');
        if ($prazo === null || $prazo < self::PRAZO_MINIMO || $prazo > self::PRAZO_MAXIMO) {
            throw new AcaoRecusada('assinatura_eletronica.erro.prazo_invalido');
        }

        return $prazo;
    }

    /**
     * @param array<mixed> $json
     *
     * @throws AcaoRecusada
     */
    private static function logo(array $json): TipoDeLogo
    {
        $logo = $json['logo'] ?? [];
        if (!\is_array($logo)) {
            throw new AcaoRecusada('assinatura_eletronica.erro.logo_invalida');
        }

        return TipoDeLogo::tryFrom(LeitorDeCampos::texto($logo, 'tipo', TipoDeLogo::Bluejus->value))
            ?? throw new AcaoRecusada('assinatura_eletronica.erro.logo_invalida');
    }

    /**
     * @param array<mixed> $json
     *
     * @return list<DadosDoSignatario>
     *
     * @throws AcaoRecusada
     */
    private static function signatarios(array $json): array
    {
        $lista = $json['signatarios'] ?? [];
        if (!\is_array($lista) || !array_is_list($lista)) {
            throw new AcaoRecusada('assinatura_eletronica.erro.dados_invalidos');
        }

        if (\count($lista) > self::SIGNATARIOS_MAXIMO) {
            throw new AcaoRecusada('assinatura_eletronica.erro.signatarios_demais');
        }

        $signatarios = [];
        foreach ($lista as $indice => $item) {
            $signatarios[] = DadosDoSignatario::deJson($item, $indice + 1);
        }

        return $signatarios;
    }
}
