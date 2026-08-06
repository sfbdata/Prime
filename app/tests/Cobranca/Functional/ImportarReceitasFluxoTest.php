<?php

declare(strict_types=1);

namespace App\Tests\Cobranca\Functional;

use App\Cobranca\Entity\CasoCobranca;
use App\Cobranca\Entity\Obrigacao;
use App\Cobranca\Entity\Pessoa;
use App\Cobranca\Service\CalculadoraSaldo;
use App\Cobranca\Service\Importacao\ReceitaImportavel;
use App\Cobranca\Service\Importacao\ResultadoImportacaoReceitas;
use App\Cobranca\Service\Importacao\ResultadoLeituraReceitas;
use App\Cobranca\UseCase\ImportarReceitasUseCase;
use App\Tests\Factory\Cobranca\ObjetoCobrancaFactory;
use App\Tests\Factory\Cobranca\ObrigacaoFactory;
use App\Tests\Factory\Cobranca\PessoaFactory;
use App\Tests\Factory\Cobranca\VinculoPessoaObjetoFactory;
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
 * deriva os campos por REFLEXÃO: campo novo no DTO entra na comparação sozinho, sem ninguém lembrar.
 *
 * Sobre `testAchatarCobreTodoOResultado`, e para não vender o que ele não é (achado da 2ª revisão):
 * ele NÃO "estoura quando um campo novo entra" — com `achatar()` derivando da mesma reflexão, o campo
 * novo já entra e o `array_diff` é vazio por construção. O que ele guarda é a REGRESSÃO do mecanismo:
 * se alguém trocar o `achatar()` por uma lista escrita à mão (que foi como a cobertura se perdeu da
 * primeira vez), ele passa a falhar no primeiro campo esquecido.
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

    #[TestDox('🔑 Unidade vinda do CADASTRO: reusa a pessoa e a prévia bate — não duplica o devedor')]
    public function testUnidadeDoCadastroReusaPessoaEMantemParidade(): void
    {
        $client = static::createClient();
        [, $tenant] = $this->criarAdminLogado($client);
        [$carteira] = $this->semearGrafo($tenant);
        $carteiraId = (int) $carteira->getId();
        $usuario = $this->em()->getRepository(\App\Entity\Auth\User::class)->findAll()[0];

        // Estado que o importe de CADASTRO deixa: unidade + pessoa (com CPF) + vínculo, e NENHUM caso.
        // Medido na AMLI antes da correção: 45 das 51 unidades ganhavam uma 2ª pessoa, sem documento, e
        // o caso passava a cobrar essa cópia. Spec: cobranca-importe-nao-duplica-devedor-do-cadastro.md
        $objeto = ObjetoCobrancaFactory::createOne([
            'tenant' => $tenant,
            'carteira' => $carteira,
            'identificacao' => 'QUADRA D LOTE 05',
        ])->_real();
        $pessoaDoCadastro = PessoaFactory::createOne([
            'tenant' => $tenant,
            'nome' => 'EDIMAR DE BRITO CERQUEIRA',
            'cpf' => '80778534120',
        ])->_real();
        VinculoPessoaObjetoFactory::createOne(['tenant' => $tenant, 'pessoa' => $pessoaDoCadastro, 'objeto' => $objeto]);

        $leitura = $this->leitura([
            $this->receita('QUADRA D LOTE 05', 'EDIMAR DE BRITO CERQUEIRA', '9101', '05/2026', '15/05/2026', divida: 17000),
        ]);

        $sut = static::getContainer()->get(ImportarReceitasUseCase::class);
        $previa = $sut->prever($carteiraId, $leitura, $tenant);
        $confirmacao = $sut->confirmar($carteiraId, $leitura, $tenant, $usuario);

        self::assertSame(
            $this->achatar($previa),
            $this->achatar($confirmacao),
            'prévia e confirmação têm de bater também neste cenário, em TODOS os campos',
        );
        self::assertSame(0, $previa->pessoasCriadas, 'a pessoa do cadastro é REUSADA, não duplicada');
        self::assertSame(0, $previa->objetosCriados, 'a unidade já existia');
        self::assertSame(1, $previa->casosCriados, 'o caso continua nascendo');

        $caso = $this->em()->getRepository(CasoCobranca::class)->findOneBy(['objeto' => $objeto]);
        self::assertSame(
            $pessoaDoCadastro->getId(),
            $caso?->getPessoaCobradaAtual()?->getId(),
            'o caso cobra a pessoa COM CPF, não uma cópia sem documento',
        );

        // 🔑 O contador NÃO é prova suficiente: numa injeção de defeito ele continuou dizendo "0 pessoas
        // criadas" enquanto o banco ganhava uma segunda pessoa. Quem prova é a contagem no banco.
        self::assertCount(
            1,
            $this->em()->getRepository(Pessoa::class)->findBy(['tenant' => $tenant, 'nome' => 'EDIMAR DE BRITO CERQUEIRA']),
            'no banco tem de existir UMA pessoa com esse nome — o contador pode mentir, a tabela não',
        );
    }

    #[TestDox('🔑 Unidade do cadastro com VÁRIOS recebimentos: prévia e confirmação batem em todos eles')]
    public function testUnidadeDoCadastroComVariosRecebimentosMantemParidade(): void
    {
        $client = static::createClient();
        [, $tenant] = $this->criarAdminLogado($client);
        [$carteira] = $this->semearGrafo($tenant);
        $carteiraId = (int) $carteira->getId();
        $usuario = $this->em()->getRepository(\App\Entity\Auth\User::class)->findAll()[0];

        // 🔑 Este é o arranjo REAL: na AMLI são 319 recebimentos para 45 unidades, ~7 por unidade. O
        // cenário de UMA linha (que o teste vizinho cobre) é a exceção, não a regra.
        //
        // ⚠️ Honestidade sobre o que este teste NÃO prova: removendo a memorização de
        // `pessoaJaNoObjeto()` ele continua VERDE (injeção feita, e passou). A paridade aqui é
        // sustentada pelo gate `casosVistos` de `EstadoDaImportacaoDeReceitas`, que descarta o valor
        // fora do primeiro encontro. A memorização é defesa em profundidade — tira a paridade da
        // dependência de um gate distante e corta as consultas de uma por LINHA para uma por UNIDADE.
        // O que este teste trava de verdade é o arranjo multi-linha funcionando ponta a ponta.
        $objeto = ObjetoCobrancaFactory::createOne([
            'tenant' => $tenant,
            'carteira' => $carteira,
            'identificacao' => 'QUADRA E LOTE 14',
        ])->_real();
        $pessoaDoCadastro = PessoaFactory::createOne([
            'tenant' => $tenant,
            'nome' => 'PAULO ROBERTO RAMOS DE CASTRO',
            'cpf' => '02002755930',
        ])->_real();
        VinculoPessoaObjetoFactory::createOne(['tenant' => $tenant, 'pessoa' => $pessoaDoCadastro, 'objeto' => $objeto]);

        $leitura = $this->leitura([
            $this->receita('QUADRA E LOTE 14', 'PAULO ROBERTO RAMOS DE CASTRO', '9201', '03/2026', '15/03/2026', divida: 17000),
            $this->receita('QUADRA E LOTE 14', 'PAULO ROBERTO RAMOS DE CASTRO', '9202', '04/2026', '15/04/2026', divida: 17000),
            $this->receita('QUADRA E LOTE 14', 'PAULO ROBERTO RAMOS DE CASTRO', '9203', '05/2026', '15/05/2026', divida: 17000),
        ]);

        $sut = static::getContainer()->get(ImportarReceitasUseCase::class);
        $previa = $sut->prever($carteiraId, $leitura, $tenant);
        $confirmacao = $sut->confirmar($carteiraId, $leitura, $tenant, $usuario);

        self::assertSame(
            $this->achatar($previa),
            $this->achatar($confirmacao),
            'com 3 recebimentos da MESMA unidade os dois modos têm de bater em todos os campos',
        );
        self::assertSame(0, $previa->pessoasCriadas, 'nenhuma pessoa nasce: a do cadastro é reusada');
        self::assertSame(1, $previa->casosCriados, 'um caso para a unidade, não um por recebimento');
        self::assertCount(3, $previa->pagamentosCriados, 'os três recebimentos entram');

        self::assertCount(
            1,
            $this->em()->getRepository(Pessoa::class)->findBy(['tenant' => $tenant, 'nome' => 'PAULO ROBERTO RAMOS DE CASTRO']),
            'uma pessoa no banco, mesmo com três linhas',
        );
    }

    #[TestDox('Sentido contrário: sacado com outro nome na unidade do cadastro continua criando pessoa')]
    public function testSacadoDeOutroNomeNaUnidadeDoCadastroCriaPessoa(): void
    {
        $client = static::createClient();
        [, $tenant] = $this->criarAdminLogado($client);
        [$carteira] = $this->semearGrafo($tenant);
        $carteiraId = (int) $carteira->getId();
        $usuario = $this->em()->getRepository(\App\Entity\Auth\User::class)->findAll()[0];

        // QUADRA D LOTE 03 da AMLI tem DOIS proprietários distintos: unir seria tão defeito quanto duplicar.
        $objeto = ObjetoCobrancaFactory::createOne([
            'tenant' => $tenant,
            'carteira' => $carteira,
            'identificacao' => 'QUADRA D LOTE 03',
        ])->_real();
        $pessoa = PessoaFactory::createOne(['tenant' => $tenant, 'nome' => 'CARLOS ALBERTO DE LIMA', 'cpf' => '35172711104'])->_real();
        VinculoPessoaObjetoFactory::createOne(['tenant' => $tenant, 'pessoa' => $pessoa, 'objeto' => $objeto]);

        $leitura = $this->leitura([
            $this->receita('QUADRA D LOTE 03', 'EDUARDO TAVARES DE LIMA', '9102', '05/2026', '15/05/2026', divida: 17000),
        ]);

        $sut = static::getContainer()->get(ImportarReceitasUseCase::class);
        $previa = $sut->prever($carteiraId, $leitura, $tenant);
        $confirmacao = $sut->confirmar($carteiraId, $leitura, $tenant, $usuario);

        self::assertSame($this->achatar($previa), $this->achatar($confirmacao));
        self::assertSame(1, $previa->pessoasCriadas, 'nome diferente é outra pessoa: continua nascendo');
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

    #[TestDox('Recebimento MAIOR que o exigível da obrigação existente: aloca o que a planilha diz')]
    public function testRecebimentoMaiorQueOExigivelAlocaOValorCheio(): void
    {
        $client = static::createClient();
        [, $tenant] = $this->criarAdminLogado($client);
        [$carteira, $caso] = $this->semearGrafo($tenant);
        $usuario = $this->em()->getRepository(\App\Entity\Auth\User::class)->findAll()[0];
        $identificacao = $caso->getObjeto()->getIdentificacao();
        $casoId = (int) $caso->getId();

        // ⚠️ Não é hipótese: medido no dry-run de 03/08, **3 dos 4** recebimentos que pousam em
        // obrigação preexistente pagam MAIS que o exigível dela — R$ 0,62, R$ 0,20 e R$ 0,80. A causa
        // é banal: os encargos que o sistema calculou não são os que a contabilidade cobrou.
        //
        // O importador aloca o valor CHEIO da planilha, e isso é deliberado. A régua do dono é "o que
        // vem da planilha entra", e o total recebido tem de bater ao centavo com a contabilidade (§8);
        // limitar a alocação ao exigível faria o excedente sumir e o total deixar de fechar.
        //
        // O efeito é o mesmo de uma alocação manual super-dimensionada, que o sistema já aceita: o
        // `restante` da linha tem piso 0 (não aparece negativo na tela) e o excedente abate o SALDO DO
        // CASO. Este teste existe para essa escolha ser consciente e vigiada, não acidental.
        ObrigacaoFactory::createOne([
            'tenant' => $tenant, 'caso' => $caso,
            'descricao' => 'Taxa 06/2026', 'valorOriginal' => 17482, 'encargosReconhecidos' => 0,
            'referenciaExterna' => '8050', 'competencia' => '06/2026',
            'vencimentoOriginal' => new \DateTimeImmutable('2026-06-10'),
        ]);

        $resultado = static::getContainer()->get(ImportarReceitasUseCase::class)->confirmar(
            (int) $carteira->getId(),
            // R$ 175,44 contra R$ 174,82 de exigível: 62 centavos a mais, o caso real do NN 61161.
            $this->leitura([$this->receita($identificacao, 'Fulano', '8050', '06/2026', '20/06/2026', divida: 17544)]),
            $tenant,
            $usuario,
        );

        self::assertSame(['8050'], $resultado->obrigacoesExistentes);
        self::assertSame(17544, $resultado->totalRecebidoCentavos, 'entra o que a planilha diz, não o exigível');

        $this->em()->clear();
        self::assertSame(
            17544,
            (int) $this->em()->getConnection()->fetchOne(
                'SELECT COALESCE(SUM(a.valor), 0) FROM cobranca_alocacao_pagamento a
                   JOIN cobranca_obrigacao o ON o.id = a.obrigacao_id
                  WHERE o.referencia_externa = ? AND a.tenant_id = ?',
                ['8050', $tenant->getId()],
            ),
            'a alocação leva o valor cheio recebido',
        );

        // O excedente vai para o saldo do caso, que fica NEGATIVO quando não há outra dívida. É o
        // comportamento que o sistema já tinha para alocação manual — registrado aqui, não corrigido
        // por conta própria: mexer nisso é decisão de dinheiro, e é do dono.
        $calc = static::getContainer()->get(CalculadoraSaldo::class);
        self::assertSame(
            -62,
            $calc->saldoExigivel($this->em()->find(CasoCobranca::class, $casoId)),
            'os 62 centavos a mais abatem o saldo do caso',
        );
    }

    #[TestDox('🔑 Recebimento SEM principal é contado e some no resultado — é o aviso da spec §9.1')]
    public function testRecebimentoSemPrincipalEhContadoNoResultado(): void
    {
        $client = static::createClient();
        [, $tenant] = $this->criarAdminLogado($client);
        [$carteira] = $this->semearGrafo($tenant);
        $carteiraId = (int) $carteira->getId();
        $usuario = $this->em()->getRepository(\App\Entity\Auth\User::class)->findAll()[0];

        // O contador do Estado é o ÚNICO produto da correção do achado B1 — é dele que sai o aviso
        // que o comando imprime antes de gravar. Nenhum cenário desta classe usava `divida: 0`, então
        // prévia e confirmação comparavam `[]` com `[]`: apagar as linhas que alimentam o campo
        // mantinha a suíte verde. Aqui elas passam a ser exercitadas.
        $leitura = $this->leitura([
            // Só honorário: sem principal E sem encargo — o pior caso, exigível zero.
            $this->receita('CHACARA 95', 'Fulano', '8040', '05/2026', '15/05/2026', divida: 0, honorarios: 5000),
            // Sem principal, mas com juros: a obrigação criada tem exigível positivo.
            $this->receita('CHACARA 96', 'Beltrano', '8041', '05/2026', '15/05/2026', divida: 0, juros: 1200),
            // O caso normal, que NÃO pode ser marcado.
            $this->receita('CHACARA 97', 'Cicrano', '8042', '05/2026', '15/05/2026', divida: 30000),
        ]);

        $sut = static::getContainer()->get(ImportarReceitasUseCase::class);
        $previa = $sut->prever($carteiraId, $leitura, $tenant);
        $confirmacao = $sut->confirmar($carteiraId, $leitura, $tenant, $usuario);

        self::assertSame(['8040', '8041'], $previa->semPrincipal, 'os dois sem principal, e só eles');
        self::assertSame(6200, $previa->semPrincipalCentavos, '50,00 de honorário + 12,00 de juros');
        self::assertSame($this->achatar($previa), $this->achatar($confirmacao), 'e a confirmação conta igual');

        // O efeito que o aviso descreve: a obrigação nasce valendo R$ 0,00.
        $this->em()->clear();
        $soHonorario = $this->em()->getRepository(Obrigacao::class)->findOneBy(['referenciaExterna' => '8040']);
        self::assertNotNull($soHonorario);
        self::assertSame(0, $soHonorario->getValorOriginal());
        self::assertSame(0, $soHonorario->valorExigivel(), 'exigível zero: a alocação que a acompanha vale R$ 0,00');
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
        // A guarda do MECANISMO, não dos campos: enquanto `achatar()` derivar por reflexão, este assert
        // é verdadeiro por construção — e é isso que ele protege. Se alguém voltar a escrever a lista de
        // campos à mão, o primeiro esquecido derruba este teste. Foi assim que a cobertura se perdeu da
        // primeira vez (13/13/18 escritos, 16 comparados), e é a única forma de ela se perder de novo.
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
