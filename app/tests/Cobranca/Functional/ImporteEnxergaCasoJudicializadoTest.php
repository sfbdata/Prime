<?php

declare(strict_types=1);

namespace App\Tests\Cobranca\Functional;

use App\Cobranca\DTO\AbrirCasoInput;
use App\Cobranca\Entity\Acordo;
use App\Cobranca\Entity\Carteira;
use App\Cobranca\Entity\CasoCobranca;
use App\Cobranca\Entity\EventoHistorico;
use App\Cobranca\Entity\ObjetoCobranca;
use App\Cobranca\Entity\Obrigacao;
use App\Cobranca\Entity\Pessoa;
use App\Cobranca\Entity\VinculoPessoaObjeto;
use App\Cobranca\Enum\ModoCarteira;
use App\Cobranca\Enum\StatusCaso;
use App\Cobranca\Exception\CasoCobravelJaExisteException;
use App\Cobranca\Repository\AcordoRepository;
use App\Cobranca\Repository\CasoCobrancaRepository;
use App\Cobranca\Service\CalculadoraEncargos;
use App\Cobranca\Service\ConversorTaxaEncargo;
use App\Cobranca\Service\Importacao\BoletoImportavel;
use App\Cobranca\Service\Importacao\ResultadoImportacao;
use App\Cobranca\Service\Importacao\ResultadoLeitura;
use App\Cobranca\Service\RegistrarEventoHistorico;
use App\Cobranca\Service\ResolvedorConfigEncargos;
use App\Cobranca\Service\ResolvedorPessoaNoObjeto;
use App\Cobranca\UseCase\AbrirCasoUseCase;
use App\Cobranca\UseCase\CriarObjetoUseCase;
use App\Cobranca\UseCase\CriarPessoaUseCase;
use App\Cobranca\UseCase\ImportarRelatorioCarteiraUseCase;
use App\Cobranca\UseCase\RegistrarObrigacaoUseCase;
use App\Cobranca\UseCase\VincularPessoaAObjetoUseCase;
use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Tests\Factory\Cliente\ClientePFFactory;
use App\Tests\Factory\Cobranca\CarteiraFactory;
use App\Tests\Factory\Cobranca\CasoCobrancaFactory;
use App\Tests\Factory\Cobranca\ObjetoCobrancaFactory;
use App\Tests\Factory\Cobranca\PessoaFactory;
use App\Tests\Factory\Cobranca\VinculoPessoaObjetoFactory;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Zenstruck\Foundry\Test\Factories;

/**
 * Spec: `docs/specs/cobranca-importe-enxerga-caso-judicializado.md`.
 *
 * Cenário medido em PRODUÇÃO em 03/09/2026: o escritório judicializou 54 casos da TOP LIFE I entre
 * 01/09 e 03/09. Como os importadores resolviam o caso por `status = ativo`, o caso judicializado
 * ficava invisível, o importe concluía "unidade sem cobrança" e ABRIA UM SEGUNDO CASO — recriando
 * ali 2.609 obrigações que já existiam do outro lado. A mesma dívida duas vezes, R$ 390.370,46 de
 * principal vencido.
 *
 * A régua correta é `!= encerrado`, não `= ativo`: SPEC §16 diz que judicializar NÃO encerra a
 * cobrança, e §17 reserva a proibição de receber obrigação nova só ao `encerrado`. As 18 guardas de
 * mutação do domínio já barravam apenas `estaEncerrado()`; o importe era a única peça fora do tom.
 *
 * ⚠️ Antes desta frente NENHUM teste de importação criava caso `Judicializado` — nos 11 arquivos de
 * teste de importe as únicas manipulações de status usavam `Encerrado`. A suíte ficava verde com a
 * regra certa E com a errada. É esse buraco que este arquivo fecha.
 */
#[CoversClass(ImportarRelatorioCarteiraUseCase::class)]
#[CoversClass(CasoCobrancaRepository::class)]
#[CoversClass(AbrirCasoUseCase::class)]
final class ImporteEnxergaCasoJudicializadoTest extends KernelTestCase
{
    use Factories;

    private EntityManagerInterface $em;
    private ImportarRelatorioCarteiraUseCase $importar;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);

        $carteiraRepo = $this->em->getRepository(Carteira::class);
        $objetoRepo = $this->em->getRepository(ObjetoCobranca::class);
        $casoRepo = $this->em->getRepository(CasoCobranca::class);
        $obrigacaoRepo = $this->em->getRepository(Obrigacao::class);
        $pessoaRepo = $this->em->getRepository(Pessoa::class);
        $vinculoRepo = $this->em->getRepository(VinculoPessoaObjeto::class);
        $eventoRepo = $this->em->getRepository(EventoHistorico::class);
        /** @var AcordoRepository $acordoRepo */
        $acordoRepo = $this->em->getRepository(Acordo::class);

        $registrarEvento = new RegistrarEventoHistorico($eventoRepo);
        $this->importar = new ImportarRelatorioCarteiraUseCase(
            $carteiraRepo,
            $objetoRepo,
            $casoRepo,
            $obrigacaoRepo,
            $vinculoRepo,
            $acordoRepo,
            new CriarObjetoUseCase($objetoRepo, $carteiraRepo),
            new CriarPessoaUseCase($pessoaRepo),
            new VincularPessoaAObjetoUseCase($vinculoRepo, $pessoaRepo, $objetoRepo),
            new AbrirCasoUseCase($casoRepo, $objetoRepo, $pessoaRepo, $registrarEvento),
            new RegistrarObrigacaoUseCase($obrigacaoRepo, $casoRepo, $registrarEvento, new CalculadoraEncargos(), new ResolvedorConfigEncargos(), new ConversorTaxaEncargo(new CalculadoraEncargos())),
            $this->em,
            new ResolvedorPessoaNoObjeto($vinculoRepo),
        );
    }

    // ───────────────────────── T1: o defeito de produção, invertido ─────────────────────────

    #[TestDox('T1 · Unidade JUDICIALIZADA: o importe ATUALIZA a dívida existente e NÃO abre caso novo')]
    public function testUnidadeJudicializadaAtualizaEmVezDeDuplicar(): void
    {
        $tenant = $this->criarTenant();
        $user = $this->criarUser();
        $carteiraId = $this->criarCarteira($tenant);

        // Exatamente o estado de produção: caso judicializado, sem nenhum ativo, com a dívida já dentro.
        $caso = $this->criarUnidadeComCaso($tenant, $carteiraId, '01-01', 'ANTONIO JOSE PORTELA DE SOUZA', StatusCaso::Judicializado);
        $this->criarObrigacaoExistente($tenant, $caso, nn: '74608', competencia: '02/2026');

        $resultado = $this->importar->confirmar(
            $carteiraId,
            new ResultadoLeitura([$this->boleto('01-01', 'ANTONIO JOSE PORTELA DE SOUZA', nn: '74608')], [], 0),
            $tenant,
            $user,
        );

        self::assertSame(0, $resultado->casosCriados, 'o caso judicializado É a cobrança da unidade — não se abre outro');
        self::assertCount(0, $resultado->obrigacoesCriadas, 'a dívida já existe; recriá-la é contá-la duas vezes');
        self::assertCount(1, $resultado->obrigacoesAtualizadas, 'ela tem de ser ATUALIZADA');

        // A prova que mais importa: continua havendo UM caso e UMA obrigação no banco.
        $objeto = $this->objetoPorIdentificacao($tenant, '01-01');
        self::assertCount(1, $this->em->getRepository(CasoCobranca::class)->findBy(['objeto' => $objeto]), 'um caso, não dois');
        self::assertSame(1, $this->em->getRepository(Obrigacao::class)->count(['caso' => $caso]), 'uma dívida, não duas');

        // E ela continua pendurada no caso JUDICIALIZADO, não migrou para lugar nenhum.
        $obrigacao = $this->em->getRepository(Obrigacao::class)->findOneBy(['caso' => $caso]);
        self::assertSame(StatusCaso::Judicializado, $obrigacao?->getCaso()?->getStatus());
    }

    #[TestDox('T2 · Paridade prévia×confirmação na unidade judicializada')]
    public function testPreviaEConfirmacaoConcordamNaUnidadeJudicializada(): void
    {
        $tenant = $this->criarTenant();
        $user = $this->criarUser();
        $carteiraId = $this->criarCarteira($tenant);

        $caso = $this->criarUnidadeComCaso($tenant, $carteiraId, '02-02', 'SALVADOR PAULO DE OLIVEIRA', StatusCaso::Judicializado);
        $this->criarObrigacaoExistente($tenant, $caso, nn: '75216', competencia: '03/2026');
        $leitura = new ResultadoLeitura([$this->boleto('02-02', 'SALVADOR PAULO DE OLIVEIRA', nn: '75216')], [], 0);

        // A prévia é o número que o operador lê para decidir sobre dinheiro. Se ela mentir, a decisão
        // é tomada errada — e esta paridade já foi quebrada duas vezes nesta frente (§3.3 da spec).
        $previa = $this->importar->prever($carteiraId, $leitura, $tenant);
        $confirmado = $this->importar->confirmar($carteiraId, $leitura, $tenant, $user);

        self::assertSame($previa->casosCriados, $confirmado->casosCriados, 'a prévia não pode prometer caso que a confirmação não abre');
        self::assertSame(0, $previa->casosCriados, 'e o número certo é ZERO nos dois');
        self::assertCount(0, $previa->obrigacoesCriadas, 'idem para as obrigações criadas');
        self::assertSame(count($previa->obrigacoesCriadas), count($confirmado->obrigacoesCriadas));
        self::assertSame(count($previa->obrigacoesAtualizadas), count($confirmado->obrigacoesAtualizadas));

        // E TODOS os demais campos, por reflexão — não uma amostra escolhida à mão. A 1ª revisão pegou
        // esta lista escrita com 9 dos 11 campos: faltavam `referenciasReutilizadas` e
        // `vencimentosAlterados`, que são populados nos dois modos e portanto podem divergir de
        // verdade. Quem escreve o assert escolhe o que olhar, e escolhe mal — este é o importador que
        // move 2.609 linhas.
        self::assertSame($this->achatar($previa), $this->achatar($confirmado), 'a prévia tem de projetar EXATAMENTE o que a confirmação faz, em todos os campos');
    }

    // ───────────────────────── T6: a régua §17 não pode afrouxar ─────────────────────────

    #[TestDox('T6 · Caso ENCERRADO continua fora: o importe abre caso novo, como manda a SPEC §17')]
    public function testCasoEncerradoContinuaForaEAbreCasoNovo(): void
    {
        $tenant = $this->criarTenant();
        $user = $this->criarUser();
        $carteiraId = $this->criarCarteira($tenant);

        // §17: "depois de encerrado […] o caso não recebe novas obrigações; uma nova inadimplência
        // pode gerar um novo caso para o mesmo objeto". Alargar para `!= encerrado` não pode virar
        // "aceita tudo" — é este teste que impede a mudança de passar do ponto.
        $encerrado = $this->criarUnidadeComCaso($tenant, $carteiraId, '09-01A', 'MARIA GENIRA DE ARAUJO GUEDES', StatusCaso::Encerrado);

        $resultado = $this->importar->confirmar(
            $carteiraId,
            new ResultadoLeitura([$this->boleto('09-01A', 'MARIA GENIRA DE ARAUJO GUEDES', nn: '80001')], [], 0),
            $tenant,
            $user,
        );

        self::assertSame(1, $resultado->casosCriados, 'caso encerrado NÃO recebe dívida nova: nasce outro caso');
        self::assertCount(1, $resultado->obrigacoesCriadas);
        self::assertSame(0, $this->em->getRepository(Obrigacao::class)->count(['caso' => $encerrado]), 'o encerrado fica intacto');
    }

    // ───────────────────────── T8: qual caso, quando há mais de um ─────────────────────────

    #[TestDox('T8 · O repositório devolve ATIVO antes de judicializado, e nunca o encerrado')]
    public function testOrdemDeterministaDosCasosCobraveis(): void
    {
        $tenant = $this->criarTenant();
        $carteiraId = $this->criarCarteira($tenant);

        // `[0]` sem ORDER BY é loteria. Com um só ativo isso nunca mordeu; ampliando o conjunto para
        // ativo+judicializado, passa a morder — e a tela (`casoAncoraDoObjeto`) já prefere o ativo.
        $objeto = $this->criarObjeto($tenant, $carteiraId, '19-03B');
        $pessoa = PessoaFactory::createOne(['tenant' => $tenant, 'nome' => 'JOSÉ DUALCEI BESERRA CAVALCANTE'])->_real();

        // ⚠️ A ORDEM DE CRIAÇÃO aqui é o próprio teste: o ativo nasce PRIMEIRO (menor id, mais antigo)
        // e o judicializado DEPOIS. Assim `criadoEm DESC, id DESC` sozinho devolveria o judicializado
        // na frente, e só a prioridade por status coloca o ativo em primeiro. Criar na ordem inversa
        // deixa o teste verde mesmo sem a prioridade — foi o que a 1ª prova por reintrodução pegou.
        $this->criarCasoNoObjeto($tenant, $objeto, $pessoa, StatusCaso::Encerrado);
        $ativo = $this->criarCasoNoObjeto($tenant, $objeto, $pessoa, StatusCaso::Ativo);
        $judicializado = $this->criarCasoNoObjeto($tenant, $objeto, $pessoa, StatusCaso::Judicializado);

        /** @var CasoCobrancaRepository $repo */
        $repo = $this->em->getRepository(CasoCobranca::class);
        $cobraveis = $repo->casosCobraveisDoObjeto($objeto);

        self::assertCount(2, $cobraveis, 'o encerrado nunca entra');
        self::assertSame($ativo->getId(), $cobraveis[0]->getId(), 'o ativo vem primeiro — a mesma prioridade da tela');
        self::assertSame($judicializado->getId(), $cobraveis[1]->getId());
    }

    #[TestDox('T8b · Só judicializado: ele É o caso cobrável da unidade')]
    public function testSoJudicializadoEhCobravel(): void
    {
        $tenant = $this->criarTenant();
        $carteiraId = $this->criarCarteira($tenant);

        $objeto = $this->criarObjeto($tenant, $carteiraId, '16-01');
        $pessoa = PessoaFactory::createOne(['tenant' => $tenant, 'nome' => 'LUCAS JUNIO SILVA MAGALHAES'])->_real();
        $judicializado = $this->criarCasoNoObjeto($tenant, $objeto, $pessoa, StatusCaso::Judicializado);

        /** @var CasoCobrancaRepository $repo */
        $repo = $this->em->getRepository(CasoCobranca::class);

        self::assertSame([$judicializado->getId()], array_map(static fn (CasoCobranca $c): ?int => $c->getId(), $repo->casosCobraveisDoObjeto($objeto)));
        self::assertTrue($repo->existeCasoCobravelParaObjeto($objeto), 'é ele que impede o segundo caso de nascer');
    }

    // ───────────────────── T7: o segundo furo — a guarda de quem abre caso ─────────────────────

    #[TestDox('T7 · A guarda do AbrirCaso recusa um segundo caso quando já existe um JUDICIALIZADO')]
    public function testGuardaRecusaSegundoCasoQuandoExisteJudicializado(): void
    {
        $tenant = $this->criarTenant();
        $user = $this->criarUser();
        $carteiraId = $this->criarCarteira($tenant);

        // Corrigir só os importadores não bastava: enquanto a guarda contasse apenas o `ativo`, este
        // objeto respondia "não tem caso" e ganhava um segundo por QUALQUER outro caminho de escrita
        // (tela, criação de objeto com cobrança, importe futuro). É o furo nº 2 da spec §3.3.
        $caso = $this->criarUnidadeComCaso($tenant, $carteiraId, '12-01B', 'LINDOMAR PEREIRA DA SILVA', StatusCaso::Judicializado);
        $objeto = $caso->getObjeto();

        $abrirCaso = new AbrirCasoUseCase(
            $this->em->getRepository(CasoCobranca::class),
            $this->em->getRepository(ObjetoCobranca::class),
            $this->em->getRepository(Pessoa::class),
            new RegistrarEventoHistorico($this->em->getRepository(EventoHistorico::class)),
        );

        $input = new AbrirCasoInput();
        $input->objetoId = (int) $objeto?->getId();
        $input->pessoaCobradaId = (int) $caso->getPessoaCobradaAtual()?->getId();

        $this->expectException(CasoCobravelJaExisteException::class);

        try {
            $abrirCaso->executar($input, $tenant, $user);
        } finally {
            self::assertCount(
                1,
                $this->em->getRepository(CasoCobranca::class)->findBy(['objeto' => $objeto]),
                'o objeto continua com UM caso',
            );
        }
    }

    // ───────────────────────── T9: o isolamento entre escritórios ─────────────────────────

    /**
     * ⚠️ O que este teste NÃO prova (medido por reintrodução, 03/09): apagando o `c.tenant = :tenant`
     * de `casosCobraveisDoObjeto` ele continua VERDE. E isso não é falha dele — é a natureza da
     * consulta: ela casa por `c.objeto = :objeto`, o objeto já é tenant-bound, e os objetos dos dois
     * escritórios são linhas diferentes. O filtro de tenant ali é **defesa em profundidade**.
     *
     * 🔴 E o cenário aqui também NÃO isola o filtro de tenant do `findOnePorIdentificacaoNaCarteira`:
     * as duas carteiras são diferentes, então o `carteira` da consulta já separa sozinho. Quem de fato
     * barra o vazamento neste caminho é o `resolverCarteira($carteiraId, $tenant)`, que recusa a
     * carteira de outro escritório antes de qualquer coisa.
     *
     * O que este teste prova, então, é o COMPORTAMENTO: o importe do escritório B não reaproveita nem
     * o caso judicializado nem a dívida do escritório A, e o A sai intacto. Fica escrito para ninguém
     * ler o nome do teste e concluir cobertura de filtro que não existe.
     */
    #[TestDox('🔒 T9 · Cross-tenant: caso judicializado de OUTRO escritório nunca é reusado')]
    public function testNaoEnxergaCasoJudicializadoDeOutroTenant(): void
    {
        $tenantA = $this->criarTenant();
        $tenantB = $this->criarTenant();
        $user = $this->criarUser();

        // O pior caso para um vazamento: mesma identificação de unidade e mesmo sacado nos dois
        // escritórios, e o do vizinho judicializado — justamente o estado que a mudança passa a enxergar.
        $carteiraA = $this->criarCarteira($tenantA);
        $casoA = $this->criarUnidadeComCaso($tenantA, $carteiraA, '01-01', 'ANTONIO JOSE PORTELA DE SOUZA', StatusCaso::Judicializado);
        $this->criarObrigacaoExistente($tenantA, $casoA, nn: '74608', competencia: '02/2026');

        $carteiraB = $this->criarCarteira($tenantB);
        $resultado = $this->importar->confirmar(
            $carteiraB,
            new ResultadoLeitura([$this->boleto('01-01', 'ANTONIO JOSE PORTELA DE SOUZA', nn: '74608')], [], 0),
            $tenantB,
            $user,
        );

        self::assertSame(1, $resultado->objetosCriados, 'a unidade do outro escritório não é vista');
        self::assertSame(1, $resultado->casosCriados, 'nem o caso judicializado dele');
        self::assertCount(1, $resultado->obrigacoesCriadas, 'a dívida nasce no escritório B, do zero');

        self::assertSame(1, $this->em->getRepository(Obrigacao::class)->count(['caso' => $casoA]), 'o escritório A fica intacto');
        $objetoB = $this->objetoPorIdentificacao($tenantB, '01-01');
        $casoB = $this->em->getRepository(CasoCobranca::class)->findOneBy(['objeto' => $objetoB]);
        self::assertSame($tenantB->getId(), $casoB?->getTenant()?->getId(), 'o caso usado é do próprio tenant');
    }

    // ───────────────────────── helpers ─────────────────────────

    /**
     * Achata o `ResultadoImportacao` em TODOS os campos públicos, por REFLEXÃO — para que um campo novo
     * no DTO entre na comparação sem ninguém lembrar de acrescentá-lo aqui. Mesmo mecanismo do
     * `ImportarReceitasFluxoTest`, pela mesma razão: a paridade prévia×confirmação já mentiu duas vezes
     * nesta frente, e das duas o que a desmascarava era um campo que ninguém comparava.
     *
     * As listas de obrigação viram CONTAGEM: os objetos são instâncias diferentes nos dois caminhos
     * (na prévia nada foi persistido), então comparar identidade não diria nada útil.
     *
     * @return array<string, mixed>
     */
    private function achatar(ResultadoImportacao $r): array
    {
        $achatado = [];
        foreach ((new \ReflectionClass($r))->getProperties(\ReflectionProperty::IS_PUBLIC) as $propriedade) {
            $valor = $propriedade->getValue($r);
            $achatado[$propriedade->getName()] = is_array($valor) ? count($valor) : $valor;
        }
        ksort($achatado);

        return $achatado;
    }

    private function criarUnidadeComCaso(Tenant $tenant, int $carteiraId, string $unidade, string $nome, StatusCaso $status): CasoCobranca
    {
        $objeto = $this->criarObjeto($tenant, $carteiraId, $unidade);
        $pessoa = PessoaFactory::createOne(['tenant' => $tenant, 'nome' => $nome])->_real();
        VinculoPessoaObjetoFactory::createOne(['tenant' => $tenant, 'pessoa' => $pessoa, 'objeto' => $objeto]);

        return $this->criarCasoNoObjeto($tenant, $objeto, $pessoa, $status);
    }

    private function criarObjeto(Tenant $tenant, int $carteiraId, string $unidade): ObjetoCobranca
    {
        return ObjetoCobrancaFactory::createOne([
            'tenant' => $tenant,
            'carteira' => $this->em->getRepository(Carteira::class)->find($carteiraId),
            'identificacao' => $unidade,
        ])->_real();
    }

    private function criarCasoNoObjeto(Tenant $tenant, ObjetoCobranca $objeto, Pessoa $pessoa, StatusCaso $status): CasoCobranca
    {
        return CasoCobrancaFactory::createOne([
            'tenant' => $tenant,
            'objeto' => $objeto,
            'pessoaCobradaAtual' => $pessoa,
            'status' => $status,
        ])->_real();
    }

    /** A dívida como o importe a deixou no lote anterior: chave `(caso, nosso-número, competência)`. */
    private function criarObrigacaoExistente(Tenant $tenant, CasoCobranca $caso, string $nn, string $competencia): Obrigacao
    {
        $obrigacao = new Obrigacao();
        $obrigacao->setTenant($tenant);
        $obrigacao->setCaso($caso);
        $obrigacao->setDescricao('Competência ' . $competencia);
        $obrigacao->setValorOriginal(17000);
        $obrigacao->setVencimentoOriginal(new \DateTimeImmutable('2026-02-10'));
        $obrigacao->setReferenciaExterna($nn);
        $obrigacao->setCompetencia($competencia);
        $this->em->persist($obrigacao);
        $this->em->flush();

        return $obrigacao;
    }

    private function objetoPorIdentificacao(Tenant $tenant, string $unidade): ?ObjetoCobranca
    {
        return $this->em->getRepository(ObjetoCobranca::class)->findOneBy(['tenant' => $tenant, 'identificacao' => $unidade]);
    }

    private function boleto(string $unidade, string $sacado, string $nn): BoletoImportavel
    {
        return new BoletoImportavel(
            nn: $nn,
            objetoIdentificacao: $unidade,
            unidadeMetadata: null,
            sacadoNome: $sacado,
            principalCentavos: 17000,
            jurosCentavos: 0,
            multaCentavos: 0,
            correcaoCentavos: 0,
            honorariosInformadosCentavos: 0,
            vencimento: new \DateTimeImmutable('2026-02-10'),
            competencia: $nn === '74608' ? '02/2026' : ($nn === '75216' ? '03/2026' : '04/2026'),
            acordoTexto: null,
            acordo: null,
            somaColunaValorCentavos: 17000,
            jurosDasColunasCentavos: 0,
            multaDasColunasCentavos: 0,
            honorariosDasColunasCentavos: 0,
            linhas: [],
        );
    }

    private function criarTenant(): Tenant
    {
        $tenant = new Tenant();
        $tenant->setName('Tenant JUD ' . uniqid());
        $this->em->persist($tenant);
        $this->em->flush();

        return $tenant;
    }

    private function criarUser(): User
    {
        $user = new User();
        $user->setEmail('jud_' . uniqid() . '@test.com');
        $user->setFullName('User Importação');
        $user->setIsActive(true);
        $this->em->persist($user);
        $this->em->flush();

        return $user;
    }

    private function criarCarteira(Tenant $tenant): int
    {
        $carteira = CarteiraFactory::createOne([
            'tenant' => $tenant,
            'cliente' => ClientePFFactory::createOne(['tenant' => $tenant]),
            'modo' => ModoCarteira::Unico,
        ]);

        return (int) $carteira->getId();
    }
}
