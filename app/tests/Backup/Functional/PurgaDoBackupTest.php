<?php

declare(strict_types=1);

namespace App\Tests\Backup\Functional;

use App\Backup\Armazenamento\ChavesDeBackup;
use App\Backup\DTO\ResultadoDoBackup;
use App\Backup\Entity\PedidoDeBackup;
use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Shared\Armazenamento\ArmazenamentoDeArquivos;
use App\Shared\Armazenamento\ChaveDeArquivo;
use App\Shared\Armazenamento\FonteDeConteudo;
use App\Tenant\UseCase\PurgarEscritorioUseCase;
use App\Tests\Factory\Auth\UserFactory;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Zenstruck\Foundry\Test\Factories;

/** A purga do escritório leva os pedidos de backup e os ZIPs, e só os do escritório purgado (spec backup, seção 8). */
#[CoversClass(PurgarEscritorioUseCase::class)]
final class PurgaDoBackupTest extends KernelTestCase
{
    use Factories;

    private EntityManagerInterface $em;
    private ArmazenamentoDeArquivos $armazenamento;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->armazenamento = static::getContainer()->get(ArmazenamentoDeArquivos::class);
    }

    private function escritorioEmQuarentena(): Tenant
    {
        $tenant = new Tenant();
        $tenant->setName('Backup purga ' . uniqid());
        $tenant->setIsActive(false);
        $tenant->setExcluidoEm(new \DateTimeImmutable('-400 days'));
        $this->em->persist($tenant);
        $this->em->flush();

        return $tenant;
    }

    /** Um pedido pronto com o ZIP gravado no armazenamento, e um pedido na fila, sem arquivo. */
    private function semearBackups(Tenant $tenant, User $dono): ChaveDeArquivo
    {
        $agora = new \DateTimeImmutable('2026-11-23 10:00:00');
        $pronto = new PedidoDeBackup($tenant, $dono, $agora);
        $this->em->persist($pronto);
        $this->em->persist(new PedidoDeBackup($tenant, $dono, $agora));
        $this->em->flush();

        $gravado = $this->armazenamento->gravar(ChavesDeBackup::novoZip($pronto), FonteDeConteudo::deTexto('zip'));
        $pronto->iniciarMontagem($agora);
        $pronto->concluir(new ResultadoDoBackup($gravado->chave->nome, 3, str_repeat('a', 64), 1, 0), $agora, $agora->modify('+7 days'));
        $this->em->flush();

        return ChavesDeBackup::zip($pronto);
    }

    private function contar(int $tenantId): int
    {
        return (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM pedido_de_backup WHERE tenant_id = ?', [$tenantId]);
    }

    #[TestDox('Purgar o escritório apaga os pedidos de backup e os ZIPs; o vizinho fica intacto')]
    public function testPurgaCobreOBackup(): void
    {
        $dono = UserFactory::createOne()->_real();
        $alvo = $this->escritorioEmQuarentena();
        $vizinho = $this->escritorioEmQuarentena();
        $zipDoAlvo = $this->semearBackups($alvo, $dono);
        $zipDoVizinho = $this->semearBackups($vizinho, $dono);
        self::assertTrue($this->armazenamento->existe($zipDoAlvo));

        $resultado = static::getContainer()->get(PurgarEscritorioUseCase::class)->executar($alvo, dryRun: false);

        self::assertSame(0, $this->contar((int) $alvo->getId()), 'pedido_de_backup do escritório purgado ficou');
        self::assertFalse($this->armazenamento->existe($zipDoAlvo), 'o ZIP deveria ter saído do disco');
        self::assertSame([], $resultado->arquivosNaoRemovidos);

        self::assertSame(2, $this->contar((int) $vizinho->getId()), 'pedido_de_backup do vizinho foi apagado');
        self::assertTrue($this->armazenamento->existe($zipDoVizinho), 'a purga do alvo apagou o ZIP do vizinho');
        $this->armazenamento->excluir($zipDoVizinho);
    }
}
