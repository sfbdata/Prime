<?php

declare(strict_types=1);

namespace App\Tests\Cobranca\Unit;

use App\Cobranca\Entity\Carteira;
use App\Cobranca\Service\Espelho\AtribuidorDeCarteira;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/**
 * A atribuição de carteira na carga do histórico (SPEC espelho §4.4).
 *
 * Nenhum arquivo da contabilidade diz de qual carteira é — nem no conteúdo, nem sempre no nome.
 * Atribuir errado é o **pior modo de falha desta carga**: a conferência acusaria 100% de "falta" e
 * "sobra" naquela carteira, sem nenhum erro aparecer em lugar nenhum. Daí o rigor aqui.
 */
#[CoversClass(AtribuidorDeCarteira::class)]
final class AtribuidorDeCarteiraTest extends TestCase
{
    private AtribuidorDeCarteira $atribuidor;

    /** @var list<Carteira> */
    private array $carteiras;

    protected function setUp(): void
    {
        $this->atribuidor = new AtribuidorDeCarteira();
        $this->carteiras = [
            $this->carteira(1, 'TOP LIFE I'),
            $this->carteira(2, 'TOP LIFE II'),
            $this->carteira(3, 'AMLI BR 060'),
        ];
    }

    /**
     * O caso que quebrou a carga de verdade, e o motivo de existir esta classe.
     *
     * `TOPLIFE I_Inadimplências…` normalizado sem separador vira `toplifeiinadimplencias…`: o `I` do
     * nome, colado ao `I` de "Inadimplências", forma um `ii` que casa com o apelido da TOP LIFE
     * **II**. Na primeira execução da carga do acervo, 3.951 linhas da TOP LIFE I foram parar na
     * carteira errada — sem erro, sem aviso, com o comando reportando sucesso.
     */
    #[DataProvider('nomesDeArquivo')]
    #[TestDox('O nome do arquivo aponta a carteira certa')]
    public function testAtribuiPeloNome(string $arquivo, string $carteiraEsperada): void
    {
        $carteira = $this->atribuidor->porNome($arquivo, $this->carteiras);

        self::assertNotNull($carteira, sprintf('"%s" deveria ter sido reconhecido', $arquivo));
        self::assertSame($carteiraEsperada, $carteira->getNome());
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function nomesDeArquivo(): iterable
    {
        // Prefixo canônico — cobre 19 dos 23 arquivos do acervo.
        yield 'canônico TL1' => ['top_life_1_Inadimplencias_detalhadas.xlsx', 'TOP LIFE I'];
        yield 'canônico TL2' => ['top_life_2_Inadimplencias_detalhadas.xlsx', 'TOP LIFE II'];
        yield 'canônico AMLI' => ['amli_br_060_Inadimplencias_detalhadas.xlsx', 'AMLI BR 060'];

        // Grafia livre — os outros 3. O primeiro é o que ia para a carteira errada.
        yield 'REGRESSÃO: I colado em Inadimplências' => [
            'TOPLIFE I_Inadimplências_detalhadas_2026_07_08_10_15_03 (1) (1).xlsx',
            'TOP LIFE I',
        ];
        yield 'grafia livre TL2 com espaço' => [
            'TOPLIFE II_Inadimplências_detalhadas_2026_07_08_10_04_30 (1).xlsx',
            'TOP LIFE II',
        ];
        yield 'grafia livre TL2 sem espaço' => [
            'toplifeII_Inadimplências_detalhadas_2026_07_22_10_08_13.xlsx',
            'TOP LIFE II',
        ];
    }

    #[TestDox('Arquivo sem carteira no nome NÃO é adivinhado')]
    public function testNomeAnonimoNaoEhAdivinhado(): void
    {
        // É o `Inadimplências_detalhadas_2026_07_29_16_06_05.xlsx` do acervo: existe de verdade e
        // não traz carteira nem no nome nem no conteúdo.
        self::assertNull(
            $this->atribuidor->porNome('Inadimplências_detalhadas_2026_07_29_16_06_05.xlsx', $this->carteiras)
        );
    }

    #[TestDox('A alíquota de 20% identifica a TOP LIFE I; 15% não decide nada')]
    public function testHonorariosSoDesempatamATopLifeUm(): void
    {
        $porVinte = $this->atribuidor->porHonorarios(
            ['juros' => 100, 'multa' => 200, 'honorarios' => 2000],
            $this->carteiras
        );

        self::assertSame('TOP LIFE I', $porVinte?->getNome());

        // TOP LIFE II e AMLI declaram AMBAS 15%: este nível é mudo para elas, e dizer isso é melhor
        // do que fingir que decide.
        self::assertNull($this->atribuidor->porHonorarios(
            ['juros' => 100, 'multa' => 200, 'honorarios' => 1500],
            $this->carteiras
        ));
    }

    #[TestDox('A sobreposição de unidades decide quando há maioria folgada')]
    public function testAtribuiPelaSobreposicaoDeUnidades(): void
    {
        // Reproduz o caso real: 100% de contenção na TL2 contra 5,6% na TL1.
        $carteira = $this->atribuidor->porUnidades(
            ['01-01', '01-02', '01-03', '01-04'],
            [
                1 => ['09-01', '09-02', '09-03'],
                2 => ['01-01', '01-02', '01-03', '01-04', '01-05'],
            ],
            $this->carteiras
        );

        self::assertSame('TOP LIFE II', $carteira?->getNome());
    }

    #[TestDox('Sobreposição fraca ou disputada NÃO atribui — recusa é o desfecho certo')]
    public function testSobreposicaoInsuficienteRecusa(): void
    {
        // Abaixo do piso de 90%.
        self::assertNull($this->atribuidor->porUnidades(
            ['01-01', '01-02', '01-03', '01-04'],
            [1 => ['01-01', '01-02']],
            $this->carteiras
        ));

        // Empate: duas carteiras contêm tudo. Sem os 5× de vantagem, não decide.
        self::assertNull($this->atribuidor->porUnidades(
            ['01-01', '01-02'],
            [
                1 => ['01-01', '01-02'],
                2 => ['01-01', '01-02'],
            ],
            $this->carteiras
        ));
    }

    #[TestDox('Espelho vazio não atribui nada — é o que exige a carga em duas passadas')]
    public function testEspelhoVazioNaoAtribui(): void
    {
        self::assertNull($this->atribuidor->porUnidades(['01-01'], [], $this->carteiras));
    }

    private function carteira(int $id, string $nome): Carteira
    {
        $carteira = new Carteira();
        $carteira->setNome($nome);

        $reflexao = new \ReflectionProperty(Carteira::class, 'id');
        $reflexao->setValue($carteira, $id);

        return $carteira;
    }
}
