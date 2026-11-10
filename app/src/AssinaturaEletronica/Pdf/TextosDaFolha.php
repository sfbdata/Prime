<?php

declare(strict_types=1);

namespace App\AssinaturaEletronica\Pdf;

use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Os textos que o `pdf()` do desenho imprime na folha (dc:409-412), lidos do bloco `pdf:` do catálogo da tela (spec
 * da etapa 3, seção 2, item 10). Quem monta a folha cria os textos pelo tradutor e os passa à {@see FolhaParaMontar};
 * as classes de desenho só leem daqui, nenhum texto impresso fica escrito no código.
 *
 * - Texto fixo: propriedade, já traduzida na criação.
 * - Texto com marcador (página e total, data, código): método que recebe o valor e traduz no formato ICU da chave. O
 *   número vai ao ICU como texto, para sair como no desenho (sem separador de milhar).
 * - O hash do rodapé sai em duas linhas de fonte mono: a primeira com o rótulo ("SHA-256 "), a segunda recuada do
 *   mesmo tamanho, para as duas metades ficarem alinhadas. O recuo é diagramação e acompanha o rótulo.
 * - O formato da data e hora é o `toLocaleString('pt-BR')` do desenho ({@see dataHora()}).
 */
final readonly class TextosDaFolha
{
    private const CHAVE = 'assinatura_eletronica_tela.js.pdf.';

    /** "RASCUNHO": o código impresso da folha sem código (rodapé, marca d'água, selo e título). */
    public string $rascunho;
    /** No miolo do selo do documento que ainda não foi concluído. */
    public string $pendente;
    /** O nome do lugar de assinatura sem rótulo e sem signatário. */
    public string $assinatura;
    public string $confira;
    /** A faixa lateral do fundo de segurança. */
    public string $faixa;
    /** O anel de dentro do selo. */
    public string $anelInterno;
    /** A marca em texto do cabeçalho sem logo: a parte azul-marinho e a parte azul. */
    public string $marcaAzul;
    public string $marcaClara;
    /** O `/Producer` do PDF. */
    public string $produtor;
    /** O rótulo do hash com o espaço depois, na primeira linha. */
    public string $prefixoDoHash;
    /** O recuo da segunda linha do hash, do tamanho do {@see $prefixoDoHash}. */
    public string $recuoDoHash;

    public function __construct(private TranslatorInterface $tradutor)
    {
        $this->rascunho = $this->t('rascunho');
        $this->pendente = $this->t('pendente');
        $this->assinatura = $this->t('assinatura');
        $this->confira = $this->t('confira');
        $this->faixa = $this->t('faixa');
        $this->anelInterno = $this->t('anel_interno');
        $this->marcaAzul = $this->t('marca_blue');
        $this->marcaClara = $this->t('marca_jus');
        $this->produtor = $this->t('produtor');
        $this->prefixoDoHash = $this->t('sha256') . ' ';
        $this->recuoDoHash = str_repeat(' ', mb_strlen($this->prefixoDoHash));
    }

    /** "CÓDIGO DE VALIDAÇÃO · PÁGINA n DE total" do rodapé. */
    public function rotuloDoRodape(int $pagina, int $total): string
    {
        return $this->t('rotulo_rodape', ['pagina' => (string) $pagina, 'total' => (string) $total]);
    }

    /** A emissão do rodapé, com a data e hora da geração. */
    public function emitido(\DateTimeImmutable $quando): string
    {
        return $this->t('emitido', ['data' => self::dataHora($quando)]);
    }

    /** Debaixo do nome de quem assinou, no lugar de assinatura. */
    public function assinadoEm(\DateTimeImmutable $quando): string
    {
        return $this->t('assinado_em', ['data' => self::dataHora($quando)]);
    }

    /** O trecho que se repete na marca d'água do fundo. */
    public function marcaDagua(string $codigo): string
    {
        return $this->t('marca_dagua', ['codigo' => $codigo]);
    }

    /** O trecho que se repete no microtexto da base do fundo. */
    public function microtexto(string $codigo): string
    {
        return $this->t('microtexto', ['codigo' => $codigo]);
    }

    /** O anel de fora do selo. */
    public function anel(string $codigo): string
    {
        return $this->t('anel', ['codigo' => $codigo]);
    }

    /** O `/Title` do PDF. */
    public function titulo(string $codigo): string
    {
        return $this->t('titulo', ['codigo' => $codigo]);
    }

    public static function dataHora(\DateTimeImmutable $quando): string
    {
        return $quando->format('d/m/Y, H:i:s');
    }

    /** @param array<string, string> $parametros */
    private function t(string $chave, array $parametros = []): string
    {
        return $this->tradutor->trans(self::CHAVE . $chave, $parametros);
    }
}
