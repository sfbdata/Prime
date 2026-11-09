<?php

declare(strict_types=1);

namespace App\Tests\Arquitetura;

use App\Shared\Armazenamento\ChaveDeArquivo;
use App\Shared\EspelhoRemoto\Reconciliacao\FontesDeReferencia;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\ClassMetadata;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Toda coluna que guarda chave de arquivo do armazenamento entra na reconciliação do espelho remoto
 * (`FontesDeReferencia`). Coluna fora dela é arquivo que o banco diz que existe e que a reconciliação
 * nunca confere no R2. A `assinatura_eletronica_signatario.rubrica_chave` nasceu assim, porque nada
 * conferia isso.
 *
 * ## O sinal: dois, somados
 *
 * Não há tipo de coluna nem atributo que marque "chave de arquivo", e o nome sozinho não basta: as
 * colunas antigas se chamam `caminho_arquivo`, `foto_url`, `anexo_path`, `nome_armazenado`, e uma
 * coluna `chave` quase sempre é outra coisa (preferência, data comemorativa, recurso do Master).
 *
 *  1. **A fábrica (o sinal forte).** A chave de um arquivo que existe nasce numa fábrica
 *     `src/<Dominio>/Armazenamento/ChavesDe*.php` (ou `ChavesDo*.php`, como `ChavesDoBlog`): o método
 *     que devolve `ChaveDeArquivo` recebe a entidade dona e lê dela o nome. O teste segue esse caminho
 *     um passo: abre cada método da entidade chamado ali (`getCaminhoArquivo()`,
 *     `chaveDoArquivo($tipo)`) e cada `$this->propriedade` de texto ou JSON lida nele vira, pelo
 *     metadata do Doctrine, `tabela.coluna`. Pega o nome antigo, o JSON do currículo e a coluna
 *     herdada (a foto do `Cliente`), sem lista feita à mão.
 *  2. **O nome (a rede para a coluna que ainda não tem fábrica).** Toda coluna mapeada com sufixo
 *     `_chave` (propriedade `*Chave`), a convenção das colunas novas. Pega a coluna que nasce antes do
 *     código que a lê, como a `rubrica_chave` (criada na etapa 1 da assinatura, escrita só na etapa
 *     2): sem fábrica, o sinal 1 não a vê. O prefixo `chave_` fica de fora: é PIX, idempotência,
 *     cifra, a chave remota da própria outbox.
 *
 * O limite: tabela sem entidade (só SQL) e coluna de nome livre sem fábrica não aparecem. A coluna de
 * nome livre aparece no dia em que uma fábrica a ler, que é o caminho da skill `armazenamento-de-arquivos`.
 *
 * ## O que conta como "está na reconciliação"
 *
 * Uma fonte de `FontesDeReferencia::FONTES` que lê a coluna antes do `WHERE`: na lista do `SELECT`
 * ou no `FROM`/`JOIN` (o `json_each(c.arquivos)` do currículo), com o apelido resolvido para a tabela.
 * Coluna que só aparece no filtro não conta.
 */
#[CoversNothing]
final class ColunasDeChaveNoEspelhoArquiteturaTest extends KernelTestCase
{
    /** Os tipos de coluna que podem guardar o nome de um arquivo: texto solto ou JSON com as chaves dentro. */
    private const TIPOS_DE_TEXTO = ['string', 'ascii_string', 'text', 'json', 'simple_array'];

    /**
     * As colunas que o sinal pega e que NÃO guardam chave de arquivo: uma linha, um motivo. Coluna de
     * arquivo fora da reconciliação não entra aqui; entra em `FontesDeReferencia`.
     */
    private const EXCECOES = [
        'artigo_blog.imagem_chave'                     => 'guarda o id da ImagemDoArtigo da capa; o arquivo é o de artigo_blog_imagem.chave',
        'assinatura_eletronica_documento.modelo_chave' => 'identificador do modelo de documento escolhido na tela, não arquivo',
        'comercial_campanha.modelo_chave'              => 'identificador do modelo de e-mail da campanha, não arquivo',
    ];

    private const ONDE_ACRESCENTAR = 'Acrescente uma fonte em FONTES de src/Shared/EspelhoRemoto/Reconciliacao/FontesDeReferencia.php '
        . '(a categoria da fábrica e o escopo: tenant_id da própria linha, ou a categoria em GLOBAIS). '
        . 'Se a coluna não guarda arquivo, ela vai para EXCECOES deste teste, com o motivo numa linha.';

    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
    }

    #[TestDox('toda coluna que guarda chave de arquivo está numa fonte da reconciliação do espelho')]
    public function testTodaColunaDeChaveEstaNaReconciliacao(): void
    {
        $lidas    = $this->colunasLidasPelasFontes();
        $faltando = [];

        foreach ($this->colunasDeChave() as $coluna => $origem) {
            if (!isset(self::EXCECOES[$coluna]) && !isset($lidas[$coluna])) {
                $faltando[] = $coluna . ' (' . $origem . ')';
            }
        }

        self::assertSame([], $faltando, 'Coluna que guarda chave de arquivo fora da reconciliação do espelho remoto. ' . self::ONDE_ACRESCENTAR);
    }

    /**
     * A prova de que o detector enxerga: em cada fonte que a reconciliação JÁ tem, ele acha a coluna
     * do arquivo. Sem isto, uma mudança no formato das fábricas zeraria a lista e o teste de cima
     * passaria sem conferir nada.
     */
    #[TestDox('o detector acha a coluna de cada fonte que a reconciliação já tem')]
    public function testDetectorAchaAsColunasDasFontes(): void
    {
        $colunas  = $this->colunasDeChave();
        $porFonte = $this->colunasLidasPorFonte();
        self::assertNotSame([], $porFonte, 'as fontes da reconciliação não foram lidas');

        $semSinal = [];
        foreach ($porFonte as $fonte => $lidas) {
            if (array_intersect($lidas, array_keys($colunas)) === []) {
                $semSinal[] = $fonte;
            }
        }

        self::assertSame([], $semSinal, 'fonte da reconciliação cuja coluna nenhuma fábrica lê e que não tem sufixo _chave: o detector parou de enxergar');
    }

    #[TestDox('cada exceção ainda existe, ainda é achada pelo sinal e não está numa fonte')]
    public function testExcecoesNaoVencidas(): void
    {
        $colunas = $this->colunasDeChave();
        $lidas   = $this->colunasLidasPelasFontes();

        foreach (array_keys(self::EXCECOES) as $coluna) {
            self::assertArrayHasKey($coluna, $colunas, $coluna . ': a exceção venceu (a coluna sumiu ou mudou de nome); tire-a da lista');
            self::assertArrayNotHasKey($coluna, $lidas, $coluna . ': a coluna já está numa fonte; tire-a da lista de exceções');
        }
    }

    /**
     * Os dois sinais somados. A origem diz quem achou: a fábrica (`Fabrica::metodo`) ou o nome.
     *
     * @return array<string, string> `tabela.coluna` => origem
     */
    private function colunasDeChave(): array
    {
        $colunas = $this->colunasLidasPelasFabricas();

        foreach ($this->em->getMetadataFactory()->getAllMetadata() as $meta) {
            if ($meta->isMappedSuperclass || $meta->isEmbeddedClass) {
                continue;
            }
            foreach ($meta->fieldMappings as $propriedade => $mapeamento) {
                if (\in_array($mapeamento->type, self::TIPOS_DE_TEXTO, true)
                    && (str_ends_with($mapeamento->columnName, '_chave') || preg_match('/[a-z]Chave$/', $propriedade) === 1)) {
                    $colunas[$this->tabelaDoCampo($meta, $propriedade) . '.' . $mapeamento->columnName] ??= $meta->getName() . '::$' . $propriedade . ', pelo sufixo _chave';
                }
            }
        }
        ksort($colunas);

        return $colunas;
    }

    /**
     * Sinal 1: cada método de fábrica que devolve `ChaveDeArquivo` e recebe entidade; cada método da
     * entidade que ele chama; cada propriedade de texto ou JSON que esse método lê.
     *
     * @return array<string, string> `tabela.coluna` => `Fabrica::metodo`
     */
    private function colunasLidasPelasFabricas(): array
    {
        $colunas = [];

        foreach (self::fabricas() as $fabrica) {
            foreach ((new \ReflectionClass($fabrica))->getMethods(\ReflectionMethod::IS_STATIC) as $metodo) {
                if (!str_contains((string) $metodo->getReturnType(), ChaveDeArquivo::class)) {
                    continue;
                }
                $corpo = self::corpo($metodo);

                foreach ($metodo->getParameters() as $parametro) {
                    $tipo = $parametro->getType();
                    if (!$tipo instanceof \ReflectionNamedType || $tipo->isBuiltin() || $this->em->getMetadataFactory()->isTransient($tipo->getName())) {
                        continue;
                    }
                    $entidade = $tipo->getName();
                    $meta     = $this->em->getClassMetadata($entidade);

                    preg_match_all('/\$' . $parametro->getName() . '->(\w+)\s*\(/', $corpo, $chamadas);
                    foreach (array_unique($chamadas[1]) as $chamado) {
                        if (!method_exists($entidade, $chamado)) {
                            continue;
                        }
                        foreach (self::propriedadesLidas(new \ReflectionMethod($entidade, $chamado)) as $propriedade) {
                            $mapeamento = $meta->fieldMappings[$propriedade] ?? null;
                            if ($mapeamento !== null && \in_array($mapeamento->type, self::TIPOS_DE_TEXTO, true)) {
                                $colunas[$this->tabelaDoCampo($meta, $propriedade) . '.' . $mapeamento->columnName] ??= $fabrica . '::' . $metodo->getName();
                            }
                        }
                    }
                }
            }
        }

        return $colunas;
    }

    /** @return array<string, true> `tabela.coluna` lida por alguma fonte */
    private function colunasLidasPelasFontes(): array
    {
        $lidas = [];
        foreach ($this->colunasLidasPorFonte() as $colunas) {
            foreach ($colunas as $coluna) {
                $lidas[$coluna] = true;
            }
        }

        return $lidas;
    }

    /**
     * Os identificadores que cada fonte lê antes do filtro, como `tabela.coluna`, com o apelido
     * resolvido. Palavra que não é coluna (SELECT, AS, o nome da tabela) também sai daqui, e é inócua:
     * só conta o que coincide com uma coluna de chave.
     *
     * @return array<string, list<string>> nome da fonte => `tabela.coluna`
     */
    private function colunasLidasPorFonte(): array
    {
        /** @var array<string, array{sql: string}> $fontes */
        $fontes   = (new \ReflectionClassConstant(FontesDeReferencia::class, 'FONTES'))->getValue();
        $porFonte = [];

        foreach ($fontes as $fonte => ['sql' => $sql]) {
            $semTexto = (string) preg_replace("/'[^']*'/", "''", $sql);
            $partes   = preg_split('/\b(?:WHERE|GROUP\s+BY|ORDER\s+BY)\b/i', $semTexto);
            $leitura  = \is_array($partes) ? $partes[0] : '';

            preg_match_all('/\b(?:FROM|JOIN)\s+(?!LATERAL\b)(\w+)(?:\s+(?!ON\b|JOIN\b|CROSS\b|LEFT\b|INNER\b)(\w+))?/i', $leitura, $tabelas, \PREG_SET_ORDER);
            if ($tabelas === []) {
                continue;
            }
            $principal = $tabelas[0][1];
            $apelidos  = [];
            foreach ($tabelas as $tabela) {
                $apelidos[$tabela[1]] = $tabela[1];
                if (($tabela[2] ?? '') !== '') {
                    $apelidos[$tabela[2]] = $tabela[1];
                }
            }

            preg_match_all('/(?<![\w.])(?:(\w+)\.)?(\w+)\b(?!\s*\.)/', $leitura, $identificadores, \PREG_SET_ORDER);
            $lidas = [];
            foreach ($identificadores as [, $apelido, $coluna]) {
                $tabela = $apelido === '' ? $principal : ($apelidos[$apelido] ?? null);
                if ($tabela !== null) {
                    $lidas[] = $tabela . '.' . $coluna;
                }
            }
            $porFonte[$fonte] = array_values(array_unique($lidas));
        }

        return $porFonte;
    }

    /**
     * A tabela onde o campo mora: a da entidade que o declara, para a coluna herdada (herança JOINED).
     *
     * @param ClassMetadata<object> $meta
     */
    private function tabelaDoCampo(ClassMetadata $meta, string $propriedade): string
    {
        $dona = $meta->fieldMappings[$propriedade]->inherited ?? $meta->getName();

        return $this->em->getClassMetadata($dona)->getTableName();
    }

    /** @return list<string> as propriedades que o método lê de `$this` (não as que ele chama) */
    private static function propriedadesLidas(\ReflectionMethod $metodo): array
    {
        preg_match_all('/\$this->(\w+)\b(?!\s*\()/', self::corpo($metodo), $lidas);

        return array_values(array_unique($lidas[1]));
    }

    private static function corpo(\ReflectionMethod $metodo): string
    {
        $arquivo = (string) $metodo->getFileName();
        $linhas  = file($arquivo) ?: [];

        return implode('', \array_slice($linhas, (int) $metodo->getStartLine() - 1, (int) $metodo->getEndLine() - (int) $metodo->getStartLine() + 1));
    }

    /**
     * As fábricas, descobertas como no `FabricasDeArquivoNovoTest`: `ChavesDe*` e `ChavesDo*`.
     *
     * @return list<class-string>
     */
    private static function fabricas(): array
    {
        $raiz     = \dirname(__DIR__, 2);
        $fabricas = [];

        foreach ([...(glob($raiz . '/src/*/Armazenamento/ChavesDe*.php') ?: []), ...(glob($raiz . '/src/*/Armazenamento/ChavesDo*.php') ?: [])] as $arquivo) {
            /** @var class-string $classe */
            $classe     = 'App\\' . basename(\dirname($arquivo, 2)) . '\\Armazenamento\\' . basename($arquivo, '.php');
            $fabricas[] = $classe;
        }
        sort($fabricas);

        return $fabricas;
    }
}
