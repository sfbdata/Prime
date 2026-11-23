<?php

declare(strict_types=1);

namespace App\Tests\Comercial\Functional;

use App\Comercial\Envio\CotaDeEnvio;
use App\Comercial\Service\AvisoDeTrocaDoServicoDeEmail;
use App\Entity\Auth\User;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * O e-mail ao Master acima de 2.000 e-mails em 24 h (pedido do Farlei, 23/11/2026): conta todos os enviados (também os
 * do sistema), vai a cada Master ativo, no máximo uma vez a cada 24 h, e nunca a quem não é Master.
 */
#[CoversClass(AvisoDeTrocaDoServicoDeEmail::class)]
final class AvisoDeTrocaDoServicoDeEmailTest extends KernelTestCase
{
    private Connection $conexao;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->conexao = static::getContainer()->get(Connection::class);
    }

    #[TestDox('2.000 enviados exatos não avisam')]
    public function testNoLimiteNaoAvisa(): void
    {
        $this->master();
        $this->semear(CotaDeEnvio::TROCAR_DE_SERVICO_ACIMA_DE, new \DateTimeImmutable('-1 hour'));

        self::assertSame(0, $this->aviso()->avisarSePreciso(new \DateTimeImmutable()));
        self::assertSame(0, $this->avisos());
    }

    #[TestDox('Acima de 2.000 avisa cada Master ativo (não o inativo nem o usuário comum), uma vez só nas 24 h')]
    public function testAvisaUmaVezPorDia(): void
    {
        $master = $this->master();
        $inativo = $this->master(false);
        $comum = $this->master(true, ['ROLE_USER']);
        $this->semear(CotaDeEnvio::TROCAR_DE_SERVICO_ACIMA_DE + 1, new \DateTimeImmutable('-1 hour'));
        $agora = new \DateTimeImmutable();

        $avisados = $this->aviso()->avisarSePreciso($agora);

        self::assertGreaterThanOrEqual(1, $avisados);
        self::assertSame($avisados, $this->avisos());
        $para = $this->conexao->fetchFirstColumn('SELECT destinatario FROM comercial_envio WHERE origem = ?', [AvisoDeTrocaDoServicoDeEmail::ORIGEM]);
        self::assertContains($master->getEmail(), $para);
        self::assertNotContains($inativo->getEmail(), $para);
        self::assertNotContains($comum->getEmail(), $para);
        self::assertSame('Hora de contratar outro serviço de e-mail', $this->conexao->fetchOne('SELECT assunto FROM comercial_envio WHERE origem = ? LIMIT 1', [AvisoDeTrocaDoServicoDeEmail::ORIGEM]));

        self::assertSame(0, $this->aviso()->avisarSePreciso($agora->modify('+15 minutes')), 'a volta seguinte não repete');
        self::assertSame($avisados, $this->avisos());
    }

    #[TestDox('O aviso de 25 h atrás não segura o de hoje')]
    public function testAvisoDeOntemNaoSegura(): void
    {
        $this->master();
        $this->semear(CotaDeEnvio::TROCAR_DE_SERVICO_ACIMA_DE + 1, new \DateTimeImmutable('-1 hour'));
        $this->conexao->executeStatement(
            "INSERT INTO comercial_envio (token, destinatario, assunto, tipo, origem, situacao, criado_em, enviado_em, aberturas, cliques)
             VALUES ('antigo-aviso-troca', 'master@exemplo.com', 'x', 'transacional', :origem, 'enviado', :em, :em, 0, 0)",
            ['origem' => AvisoDeTrocaDoServicoDeEmail::ORIGEM, 'em' => (new \DateTimeImmutable('-25 hours'))->format('Y-m-d H:i:s')],
        );

        self::assertGreaterThanOrEqual(1, $this->aviso()->avisarSePreciso(new \DateTimeImmutable()));
    }

    #[TestDox('O aviso é isento do teto diário (sai mesmo com a cota cheia)')]
    public function testIsentoDoTeto(): void
    {
        self::assertContains(AvisoDeTrocaDoServicoDeEmail::ORIGEM, CotaDeEnvio::ORIGENS_ISENTAS);
    }

    private function aviso(): AvisoDeTrocaDoServicoDeEmail
    {
        return static::getContainer()->get(AvisoDeTrocaDoServicoDeEmail::class);
    }

    private function avisos(): int
    {
        return (int) $this->conexao->fetchOne('SELECT COUNT(*) FROM comercial_envio WHERE origem = ?', [AvisoDeTrocaDoServicoDeEmail::ORIGEM]);
    }

    /** @param list<string> $papeis */
    private function master(bool $ativo = true, array $papeis = ['ROLE_SUPER_ADMIN']): User
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $user = (new User())->setEmail('troca_' . uniqid() . '@test.com')->setFullName('Pessoa Master')->setRoles($papeis)->setIsActive($ativo);
        $user->setPassword('x');
        $em->persist($user);
        $em->flush();

        return $user;
    }

    private function semear(int $quantos, \DateTimeImmutable $em): void
    {
        $this->conexao->executeStatement(
            "INSERT INTO comercial_envio (token, destinatario, assunto, tipo, origem, situacao, criado_em, enviado_em, aberturas, cliques)
             SELECT md5(random()::text || n), 'sistema', 'x', 'transacional', 'sistema', 'enviado', :em, :em, 0, 0 FROM generate_series(1, :quantos) AS n",
            ['em' => $em->format('Y-m-d H:i:s'), 'quantos' => $quantos],
        );
    }
}
