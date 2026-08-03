<?php

declare(strict_types=1);

namespace App\Tests\Cobranca\Functional;

use App\Cobranca\Entity\CasoCobranca;
use App\Cobranca\Entity\Obrigacao;
use App\Cobranca\Service\CalculadoraSaldo;
use App\Cobranca\Service\Importacao\ReceitaImportavel;
use App\Cobranca\Service\Importacao\ResultadoImportacaoReceitas;
use App\Cobranca\Service\Importacao\ResultadoLeituraReceitas;
use App\Cobranca\UseCase\ImportarReceitasUseCase;
use App\Tests\Factory\Cobranca\ObrigacaoFactory;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;

/**
 * Importação de Receitas contra o BANCO REAL (spec `cobranca-importar-receitas.md`).
 *
 * O teste central é **prévia × confirmação em TODOS os campos**, não por amostra: nesta frente a prévia
 * já mentiu duas vezes, e das duas o que a desmascarava era um campo que ninguém comparava.
 *
 * ⚠️ O número de campos NÃO é escrito em lugar nenhum de propósito. Ele já esteve como "13" aqui, "13"
 * no UseCase e "18" na spec, com o `achatar()` comparando 16 — três fontes, nenhuma certa, numa etapa
 * cuja spec foi reescrita justamente por causa de números que não se reproduziam. Agora `achatar()`
 * deriva os campos por reflexão e `testAchatarCobreTodoOResultado` prova que nenhum escapou: campo novo
 * no DTO entra na comparação sozinho, ou o teste falha.
 */
#[CoversClass(ImportarReceitasUseCase::class)]
final class ImportarReceitasFluxoTest extends CobrancaWebTestCase
{
    #[TestDox('🔑 Prévia e confirmação batem em TODOS os campos — inclusive objetos, pessoas e casos criados')]
    public function testPreviaEConfirmacaoSaoIdenticas(): void
    {
        $client = static::createClient();
        [, $tenant] = $this->criarAdminLogado($client);
        [$carteira] = $this->semearGrafo($tenant);
        $carteiraId = (int) $carteira->getId();
        $usuario = $this->em()->getRepository(\App\Entity\Auth\User::class)->findAll()[0];

        // Duas unidades NOVAS, uma delas com DOIS recebimentos: é o cenário que pega a prévia sem
        // estado — ela contaria dois objetos para a mesma unidade e prometeria um número que a
        // confirmação não entrega.
        $leitura = $this->leitura([
            $this->receita('CHACARA 90', 'Fulano', '8001', '05/2026', '15/05/2026', divida: 10000),
            $this->receita('CHACARA 90', 'Fulano', '8002', '06/2026', '15/06/2026', divida: 12000),
            $this->receita('CHACARA 91', 'Beltrano', '8003', '05/2026', '16/05/2026', divida: 8000, juros: 500, honorarios: 1000),
        ]);

        $sut = static::getContainer()->get(ImportarReceitasUseCase::class);

        $previa = $sut->prever($carteiraId, $leitura, $tenant);
        $confirmacao = $sut->confirmar($carteiraId, $leitura, $tenant, $usuario);

        self::assertSame(
            $this->achatar($previa),
            $this->achatar($confirmacao),
            'a prévia tem de projetar EXATAMENTE o que a confirmação faz — todos os campos, não uma amostra',
        );

        // E os números são os esperados, senão "idênticas" poderia significar "idênticas e erradas".
        self::assertSame(2, $previa->objetosCriados, 'duas unidades, não três recebimentos');
        self::assertSame(2, $previa->casosCriados);
        self::assertSame(2, $previa->pessoasCriadas, 'a pessoa nasce junto do caso');
        self::assertCount(3, $previa->pagamentosCriados);
        self::assertCount(3, $previa->obrigacoesCriadas, 'nenhum desses NNs existia');
        self::assertSame(31500, $previa->totalRecebidoCentavos, '100 + 120 + (80+5+10)');
        self::assertSame(1000, $previa->honorariosCentavos);

        // Os TRÊS baldes, e não só o total: é assim que a conferência contra a contabilidade é feita
        // (o rodapé do relatório imprime o recebido POR CLASSE DE CONTA). Conferir apenas o total
        // deixaria passar uma troca entre baldes — principal contado como encargo fecha na soma e
        // rateia errado no `Pagamento`, que é o que alimenta o split de honorários.
        self::assertSame(500, $previa->encargosCentavos, 'os R$ 5,00 de juros do terceiro recebimento');
        self::assertSame(30000, $previa->principalCentavos(), '100 + 120 + 80');
        self::assertSame(
            $previa->totalRecebidoCentavos,
            $previa->principalCentavos() + $previa->encargosCentavos + $previa->honorariosCentavos,
            'os três baldes têm de reconstituir o total — é o invariante que a conferência usa',
        );
    }

    #[TestDox('🔑 Reimportar o mesmo arquivo não cria pagamento nenhum na segunda vez')]
    public function testReimportarNaoDuplica(): void
    {
        $client = static::createClient();
        [, $tenant] = $this->criarAdminLogado($client);
        [$carteira] = $this->semearGrafo($tenant);
        $carteiraId = (int) $carteira->getId();
        $usuario = $this->em()->getRepository(\App\Entity\Auth\User::class)->findAll()[0];
        $leitura = $this->leitura([
            $this->receita('CHACARA 92', 'Fulano', '8010', '05/2026', '15/05/2026', divida: 10000),
        ]);

        $sut = static::getContainer()->get(ImportarReceitasUseCase::class);
        $primeira = $sut->confirmar($carteiraId, $leitura, $tenant, $usuario);
        self::assertCount(1, $primeira->pagamentosCriados);

        $this->em()->clear();
        $segunda = $sut->confirmar($carteiraId, $leitura, $tenant, $usuario);

        self::assertSame([], $segunda->pagamentosCriados, 'rodar duas vezes não pode duplicar dinheiro');
        self::assertSame(['8010'], $segunda->jaImportados);
        self::assertSame(0, $segunda->objetosCriados, 'nem recriar a unidade');
        self::assertSame(
            1,
            (int) $this->em()->getConnection()->fetchOne('SELECT COUNT(*) FROM cobranca_pagamento WHERE tenant_id = ?', [$tenant->getId()]),
            'um pagamento no banco, não dois',
        );
    }

    #[TestDox('🔑 Obrigação criada nasce PAGA: o saldo do caso não se move')]
    public function testObrigacaoCriadaNaoMexeNoSaldo(): void
    {
        $client = static::createClient();
        [, $tenant] = $this->criarAdminLogado($client);
        [$carteira, $caso] = $this->semearGrafo($tenant);
        $usuario = $this->em()->getRepository(\App\Entity\Auth\User::class)->findAll()[0];

        // Uma dívida REAL em aberto no caso, que tem de continuar aparecendo intacta no saldo.
        ObrigacaoFactory::createOne([
            'tenant' => $tenant, 'caso' => $caso, 'valorOriginal' => 30000, 'encargosReconhecidos' => 0,
        ]);
        $casoId = (int) $caso->getId();
        $identificacao = $caso->getObjeto()->getIdentificacao();

        $calc = static::getContainer()->get(CalculadoraSaldo::class);
        $saldoAntes = $calc->saldoExigivel($this->em()->find(CasoCobranca::class, $casoId));
        self::assertSame(30000, $saldoAntes, 'pré-condição: o caso deve 300,00');

        // O recebimento entra na MESMA unidade, criando uma obrigação nova já paga.
        static::getContainer()->get(ImportarReceitasUseCase::class)->confirmar(
            (int) $carteira->getId(),
            $this->leitura([$this->receita($identificacao, 'Fulano', '8020', '04/2026', '10/04/2026', divida: 25000, juros: 700)]),
            $tenant,
            $usuario,
        );
        $this->em()->clear();

        $calc = static::getContainer()->get(CalculadoraSaldo::class);
        self::assertSame(
            30000,
            $calc->saldoExigivel($this->em()->find(CasoCobranca::class, $casoId)),
            'histórico pago entra e sai da conta no mesmo valor — a dívida em aberto não pode mudar',
        );

        // E a obrigação criada está mesmo marcada como paga e congelada.
        $criada = $this->em()->getRepository(Obrigacao::class)->findOneBy(['referenciaExterna' => '8020']);
        self::assertNotNull($criada);
        self::assertTrue($criada->estaLiquidada(), 'ela nasce quitada — é história, não dívida viva');
        self::assertTrue($criada->encargosCongelados(), 'e não volta a crescer');
        self::assertSame(25700, $criada->valorExigivel(), 'principal + juros que a planilha diz terem sido pagos');
    }

    #[TestDox('🔑 Recebimento que pousa em obrigação JÁ EXISTENTE não cria outra — e a quita')]
    public function testRecebimentoPousaNaObrigacaoQueJaExiste(): void
    {
        $client = static::createClient();
        [, $tenant] = $this->criarAdminLogado($client);
        [$carteira, $caso] = $this->semearGrafo($tenant);
        $usuario = $this->em()->getRepository(\App\Entity\Auth\User::class)->findAll()[0];
        $identificacao = $caso->getObjeto()->getIdentificacao();
        $casoId = (int) $caso->getId();

        // O ramo raro, mas real: medido no dry-run de 03/08, 4 recebimentos dos 2.077 pousam numa
        // obrigação que a Inadimplência já tinha trazido. Ele nunca era exercitado — todos os cenários
        // caíam em "obrigação criada" —, e é justamente o ramo que decide se o dinheiro abate uma
        // dívida existente ou inventa um boleto paralelo ao lado dela.
        ObrigacaoFactory::createOne([
            'tenant' => $tenant, 'caso' => $caso,
            'descricao' => 'Taxa 05/2026', 'valorOriginal' => 20000, 'encargosReconhecidos' => 0,
            'referenciaExterna' => '8030', 'competencia' => '05/2026',
            'vencimentoOriginal' => new \DateTimeImmutable('2026-05-10'),
        ]);

        $calc = static::getContainer()->get(CalculadoraSaldo::class);
        self::assertSame(20000, $calc->saldoExigivel($this->em()->find(CasoCobranca::class, $casoId)), 'pré-condição');

        $resultado = static::getContainer()->get(ImportarReceitasUseCase::class)->confirmar(
            (int) $carteira->getId(),
            $this->leitura([$this->receita($identificacao, 'Fulano', '8030', '05/2026', '20/05/2026', divida: 20000)]),
            $tenant,
            $usuario,
        );

        self::assertSame(['8030'], $resultado->obrigacoesExistentes, 'pousou na que já existia');
        self::assertSame([], $resultado->obrigacoesCriadas, 'e NÃO criou uma segunda ao lado');

        $this->em()->clear();
        self::assertSame(
            1,
            (int) $this->em()->getConnection()->fetchOne(
                'SELECT COUNT(*) FROM cobranca_obrigacao WHERE tenant_id = ? AND referencia_externa = ?',
                [$tenant->getId(), '8030'],
            ),
            'uma obrigação com esse NN no banco, não duas',
        );

        // E o dinheiro fez o que faria digitado à mão: abateu a dívida que já existia.
        $calc = static::getContainer()->get(CalculadoraSaldo::class);
        self::assertSame(
            0,
            $calc->saldoExigivel($this->em()->find(CasoCobranca::class, $casoId)),
            'o recebimento quita a obrigação preexistente — o saldo cai a zero',
        );
    }

    // ---------------------------------------------------------------- helpers

    private function em(): EntityManagerInterface
    {
        return static::getContainer()->get(EntityManagerInterface::class);
    }

    /** @param list<ReceitaImportavel> $receitas */
    private function leitura(array $receitas): ResultadoLeituraReceitas
    {
        return new ResultadoLeituraReceitas($receitas, [], 0, 0);
    }

    private function receita(
        string $unidade,
        string $sacado,
        string $nn,
        string $competencia,
        string $recebimento,
        int $divida,
        int $juros = 0,
        int $multa = 0,
        int $honorarios = 0,
    ): ReceitaImportavel {
        return new ReceitaImportavel(
            nn: $nn,
            objetoIdentificacao: $unidade,
            unidadeMetadata: null,
            sacadoNome: $sacado,
            competencia: $competencia,
            vencimento: new \DateTimeImmutable('2026-05-10'),
            recebimento: new \DateTimeImmutable($this->paraIso($recebimento)),
            valorDividaCentavos: $divida,
            valorJurosCentavos: $juros,
            valorMultaCentavos: $multa,
            valorHonorariosCentavos: $honorarios,
            acordo: null,
            linhas: [],
        );
    }

    private function paraIso(string $ddmmaaaa): string
    {
        [$d, $m, $a] = explode('/', $ddmmaaaa);

        return sprintf('%s-%s-%s', $a, $m, $d);
    }

    /**
     * Achata o resultado em TODOS os campos, para o assert comparar tudo de uma vez. Comparar campo a
     * campo à mão foi como o defeito escapou antes: quem escreve o assert escolhe o que olhar, e
     * escolhe mal.
     *
     * Os campos vêm por REFLEXÃO, e não de uma lista escrita à mão, para que um campo novo no DTO caia
     * na comparação sem ninguém lembrar de acrescentá-lo aqui. `rejeitadas` vira contagem (os objetos
     * `LinhaRejeitada` são os mesmos nos dois caminhos, mas comparar instâncias não diz nada útil), e
     * os dois derivados entram porque é neles que uma troca entre baldes apareceria.
     *
     * @return array<string, mixed>
     */
    private function achatar(ResultadoImportacaoReceitas $r): array
    {
        $achatado = [];
        foreach ((new \ReflectionClass($r))->getProperties(\ReflectionProperty::IS_PUBLIC) as $propriedade) {
            $nome = $propriedade->getName();
            $valor = $propriedade->getValue($r);
            $achatado[$nome] = $nome === 'rejeitadas' ? count((array) $valor) : $valor;
        }

        $achatado['recuperadoDividaCentavos'] = $r->recuperadoDividaCentavos();
        $achatado['principalCentavos'] = $r->principalCentavos();
        ksort($achatado);

        return $achatado;
    }

    #[TestDox('O comparador prévia×confirmação cobre TODO campo público do resultado')]
    public function testAchatarCobreTodoOResultado(): void
    {
        // A guarda da guarda. O assert de prévia×confirmação só vale pelo que ele compara — e a lista
        // de campos já ficou desatualizada em três lugares diferentes nesta etapa. Se alguém acrescentar
        // um campo ao `ResultadoImportacaoReceitas` e o `achatar()` não o pegar, é aqui que estoura,
        // antes de o campo novo poder divergir em silêncio entre os dois caminhos.
        $vazio = new ResultadoImportacaoReceitas([], [], [], [], [], 0, 0, 0, 0, 0, 0, 0, 0);

        $publicos = array_map(
            static fn (\ReflectionProperty $p): string => $p->getName(),
            (new \ReflectionClass(ResultadoImportacaoReceitas::class))->getProperties(\ReflectionProperty::IS_PUBLIC),
        );
        sort($publicos);

        $cobertos = array_keys($this->achatar($vazio));

        self::assertSame([], array_values(array_diff($publicos, $cobertos)), 'campo público fora da comparação');
    }
}
