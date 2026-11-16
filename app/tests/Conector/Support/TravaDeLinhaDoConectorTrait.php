<?php

declare(strict_types=1);

namespace App\Tests\Conector\Support;

use App\Conector\Entity\ClienteDoConector;
use App\Conector\Entity\CodigoDoConector;
use App\Conector\Entity\ConcessaoDoConector;
use App\Conector\Entity\ConsentimentoDoConector;
use App\Conector\Entity\TokenDoConector;
use App\Conector\Enum\TipoDeClienteDoConector;
use App\Conector\Service\SegredoOpaco;
use App\Conector\Service\ServidorDeAutorizacao;
use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Exception\DriverException;
use Doctrine\ORM\EntityManager;
use Symfony\Component\Clock\Clock;

/**
 * Prova da trava de linha do `/oauth/token` com duas conexões de verdade, fora do DAMA (o mesmo modelo do
 * `NumeroSequencialDoRegistroTest`): o que o teste grava pela conexão do DAMA nunca é confirmado e uma conexão nova não
 * o enxerga, então a cadeia daqui é confirmada de fato e apagada no fim.
 *
 * A conexão de fora segura a linha (`SELECT ... FOR UPDATE` numa transação aberta); o UseCase roda na conexão do teste
 * com `lock_timeout` curto. O erro 55P03 (lock_not_available) só sai se o UseCase pediu a MESMA trava: sem o
 * `FOR UPDATE`, ele leria a linha sem esperar e seguiria. O PDO é síncrono, por isso o tempo limite em vez de esperar.
 */
trait TravaDeLinhaDoConectorTrait
{
    /**
     * Grava e confirma, por fora do DAMA, uma cadeia mínima: escritório, pessoa, app, consentimento, concessão, um código
     * e uma renovação. Com `$jaGirada`, a renovação já girou (usada agora) e tem uma filha nunca usada. Datas no relógio
     * do teste. Devolve os valores em texto e o que a limpeza precisa.
     *
     * @return array{conexao: Connection, codigo: string, renovacao: string, filha: ?string, cliente: string, tenantId: int, userId: int, clienteId: int, concessaoId: int}
     */
    private function cadeiaConfirmadaPorFora(bool $jaGirada = false): array
    {
        $doTeste = static::getContainer()->get('doctrine.orm.entity_manager');
        $parametros = array_intersect_key($doTeste->getConnection()->getParams(), array_flip(['driver', 'host', 'port', 'dbname', 'user', 'password', 'charset', 'serverVersion']));
        $conexao = DriverManager::getConnection($parametros);
        $em = new EntityManager($conexao, $doTeste->getConfiguration());

        $agora = \DateTimeImmutable::createFromInterface(Clock::get()->now());
        $tenant = (new Tenant())->setName('Escritório trava ' . uniqid())->setIsActive(true);
        $pessoa = (new User())->setEmail('trava_' . uniqid() . '@teste.com')->setFullName('Pessoa da trava')->setRoles(['ROLE_USER'])->setIsActive(true);
        $pessoa->setPassword('x');
        $cliente = new ClienteDoConector('https://claude.ai/oauth/' . uniqid() . '/client.json', TipoDeClienteDoConector::Cimd, 'App', ['https://claude.ai/api/mcp/auth_callback'], $agora);
        $consentimento = new ConsentimentoDoConector($pessoa, $tenant, $cliente, ['mcp:leitura'], $agora);
        $concessao = new ConcessaoDoConector($consentimento, ['mcp:leitura'], ServidorDeAutorizacao::RESOURCE, hash('sha256', 'x'), $agora);
        [$codigo, $renovacao] = [(new SegredoOpaco())->gerar(), (new SegredoOpaco())->gerar()];
        foreach ([$tenant, $pessoa, $cliente, $consentimento, $concessao] as $entidade) {
            $em->persist($entidade);
        }
        $em->persist(new CodigoDoConector($codigo['hash'], $concessao, 'https://claude.ai/api/mcp/auth_callback', 'E9Melhoa2OwvFrEMTJguCHaoeK1t8URWbuGJSstw-cM', $agora));
        $em->persist($mae = TokenDoConector::deRenovacao($renovacao['hash'], $concessao, $agora));
        $filha = $jaGirada ? $this->girarPorFora($em, $mae, $cliente, $agora) : null;
        $em->flush();

        return [
            'conexao' => $conexao,
            'codigo' => $codigo['valor'],
            'renovacao' => $renovacao['valor'],
            'filha' => $filha,
            'cliente' => $cliente->getIdentificador(),
            'tenantId' => (int) $tenant->getId(),
            'userId' => (int) $pessoa->getId(),
            'clienteId' => (int) $cliente->getId(),
            'concessaoId' => (int) $concessao->getId(),
        ];
    }

    /** A primeira rotação da mãe, feita direto na entidade: a mãe fica usada e a filha (devolvida em texto) nasce dela. */
    private function girarPorFora(EntityManager $em, TokenDoConector $mae, ClienteDoConector $cliente, \DateTimeImmutable $agora): string
    {
        $mae->girar($cliente, $agora);
        $filha = (new SegredoOpaco())->gerar();
        $em->persist(TokenDoConector::deRenovacao($filha['hash'], $mae->getConcessao(), $agora, $mae));

        return $filha['valor'];
    }

    /**
     * Segura a linha pela conexão de fora e confere que `$trocar` (na conexão do teste) espera por ela.
     *
     * @param array{conexao: Connection, tenantId: int, userId: int, clienteId: int} $cadeia
     */
    private function assertEsperaATrava(array $cadeia, string $tabela, string $hash, callable $trocar): void
    {
        $fora = $cadeia['conexao'];
        try {
            $fora->beginTransaction();
            self::assertNotFalse($fora->fetchOne("SELECT id FROM {$tabela} WHERE identificador_hash = ? FOR UPDATE", [$hash]), 'a conexão de fora segura a linha');
            static::getContainer()->get('doctrine.orm.entity_manager')->getConnection()->executeStatement("SET LOCAL lock_timeout = '400ms'");

            $this->assertEsperouNaLeituraTravada($tabela, $trocar);
        } finally {
            $this->apagarCadeiaPorFora($cadeia);
        }
    }

    /**
     * `$trocar` tem de parar no `lock_timeout` esperando a linha de `$tabela` numa leitura travada.
     */
    private function assertEsperouNaLeituraTravada(string $tabela, callable $trocar): void
    {
        try {
            $trocar();
            self::fail('a troca passou sem esperar a linha travada: o UseCase não pediu FOR UPDATE');
        } catch (DriverException $erro) {
            self::assertSame('55P03', $erro->getSQLState(), 'só o lock_not_available prova que a troca esperou a linha');
            // "locking tuple" é a espera do SELECT ... FOR UPDATE. Sem a trava, a troca leria a linha e só esperaria no
            // UPDATE do fim ("updating tuple"), depois de decidir com o dado velho: é isso que a trava impede.
            self::assertStringContainsString('while locking tuple', $erro->getMessage(), 'a espera tem de ser na leitura travada');
            self::assertStringContainsString(\sprintf('in relation "%s"', $tabela), $erro->getMessage(), 'a espera tem de ser nesta tabela');
        }
    }

    /**
     * Desfaz o que a conexão de fora tiver aberto, apaga a cadeia confirmada (o resto cai em cascata) e fecha a conexão.
     *
     * @param array{conexao: Connection, tenantId: int, userId: int, clienteId: int} $cadeia
     */
    private function apagarCadeiaPorFora(array $cadeia): void
    {
        $fora = $cadeia['conexao'];
        if ($fora->isTransactionActive()) {
            $fora->rollBack();
        }
        $fora->executeStatement('DELETE FROM tenant WHERE id = ?', [$cadeia['tenantId']]);
        $fora->executeStatement('DELETE FROM "user" WHERE id = ?', [$cadeia['userId']]);
        $fora->executeStatement('DELETE FROM conector_ia_cliente WHERE id = ?', [$cadeia['clienteId']]);
        $fora->close();
    }
}
