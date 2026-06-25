<?php

declare(strict_types=1);

namespace App\Tests\Tarefa\Functional;

use App\Entity\Auth\User;
use App\Entity\Tarefa\Tarefa;
use App\Entity\Tarefa\TarefaMensagem;
use App\Entity\Tenant\Tenant;
use App\Pasta\Entity\Pasta;
use App\Tarefa\UseCase\EditarTarefaMensagemUseCase;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Teste de integração (não unitário): exercita o UseCase contra o
 * EntityManager REAL — sem mock, conforme app/tests/CLAUDE.md. Verifica
 * COMPORTAMENTO (a mensagem foi de fato alterada/persistida e editadoEm
 * marcado), não a implementação (não checa "flush foi chamado"). O rollback
 * é automático via DAMA/DoctrineTestBundle.
 */
#[CoversClass(EditarTarefaMensagemUseCase::class)]
final class EditarTarefaMensagemUseCaseTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private EditarTarefaMensagemUseCase $useCase;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em      = static::getContainer()->get(EntityManagerInterface::class);
        $this->useCase = new EditarTarefaMensagemUseCase($this->em);
    }

    private function criarMensagem(string $conteudoInicial = 'Conteúdo original'): TarefaMensagem
    {
        $tenant = new Tenant();
        $tenant->setName('Tenant UC ' . uniqid());
        $this->em->persist($tenant);

        $autor = new User();
        $autor->setEmail('uc_' . uniqid() . '@test.com');
        $autor->setFullName('Autor UC');
        $autor->setIsActive(true);
        $this->em->persist($autor);

        $pasta = new Pasta();
        $pasta->setNup('UC-' . uniqid());
        $pasta->setTenant($tenant);
        $this->em->persist($pasta);

        $tarefa = new Tarefa();
        $tarefa->setTitulo('Meta UC');
        $tarefa->setDescricao('Desc');
        $tarefa->setPasta($pasta);
        $tarefa->setTenant($tenant);
        $this->em->persist($tarefa);

        $mensagem = new TarefaMensagem();
        $mensagem->setTarefa($tarefa);
        $mensagem->setUsuario($autor);
        $mensagem->setMensagem($conteudoInicial);
        $mensagem->setTenant($tenant);
        $this->em->persist($mensagem);
        $this->em->flush();

        return $mensagem;
    }

    #[TestDox('executar altera o conteúdo, marca editadoEm e persiste')]
    public function testEditarPersisteConteudoEMarcaEditado(): void
    {
        $mensagem = $this->criarMensagem();
        $id       = (int) $mensagem->getId();
        self::assertNull($mensagem->getEditadoEm());

        $this->useCase->executar($mensagem, '  Conteúdo editado  ');

        $this->em->clear();
        $persistida = $this->em->find(TarefaMensagem::class, $id);
        self::assertSame('Conteúdo editado', $persistida->getMensagem());
        self::assertNotNull($persistida->getEditadoEm());
    }

    #[TestDox('Conteúdo com exatamente 5000 caracteres é aceito (borda)')]
    public function testConteudoCom5000CaracteresEhAceito(): void
    {
        $mensagem = $this->criarMensagem();
        $id       = (int) $mensagem->getId();
        $conteudo = str_repeat('a', 5000);

        $this->useCase->executar($mensagem, $conteudo);

        $this->em->clear();
        $persistida = $this->em->find(TarefaMensagem::class, $id);
        self::assertSame($conteudo, $persistida->getMensagem());
        self::assertNotNull($persistida->getEditadoEm());
    }

    #[TestDox('Conteúdo vazio lança exceção e não altera a mensagem persistida')]
    public function testConteudoVazioLancaExcecaoENaoAltera(): void
    {
        $mensagem = $this->criarMensagem();
        $id       = (int) $mensagem->getId();

        try {
            $this->useCase->executar($mensagem, '   ');
            self::fail('Esperava InvalidArgumentException para conteúdo vazio.');
        } catch (\InvalidArgumentException) {
            // comportamento esperado
        }

        $this->em->clear();
        $persistida = $this->em->find(TarefaMensagem::class, $id);
        self::assertSame('Conteúdo original', $persistida->getMensagem());
        self::assertNull($persistida->getEditadoEm());
    }

    #[TestDox('Conteúdo acima de 5000 caracteres lança exceção')]
    public function testConteudoAcimaDe5000CaracteresLancaExcecao(): void
    {
        $mensagem = $this->criarMensagem();

        $this->expectException(\InvalidArgumentException::class);

        $this->useCase->executar($mensagem, str_repeat('a', 5001));
    }
}
