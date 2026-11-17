<?php

declare(strict_types=1);

namespace App\Tests\Conector\Integration;

use App\Conector\Entity\EscritorioDoConector;
use App\Conector\Exception\AcessoDoConectorRecusado;
use App\Conector\Repository\EscritorioDoConectorRepository;
use App\Conector\Service\GuardaDoMasterDoConector;
use App\Conector\Service\LiberacaoDoConector;
use App\Conector\Service\ValidadorDoTokenDoConector;
use App\Conector\UseCase\AlterarLiberacaoDoConectorPeloMasterUseCase;
use App\Entity\Audit\AuditLog;
use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Master\Service\RecursosDoSistema;
use App\Tests\Conector\Support\CadeiaDoMcpTrait;
use Doctrine\DBAL\Types\Types;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

/**
 * O Master libera ou bloqueia o conector num escritório (spec mcp-remoto-oauth 14.a, nível 2; PR 8): só o Master, só
 * escritório ativo, a linha certa mesmo com o `TenantFilter` ligado em outro escritório, auditoria no escritório alvo.
 * Bloquear só suspende (decisão 4): o aceite fica, o token da conexão recebe 403 na hora (sem `invalid_token`, então o
 * app não reconecta) e volta a valer ao liberar.
 */
#[CoversClass(AlterarLiberacaoDoConectorPeloMasterUseCase::class)]
#[CoversClass(GuardaDoMasterDoConector::class)]
final class AlterarLiberacaoDoConectorPeloMasterUseCaseTest extends KernelTestCase
{
    use CadeiaDoMcpTrait;

    protected function setUp(): void
    {
        self::bootKernel();
    }

    private function sut(): AlterarLiberacaoDoConectorPeloMasterUseCase
    {
        return static::getContainer()->get(AlterarLiberacaoDoConectorPeloMasterUseCase::class);
    }

    private function master(): User
    {
        return $this->pessoa(['ROLE_SUPER_ADMIN', 'ROLE_MASTER_PRIMARIO']);
    }

    private function linhaDe(Tenant $tenant): ?EscritorioDoConector
    {
        return $this->em()->getRepository(EscritorioDoConector::class)->findOneBy(['tenant' => $tenant->getId()]);
    }

    private function liberacao(): LiberacaoDoConector
    {
        return new LiberacaoDoConector(
            static::getContainer()->get(RecursosDoSistema::class),
            static::getContainer()->get(EscritorioDoConectorRepository::class),
        );
    }

    #[TestDox('quem não é Master (até o primário de um escritório) é recusado e nada é gravado')]
    public function testNaoMasterRecusado(): void
    {
        $alvo = $this->escritorio();
        $comum = $this->pessoa(['ROLE_USER']);
        $this->vinculo($comum, $alvo, sistema: true);

        try {
            $this->sut()->executar((int) $alvo->getId(), true, $comum);
            self::fail('liberou sem ser Master');
        } catch (AccessDeniedException) {
        }

        self::assertNull($this->linhaDe($alvo));
    }

    #[TestDox('escritório inexistente, suspenso ou excluído: não encontrado, sem linha nova')]
    public function testEscritorioInativoNaoEncontrado(): void
    {
        $master = $this->master();
        $suspenso = $this->escritorio();
        $suspenso->setIsActive(false);
        $excluido = $this->escritorio();
        $excluido->setExcluidoEm(new \DateTimeImmutable('-1 day'));
        $this->em()->flush();

        foreach ([0, 999999999, (int) $suspenso->getId(), (int) $excluido->getId()] as $id) {
            try {
                $this->sut()->executar($id, true, $master);
                self::fail('achou o escritório ' . $id);
            } catch (\DomainException $e) {
                self::assertSame(AlterarLiberacaoDoConectorPeloMasterUseCase::ESCRITORIO_NAO_ENCONTRADO, $e->getMessage());
            }
        }

        self::assertNull($this->linhaDe($suspenso));
        self::assertNull($this->linhaDe($excluido));
    }

    #[TestDox('liberar cria a linha só com o nível 2: não liga nem grava aceite; a auditoria vai para o escritório alvo')]
    public function testLiberarCriaALinhaEAuditaNoAlvo(): void
    {
        $alvo = $this->escritorio();
        $outro = $this->escritorio();
        $auditoria = $this->em()->getRepository(AuditLog::class);
        $antes = $auditoria->count(['entityClass' => EscritorioDoConector::class]);

        $this->sut()->executar((int) $alvo->getId(), true, $this->master());

        $linha = $this->linhaDe($alvo);
        self::assertNotNull($linha);
        self::assertTrue($linha->isLiberadoPeloMaster());
        self::assertFalse($linha->isLigado(), 'liberar não liga: falta o administrador');
        self::assertNull($linha->getAceiteEm());
        self::assertNull($this->linhaDe($outro), 'o outro escritório não muda');
        $registros = $auditoria->findBy(['entityClass' => EscritorioDoConector::class, 'tenantId' => $alvo->getId()]);
        self::assertCount(1, $registros);
        self::assertSame('create', $registros[0]->getAction());
        self::assertSame($antes + 1, $auditoria->count(['entityClass' => EscritorioDoConector::class]), 'um registro só, e no alvo');
    }

    #[TestDox('Master com o TenantFilter ligado em outro escritório acha e altera a linha certa, sem criar outra')]
    public function testSessaoEmOutroEscritorio(): void
    {
        $doMaster = $this->escritorio();
        $alvo = $this->escritorio();
        $existente = new EscritorioDoConector($alvo);
        $this->em()->persist($existente);
        $this->em()->flush();
        $this->em()->getFilters()->enable('tenant')->setParameter('tenant', (int) $doMaster->getId(), Types::INTEGER);

        $this->sut()->executar((int) $alvo->getId(), true, $this->master());

        $this->em()->getFilters()->disable('tenant');
        $this->em()->clear();
        $linhas = $this->em()->getRepository(EscritorioDoConector::class)->findBy(['tenant' => $alvo->getId()]);
        self::assertCount(1, $linhas, 'atualiza a linha que existe (a única por escritório)');
        self::assertSame($existente->getId(), $linhas[0]->getId());
        self::assertTrue($linhas[0]->isLiberadoPeloMaster());
        self::assertSame([], $this->em()->getRepository(EscritorioDoConector::class)->findBy(['tenant' => $doMaster->getId()]), 'o da sessão não ganha linha');
    }

    #[TestDox('bloquear mantém o aceite e o "ligado", faz a liberação recusar e não mexe no outro escritório; liberar de novo volta sem novo aceite')]
    public function testBloquearMantemOAceite(): void
    {
        $master = $this->master();
        $alvo = $this->escritorio();
        $outro = $this->escritorio();
        $admin = $this->pessoa();
        $linha = $this->liberar($alvo, $admin);
        $this->liberar($outro, $admin);
        $aceiteEm = $linha->getAceiteEm();

        $this->sut()->executar((int) $alvo->getId(), false, $master);

        self::assertFalse($linha->isLiberadoPeloMaster());
        self::assertTrue($linha->isLigado());
        self::assertEquals($aceiteEm, $linha->getAceiteEm());
        self::assertSame($admin->getId(), $linha->getAceitoPor()?->getId());
        try {
            $this->liberacao()->exigir($alvo);
            self::fail('bloqueado e ainda liberado');
        } catch (AcessoDoConectorRecusado $e) {
            self::assertSame(AcessoDoConectorRecusado::MOTIVO_LIBERACAO, $e->motivo);
        }
        $this->liberacao()->exigir($outro);

        $this->sut()->executar((int) $alvo->getId(), true, $master);

        $this->liberacao()->exigir($alvo);
        self::assertEquals($aceiteEm, $linha->getAceiteEm(), 'liberar de novo não pede nem regrava o aceite');
    }

    #[TestDox('bloquear só suspende: o mesmo token é recusado na chamada seguinte (403, sem invalid_token) e volta a valer ao liberar')]
    public function testBloquearSuspendeOToken(): void
    {
        $master = $this->master();
        $conexao = $this->conexaoAutorizada();
        $bearer = $this->tokenDeAcesso($conexao['concessao']);
        self::assertTrue($this->validador()->validate($bearer)->isAllowed());

        $this->sut()->executar((int) $conexao['escritorio']->getId(), false, $master);
        $this->validador()->reset();

        $recusa = $this->validador()->validate($bearer);
        self::assertSame(403, $recusa->getStatusCode());
        self::assertSame(ValidadorDoTokenDoConector::ERRO_CONECTOR_DESLIGADO, $recusa->getError());
        self::assertSame(AcessoDoConectorRecusado::MOTIVO_LIBERACAO, $this->validador()->motivoDaRecusa());
        self::assertSame(ValidadorDoTokenDoConector::DESCRICAO_LIBERACAO, $recusa->getErrorDescription());
        self::assertNull($conexao['consentimento']->getRevogadoEm(), 'bloquear não revoga a conexão');

        $this->sut()->executar((int) $conexao['escritorio']->getId(), true, $master);
        $this->validador()->reset();

        self::assertTrue($this->validador()->validate($bearer)->isAllowed(), 'liberar de novo volta sem reconectar');
    }
}
