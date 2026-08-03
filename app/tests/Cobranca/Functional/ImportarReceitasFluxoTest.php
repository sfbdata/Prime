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
 * O teste central é **prévia × confirmação nos 13 campos**, não por amostra: nesta frente a prévia já
 * mentiu duas vezes, e das duas o que a desmascarava era um campo que ninguém comparava.
 */
#[CoversClass(ImportarReceitasUseCase::class)]
final class ImportarReceitasFluxoTest extends CobrancaWebTestCase
{
    #[TestDox('🔑 Prévia e confirmação batem nos 13 campos — inclusive objetos, pessoas e casos criados')]
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
            'a prévia tem de projetar EXATAMENTE o que a confirmação faz — os 13 campos, não uma amostra',
        );

        // E os números são os esperados, senão "idênticas" poderia significar "idênticas e erradas".
        self::assertSame(2, $previa->objetosCriados, 'duas unidades, não três recebimentos');
        self::assertSame(2, $previa->casosCriados);
        self::assertSame(2, $previa->pessoasCriadas, 'a pessoa nasce junto do caso');
        self::assertCount(3, $previa->pagamentosCriados);
        self::assertCount(3, $previa->obrigacoesCriadas, 'nenhum desses NNs existia');
        self::assertSame(31500, $previa->totalRecebidoCentavos, '100 + 120 + (80+5+10)');
        self::assertSame(1000, $previa->honorariosCentavos);
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
     * Achata o resultado nos 13 campos, para o assert comparar TUDO de uma vez. Comparar campo a campo
     * à mão foi como o defeito escapou antes: quem escreve o assert escolhe o que olhar, e escolhe mal.
     *
     * @return array<string, mixed>
     */
    private function achatar(ResultadoImportacaoReceitas $r): array
    {
        return [
            'pagamentosCriados' => $r->pagamentosCriados,
            'jaImportados' => $r->jaImportados,
            'obrigacoesCriadas' => $r->obrigacoesCriadas,
            'obrigacoesExistentes' => $r->obrigacoesExistentes,
            'rejeitadas' => count($r->rejeitadas),
            'linhasIgnoradas' => $r->linhasIgnoradas,
            'emAberto' => $r->emAberto,
            'objetosCriados' => $r->objetosCriados,
            'pessoasCriadas' => $r->pessoasCriadas,
            'casosCriados' => $r->casosCriados,
            'acordosCriados' => $r->acordosCriados,
            'totalRecebidoCentavos' => $r->totalRecebidoCentavos,
            'honorariosCentavos' => $r->honorariosCentavos,
            'recuperadoDividaCentavos' => $r->recuperadoDividaCentavos(),
        ];
    }
}
