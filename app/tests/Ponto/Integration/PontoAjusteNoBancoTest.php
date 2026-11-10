<?php

declare(strict_types=1);

namespace App\Tests\Ponto\Integration;

use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Ponto\Entity\PontoAjuste;
use App\Ponto\Entity\RegistroPonto;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\DriverException;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * A forma de `ponto_ajuste` garantida pelo banco (E10, migration Version20261109280000): os CHECKs de tipo, marcação,
 * pares, motivo e forma de cada tipo, e os índices únicos parciais que não deixam a linhagem bifurcar. As linhas entram
 * por SQL cru, sem passar pela entidade, para provar que o banco recusa sozinho.
 *
 * Valores com '@' são trocados pelo id da linha de apoio: '@registro' (a batida gravada), '@inclusao' (uma inclusão),
 * '@desconsideracao' (a desconsideração da batida).
 */
#[CoversClass(PontoAjuste::class)]
final class PontoAjusteNoBancoTest extends KernelTestCase
{
    private const ANTERIOR = ['tipo_marcacao_anterior' => 'entrada', 'data_hora_anterior' => '2026-10-21 08:00:00'];
    private const NOVA = ['tipo_marcacao_nova' => 'entrada', 'data_hora_nova' => '2026-10-21 08:10:00'];

    private Connection $conexao;
    private int $tenantId;
    private int $pessoaId;
    /** @var array<string, int> */
    private array $apoio = [];

    protected function setUp(): void
    {
        self::bootKernel();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $this->conexao = $em->getConnection();

        $escritorio = (new Tenant())->setName('Escritório dos CHECKs ' . uniqid());
        $pessoa = (new User())->setEmail('checks_' . uniqid() . '@test.com')->setFullName('Pessoa dos CHECKs');
        $pessoa->setRoles(['ROLE_USER']);
        $pessoa->setPassword('x');
        $batida = (new RegistroPonto())->setTenant($escritorio)->setUser($pessoa)->setTipo('entrada')->setDataHora(new \DateTime('2026-10-21 08:00:00'));
        $em->persist($escritorio);
        $em->persist($pessoa);
        $em->persist($batida);
        $em->flush();

        $this->tenantId = (int) $escritorio->getId();
        $this->pessoaId = (int) $pessoa->getId();
        $this->apoio['@registro'] = (int) $batida->getId();
        $this->apoio['@inclusao'] = $this->inserir(['tipo' => 'inclusao'] + self::NOVA);
    }

    /**
     * Cada linha falha só no CHECK esperado (o PostgreSQL testa os CHECKs em ordem alfabética do nome, e a mensagem diz
     * qual recusou). O tipo fora do conjunto já cai na forma, que vem antes: o CHECK de tipo é a segunda rede.
     *
     * @return iterable<string, array{array<string, mixed>, string}>
     */
    public static function linhasInvalidas(): iterable
    {
        $forma = 'chk_ponto_ajuste_forma';
        yield 'tipo fora do conjunto' => [['tipo' => 'apagamento'] + self::NOVA, 'chk_ponto_ajuste_'];
        yield 'marcação nova fora dos 4 tipos' => [['tipo' => 'inclusao', 'tipo_marcacao_nova' => 'almoco', 'data_hora_nova' => '2026-10-21 12:00:00'], 'chk_ponto_ajuste_marcacao'];
        yield 'marcação anterior fora dos 4 tipos' => [['tipo' => 'desconsideracao', 'registro_ponto_id' => '@registro', 'tipo_marcacao_anterior' => 'almoco', 'data_hora_anterior' => '2026-10-21 08:00:00'], 'chk_ponto_ajuste_marcacao'];
        yield 'nova sem o par (data e hora sem tipo)' => [['tipo' => 'inclusao', 'data_hora_nova' => '2026-10-21 08:10:00'], 'chk_ponto_ajuste_pares'];
        yield 'anterior sem o par (data e hora sem tipo)' => [['tipo' => 'desconsideracao', 'registro_ponto_id' => '@registro', 'data_hora_anterior' => '2026-10-21 08:00:00'], 'chk_ponto_ajuste_pares'];
        yield 'motivo só com espaços' => [['tipo' => 'inclusao', 'motivo' => '    '] + self::NOVA, 'chk_ponto_ajuste_motivo'];
        yield 'motivo com 2001 caracteres' => [['tipo' => 'inclusao', 'motivo' => str_repeat('a', 2001)] + self::NOVA, 'chk_ponto_ajuste_motivo'];
        yield 'inclusão com raiz' => [['tipo' => 'inclusao', 'registro_ponto_id' => '@registro'] + self::NOVA, $forma];
        yield 'inclusão com anterior_id' => [['tipo' => 'inclusao', 'anterior_id' => '@inclusao'] + self::NOVA, $forma];
        yield 'inclusão com marcação anterior' => [['tipo' => 'inclusao'] + self::ANTERIOR + self::NOVA, $forma];
        yield 'inclusão sem a nova' => [['tipo' => 'inclusao'], $forma];
        yield 'alteração sem raiz' => [['tipo' => 'alteracao'] + self::ANTERIOR + self::NOVA, $forma];
        yield 'alteração com as duas raízes' => [['tipo' => 'alteracao', 'registro_ponto_id' => '@registro', 'inclusao_id' => '@inclusao', 'anterior_id' => '@inclusao'] + self::ANTERIOR + self::NOVA, $forma];
        yield 'alteração sem a anterior' => [['tipo' => 'alteracao', 'registro_ponto_id' => '@registro'] + self::NOVA, $forma];
        yield 'alteração sem a nova' => [['tipo' => 'alteracao', 'registro_ponto_id' => '@registro'] + self::ANTERIOR, $forma];
        yield 'desconsideração com a nova' => [['tipo' => 'desconsideracao', 'registro_ponto_id' => '@registro'] + self::ANTERIOR + self::NOVA, $forma];
        yield 'desconsideração sem a anterior' => [['tipo' => 'desconsideracao', 'registro_ponto_id' => '@registro'], $forma];
        yield 'restauração sem o que desfaz' => [['tipo' => 'restauracao', 'inclusao_id' => '@outraInclusao', 'anterior_id' => '@desconsideracao'] + self::NOVA, $forma];
        yield 'linha depois da inclusão sem anterior (segunda primeira linha)' => [['tipo' => 'alteracao', 'inclusao_id' => '@inclusao'] + self::ANTERIOR + self::NOVA, 'chk_ponto_ajuste_linhagem_da_inclusao'];
        yield 'restauração sem a nova' => [['tipo' => 'restauracao', 'inclusao_id' => '@outraInclusao', 'anterior_id' => '@desconsideracao', 'desfaz_id' => '@desconsideracao'], $forma];
    }

    /** @param array<string, mixed> $linha */
    #[DataProvider('linhasInvalidas')]
    #[TestDox('O banco recusa: $_dataName')]
    public function testLinhaInvalidaRecusada(array $linha, string $restricao): void
    {
        // A desconsideração que as restaurações desfazem fica noutra inclusão: nenhuma linha do caso esbarra nos índices
        // únicos, só no CHECK.
        $this->apoio['@outraInclusao'] = $this->inserir(['tipo' => 'inclusao'] + self::NOVA);
        $this->apoio['@desconsideracao'] = $this->inserir(['tipo' => 'desconsideracao', 'inclusao_id' => '@outraInclusao', 'anterior_id' => '@outraInclusao'] + self::ANTERIOR);
        $antes = $this->quantas();

        self::assertStringContainsString($restricao, $this->recusada($linha));
        self::assertSame($antes, $this->quantas());
    }

    #[TestDox('Cada tipo na forma certa entra (controle dos CHECKs), e o motivo de 2000 caracteres com espaços nas pontas também')]
    public function testFormasCertasEntram(): void
    {
        $alteracao = $this->inserir(['tipo' => 'alteracao', 'registro_ponto_id' => '@registro', 'motivo' => '  ' . str_repeat('m', 2000) . '  '] + self::ANTERIOR + self::NOVA);
        $this->apoio['@alteracao'] = $alteracao;
        $desconsideracao = $this->inserir(['tipo' => 'desconsideracao', 'registro_ponto_id' => '@registro', 'anterior_id' => '@alteracao', 'tipo_marcacao_anterior' => 'entrada', 'data_hora_anterior' => '2026-10-21 08:10:00']);
        $this->apoio['@desconsideracao'] = $desconsideracao;
        $this->inserir(['tipo' => 'restauracao', 'registro_ponto_id' => '@registro', 'anterior_id' => '@desconsideracao', 'desfaz_id' => '@desconsideracao'] + self::NOVA);
        $this->inserir(['tipo' => 'alteracao', 'inclusao_id' => '@inclusao', 'anterior_id' => '@inclusao'] + self::NOVA + ['tipo_marcacao_anterior' => 'entrada', 'data_hora_anterior' => '2026-10-21 08:10:00']);

        self::assertSame(5, $this->quantas(), 'a inclusão de apoio e as quatro linhas');
    }

    #[TestDox('Linhagem sem bifurcação: o registro tem uma primeira linha só, e cada linha um sucessor só')]
    public function testLinhagemNaoBifurca(): void
    {
        $primeira = $this->inserir(['tipo' => 'alteracao', 'registro_ponto_id' => '@registro'] + self::ANTERIOR + self::NOVA);
        $this->apoio['@primeira'] = $primeira;

        $outraPrimeira = $this->recusada(['tipo' => 'desconsideracao', 'registro_ponto_id' => '@registro'] + self::ANTERIOR);
        $this->inserir(['tipo' => 'desconsideracao', 'registro_ponto_id' => '@registro', 'anterior_id' => '@primeira'] + self::ANTERIOR);
        $outraSucessora = $this->recusada(['tipo' => 'alteracao', 'registro_ponto_id' => '@registro', 'anterior_id' => '@primeira'] + self::ANTERIOR + self::NOVA);

        self::assertStringContainsString('uniq_ponto_ajuste_raiz_batida', $outraPrimeira);
        self::assertStringContainsString('uniq_ponto_ajuste_anterior', $outraSucessora);

        self::assertSame(3, $this->quantas(), 'a inclusão de apoio, a primeira e a única sucessora');
    }

    #[TestDox('Ajuste aponta para batida: a batida não se apaga nem pela purga enquanto o ajuste existe (FK NO ACTION)')]
    public function testBatidaComAjusteNaoSomePorBaixo(): void
    {
        $this->inserir(['tipo' => 'desconsideracao', 'registro_ponto_id' => '@registro'] + self::ANTERIOR);

        $this->conexao->beginTransaction();
        try {
            $this->conexao->executeQuery("SELECT set_config('bluejus.purga_do_escritorio', ?, true)", [(string) $this->tenantId]);
            $this->conexao->executeStatement('DELETE FROM registro_ponto WHERE id = ?', [$this->apoio['@registro']]);
            self::fail('A batida com ajuste foi apagada.');
        } catch (DriverException $erro) {
            self::assertStringContainsString('foreign key', $erro->getMessage());
        } finally {
            $this->conexao->rollBack();
        }
    }

    /**
     * Tenta a linha num ponto de salvamento e devolve a mensagem da recusa do banco; falha o teste se o banco aceitar.
     *
     * @param array<string, mixed> $linha
     */
    private function recusada(array $linha): string
    {
        $this->conexao->beginTransaction();
        try {
            $this->inserir($linha);
        } catch (DriverException $erro) {
            $this->conexao->rollBack();

            return $erro->getMessage();
        }
        $this->conexao->rollBack();
        self::fail('O banco aceitou a linha: ' . json_encode($linha));
    }

    /** @param array<string, mixed> $linha */
    private function inserir(array $linha): int
    {
        $linha += [
            'tenant_id'   => $this->tenantId,
            'user_id'     => $this->pessoaId,
            'motivo'      => 'Correção da folha',
            'autor_nome'  => 'Gestora dos CHECKs',
            'criado_em'   => '2026-10-22 09:00:00',
        ];
        $linha = array_map(fn (mixed $valor): mixed => is_string($valor) && str_starts_with($valor, '@') ? $this->apoio[$valor] : $valor, $linha);
        $colunas = array_keys($linha);

        return (int) $this->conexao->fetchOne(
            sprintf('INSERT INTO ponto_ajuste (%s) VALUES (%s) RETURNING id', implode(', ', $colunas), implode(', ', array_fill(0, \count($colunas), '?'))),
            array_values($linha),
        );
    }

    private function quantas(): int
    {
        return (int) $this->conexao->fetchOne('SELECT COUNT(*) FROM ponto_ajuste WHERE tenant_id = ?', [$this->tenantId]);
    }
}
