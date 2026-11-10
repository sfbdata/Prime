<?php

declare(strict_types=1);

namespace App\Tests\Ponto\Integration;

use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Ponto\Entity\ComprovanteDoPonto;
use App\Ponto\Entity\JustificativaPonto;
use App\Ponto\Entity\RegistroPonto;
use App\Ponto\Service\NumeroSequencialDoRegistro;
use App\Ponto\Service\ChavesDoHmacDoPonto;
use App\Ponto\Service\SeloDoComprovanteHmac;
use App\Ponto\UseCase\RegistrarBatidaDaPessoaUseCase;
use App\Ponto\UseCase\RegistrarBatidaDoEsquecimentoUseCase;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Exception\DriverException;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * O NSR da batida (E15, D2): sequencial por escritório, sem buraco e sem repetir; escritórios independentes; o
 * rollback da batida devolve o número; duas transações ao mesmo tempo saem uma depois da outra. E a gravação da batida
 * da pessoa: NSR sempre (também a enviada sem internet), comprovante só com o selo pronto; a do abono de esquecimento
 * fica sem NSR.
 */
#[Group('ponto')]
#[CoversClass(NumeroSequencialDoRegistro::class)]
#[CoversClass(RegistrarBatidaDaPessoaUseCase::class)]
final class NumeroSequencialDoRegistroTest extends KernelTestCase
{
    private const SEGREDO = 'segredo-de-teste-do-comprovante';

    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
    }

    #[TestDox('Sequencial e sem buraco por escritório; outro escritório tem a própria sequência, começando em 1')]
    public function testSequencialPorEscritorio(): void
    {
        [$a, $b] = [$this->escritorio(), $this->escritorio()];

        self::assertSame([1, 2, 3], [$this->proximo($a), $this->proximo($a), $this->proximo($a)]);
        self::assertSame([1, 2], [$this->proximo($b), $this->proximo($b)], 'o escritório B não continua a do A');
        self::assertSame(4, $this->proximo($a), 'o A segue de onde parou, sem pular o que o B usou');
    }

    #[TestDox('Rollback da transação da batida devolve o número: a próxima recebe o mesmo NSR, sem buraco')]
    public function testRollbackNaoDeixaBuraco(): void
    {
        $escritorio = $this->escritorio();
        self::assertSame(1, $this->proximo($escritorio));

        $conexao = $this->em->getConnection();
        $conexao->beginTransaction();
        self::assertSame(2, $this->servico()->proximo($escritorio));
        $conexao->rollBack();

        self::assertSame(2, $this->proximo($escritorio), 'o 2 voltou com o rollback');
        self::assertSame(3, $this->proximo($escritorio));
    }

    /**
     * Duas conexões de verdade, fora do DAMA, como dois workers do PHP-FPM: o que o teste grava pela conexão do DAMA
     * nunca é confirmado e uma conexão nova não o enxerga, então o escritório daqui é confirmado de fato e apagado no
     * fim. Duas conexões num processo só, e não dois processos, porque não dependem de dormir para combinar a ordem.
     * O PDO é síncrono: a conexão B travada pararia o teste junto. O `lock_timeout` transforma a espera em algo que se
     * vê: o erro 55P03 (lock_not_available) só sai quando B ficou de fato esperando a linha que A segurava.
     */
    #[TestDox('Duas transações ao mesmo tempo no mesmo escritório: a segunda espera a primeira; depois recebe o número seguinte (commit) ou o mesmo (rollback), sem repetir nem pular')]
    public function testDuasTransacoesAoMesmoTempo(): void
    {
        [$a, $b] = [$this->conexaoDeVerdade(), $this->conexaoDeVerdade()];
        $escritorioId = null;

        try {   // tudo dentro: o finally limpa o que chegou a ser confirmado, falhe onde falhar
            $escritorioId = (int) $a->fetchOne(
                'INSERT INTO tenant (name, created_at, is_active) VALUES (:nome, NOW(), true) RETURNING id',
                ['nome' => 'Escritório NSR concorrente ' . uniqid()],
            );
            $escritorio = $this->em->getReference(Tenant::class, $escritorioId);
            [$servicoA, $servicoB] = [$this->servicoNa($a), $this->servicoNa($b)];

            $a->beginTransaction();
            self::assertSame(1, $servicoA->proximo($escritorio), 'A reserva o 1 e segura a linha do contador');
            $this->assertEsperaATrava($b, $servicoB, $escritorio);
            $a->commit();
            self::assertSame(2, $b->transactional(fn (): int => $servicoB->proximo($escritorio)), 'depois do commit de A, o 2: nem repete o 1, nem pula');

            $a->beginTransaction();
            self::assertSame(3, $servicoA->proximo($escritorio));
            $this->assertEsperaATrava($b, $servicoB, $escritorio);
            $a->rollBack();
            self::assertSame(3, $b->transactional(fn (): int => $servicoB->proximo($escritorio)), 'A desistiu: o 3 voltou e vai para B, sem buraco');

            self::assertSame(3, (int) $a->fetchOne('SELECT ultimo FROM ponto_contador_nsr WHERE tenant_id = ?', [$escritorioId]));
        } finally {
            foreach ([$a, $b] as $conexao) {
                if ($conexao->isTransactionActive()) {
                    $conexao->rollBack();
                }
            }
            if ($escritorioId !== null) {
                $a->executeStatement('DELETE FROM ponto_contador_nsr WHERE tenant_id = ?', [$escritorioId]);
                $a->executeStatement('DELETE FROM tenant WHERE id = ?', [$escritorioId]);
            }
            $a->close();
            $b->close();
        }
    }

    #[TestDox('O mesmo envio que perde a corrida (E13, índice único): a transação desfaz o NSR e a próxima batida recebe o número seguinte, sem buraco')]
    public function testEnvioRepetidoNaCorridaNaoGastaNsr(): void
    {
        $registrar = static::getContainer()->get(RegistrarBatidaDaPessoaUseCase::class);
        $escritorio = $this->escritorio();
        $escritorioId = $escritorio->getId();
        $primeira = $this->batida($escritorio);
        $primeira->setIdEnvio('7c9e6679-7425-40de-944b-e07fc1f90ae7');
        $registrar->executar($primeira);
        self::assertSame(1, $primeira->getNsr());

        $repetida = $this->batida($escritorio, $primeira->getUser());
        $repetida->setIdEnvio('7c9e6679-7425-40de-944b-e07fc1f90ae7');
        try {
            $registrar->executar($repetida);
            self::fail('o índice único barra o mesmo envio da mesma pessoa');
        } catch (UniqueConstraintViolationException $violacao) {
            self::assertStringContainsString(RegistroPonto::INDICE_DO_ENVIO, $violacao->getMessage());
        }

        static::getContainer()->get('doctrine')->resetManager();   // a violação fecha o EntityManager, como na batida (E13)
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        self::assertSame(1, $this->ultimoNsr($escritorioId), 'o 2 reservado pela repetida voltou no rollback');
        $proxima = $this->batida($this->em->find(Tenant::class, $escritorioId));
        static::getContainer()->get(RegistrarBatidaDaPessoaUseCase::class)->executar($proxima);
        self::assertSame(2, $proxima->getNsr());
    }

    #[TestDox('A batida enviada sem internet recebe NSR como as outras, e o dado selado guarda o "sem internet"')]
    public function testEnviadaSemInternetRecebeNsr(): void
    {
        $this->seloPronto();
        $escritorio = $this->escritorio();
        $batida = $this->batida($escritorio);
        $batida->setEnviadaSemInternet(true);

        $comprovante = static::getContainer()->get(RegistrarBatidaDaPessoaUseCase::class)->executar($batida);

        self::assertSame(1, $batida->getNsr());
        self::assertNotNull($comprovante);
        self::assertStringContainsString('"sem_internet":true', $comprovante->getDadosAssinados());
    }

    #[TestDox('O abono de esquecimento (RegistrarBatidaDoEsquecimentoUseCase) vira inclusão de ajuste (E10): nenhuma batida em registro_ponto, nenhum NSR, o contador nem nasce')]
    public function testBatidaDoEsquecimentoSemNsr(): void
    {
        $escritorio = $this->escritorio();
        $justificativa = new JustificativaPonto();
        $pessoa = $this->pessoa();
        $justificativa->setUser($pessoa);
        $this->em->flush();   // a guarda do dia (R1) consulta pela pessoa: ela precisa de id
        $justificativa->setTenant($escritorio);
        $justificativa->setData(new \DateTime('2026-03-10'));
        $justificativa->setStatus('abonado');
        $justificativa->setTipo('esquecimento_registro');
        $justificativa->setTipoRegistroEsquecido('saida');
        $justificativa->setHoraRegistroEsquecido(new \DateTime('1970-01-01 18:00:00'));
        $this->em->persist($justificativa);   // a inclusão aponta para ela
        $this->em->flush();

        $inclusao = static::getContainer()->get(RegistrarBatidaDoEsquecimentoUseCase::class)($justificativa, $escritorio, $this->pessoa());
        $this->em->flush();

        self::assertNotNull($inclusao?->getId(), 'a inclusão do esquecimento foi gravada');
        self::assertSame(0, (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM registro_ponto WHERE user_id = ?', [$pessoa->getId()]), 'registro_ponto guarda só o que a pessoa bateu');
        self::assertFalse($this->em->getConnection()->fetchOne('SELECT ultimo FROM ponto_contador_nsr WHERE tenant_id = ?', [$escritorio->getId()]), 'o contador nem nasceu');
    }

    #[TestDox('Sem o segredo do HMAC: a batida grava com NSR e nenhum comprovante é emitido')]
    public function testSeloIndisponivelGravaSoComNsr(): void
    {
        static::getContainer()->set(ChavesDoHmacDoPonto::class, new ChavesDoHmacDoPonto(null));
        $escritorio = $this->escritorio();
        $registrar = static::getContainer()->get(RegistrarBatidaDaPessoaUseCase::class);

        self::assertNull($registrar->executar($this->batida($escritorio)));
        $segunda = $this->batida($escritorio);
        self::assertNull($registrar->executar($segunda));

        self::assertSame(2, $segunda->getNsr());
        self::assertNotNull($segunda->getId(), 'a batida foi gravada');
        self::assertSame(0, $this->em->getRepository(ComprovanteDoPonto::class)->count([]), 'nada emitido');
    }

    #[TestDox('Selo pronto: na mesma transação, NSR, batida e comprovante com código, o HMAC da chave "ponto-comprovante" na versão 1 e o NSR selado')]
    public function testSeloProntoEmiteComprovante(): void
    {
        $this->seloPronto();
        $escritorio = $this->escritorio();
        $batida = $this->batida($escritorio);

        $comprovante = static::getContainer()->get(RegistrarBatidaDaPessoaUseCase::class)->executar($batida);

        self::assertNotNull($comprovante);
        self::assertNotNull($comprovante->getId());
        self::assertSame(1, $batida->getNsr());
        self::assertSame(1, $comprovante->getNsr());
        self::assertSame($batida, $comprovante->getRegistro());
        self::assertSame(1, $comprovante->getVersaoChave(), 'a versão atual da chave fica gravada');
        $chave = hash_hkdf('sha256', self::SEGREDO, 32, SeloDoComprovanteHmac::FINALIDADE);
        self::assertSame(hash_hmac('sha256', $comprovante->getDadosAssinados(), $chave), $comprovante->getHmac());
        self::assertSame($batida->getUser(), $comprovante->getUser(), 'a pessoa da batida fica na linha');
        self::assertStringContainsString('"nsr":1,"escritorio_id":' . $escritorio->getId() . ',"nome":"Pessoa do NSR"', $comprovante->getDadosAssinados());
        self::assertStringContainsString('"codigo":"' . $comprovante->getCodigo() . '"', $comprovante->getDadosAssinados());
    }

    private function proximo(Tenant $escritorio): int
    {
        return $this->em->wrapInTransaction(fn (): int => $this->servico()->proximo($escritorio));
    }

    /** O segredo do Ponto configurado (as chaves da E9): o selo do comprovante fica pronto. */
    private function seloPronto(): void
    {
        static::getContainer()->set(ChavesDoHmacDoPonto::class, new ChavesDoHmacDoPonto(self::SEGREDO));
    }

    private function servico(): NumeroSequencialDoRegistro
    {
        return static::getContainer()->get(NumeroSequencialDoRegistro::class);
    }

    /** B pede o próximo enquanto A segura a linha: espera a trava até o `lock_timeout` e desiste com 55P03. */
    private function assertEsperaATrava(Connection $b, NumeroSequencialDoRegistro $servicoB, Tenant $escritorio): void
    {
        $b->beginTransaction();
        $b->executeStatement("SET LOCAL lock_timeout = '300ms'");
        $inicio = hrtime(true);
        try {
            $servicoB->proximo($escritorio);
            self::fail('B não pode receber número com a linha do contador travada por A');
        } catch (DriverException $espera) {
            self::assertSame('55P03', $espera->getSQLState(), 'lock_not_available: B esperou a trava de A');
            self::assertGreaterThanOrEqual(250.0, (hrtime(true) - $inicio) / 1e6, 'B ficou parado esperando');
        } finally {
            $b->rollBack();
        }
    }

    /** Conexão nova ao banco do teste, sem o DAMA: o que ela grava é confirmado de fato. */
    private function conexaoDeVerdade(): Connection
    {
        $doTeste = $this->em->getConnection();
        $parametros = array_intersect_key($doTeste->getParams(), array_flip(['driver', 'host', 'port', 'dbname', 'user', 'password', 'charset', 'serverVersion']));
        $conexao = DriverManager::getConnection($parametros);
        self::assertSame($doTeste->fetchOne('SELECT current_database()'), $conexao->fetchOne('SELECT current_database()'), 'o mesmo banco do teste');

        return $conexao;
    }

    /** O serviço de produção, com um EntityManager na conexão dada (o NSR só usa a conexão dele). */
    private function servicoNa(Connection $conexao): NumeroSequencialDoRegistro
    {
        return new NumeroSequencialDoRegistro(new EntityManager($conexao, $this->em->getConfiguration()));
    }

    private function ultimoNsr(?int $escritorioId): int|false
    {
        $ultimo = $this->em->getConnection()->fetchOne('SELECT ultimo FROM ponto_contador_nsr WHERE tenant_id = ?', [$escritorioId]);

        return $ultimo === false ? false : (int) $ultimo;
    }

    private function escritorio(): Tenant
    {
        $tenant = new Tenant();
        $tenant->setName('Escritório NSR ' . uniqid());
        $this->em->persist($tenant);
        $this->em->flush();

        return $tenant;
    }

    private function pessoa(): User
    {
        $pessoa = new User();
        $pessoa->setEmail('nsr_' . uniqid() . '@test.com');
        $pessoa->setFullName('Pessoa do NSR');
        $pessoa->setRoles(['ROLE_USER']);
        $pessoa->setIsActive(true);
        $pessoa->setPassword('x');
        $this->em->persist($pessoa);

        return $pessoa;
    }

    private function batida(?Tenant $escritorio, ?User $pessoa = null): RegistroPonto
    {
        $batida = new RegistroPonto();
        $batida->setUser($pessoa ?? $this->pessoa());
        $batida->setTenant($escritorio);
        $batida->setTipo(RegistroPonto::TIPO_ENTRADA);
        $batida->setDataHora(new \DateTime());

        return $batida;
    }
}
