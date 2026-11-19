<?php

declare(strict_types=1);

namespace App\Tests\Conector\Integration;

use App\Conector\DTO\ChamadaDoConector;
use App\Conector\DTO\RespostaDaFerramenta;
use App\Conector\Exception\ErroDaFerramenta;
use App\Conector\Ferramenta\BuscarPastasFerramenta;
use App\Conector\Ferramenta\FerramentaDoConector;
use App\Conector\Ferramenta\LerAgendaFerramenta;
use App\Conector\Ferramenta\LerPastaFerramenta;
use App\Conector\Ferramenta\LerProcessosFerramenta;
use App\Conector\Service\GuardaDaAcaoDoConector;
use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Pasta\Entity\Pasta;
use App\Pasta\Entity\PastaProcesso;
use App\Processo\Entity\Processo;
use App\Tests\Conector\Support\CenarioDoConectorTrait;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Fatia 3 da spec acesso-restrito-e-compartilhar: pelo conector de I.A., quem tem o acesso restrito só alcança as
 * pastas sob a sua responsabilidade (e os processos delas), como na tela. A lixeira do escritório, fechada na tela,
 * também fecha aqui; a agenda já fica de fora pela lista de liberação.
 */
#[CoversClass(GuardaDaAcaoDoConector::class)]
final class ConectorNoAcessoRestritoTest extends KernelTestCase
{
    use CenarioDoConectorTrait;

    private const PERMISSOES = [
        'modules.expediente.view', 'modules.pastas.view', 'modules.processos.view', 'modules.agenda.view',
        'modules.tarefas.view', 'resources.pasta.view', 'resources.pasta.edit', 'resources.processo.view',
    ];

    private Tenant $escritorio;
    private User $restrito;
    private User $comum;
    private Pasta $minha;
    private Pasta $alheia;
    private Processo $processoMeu;
    private Processo $processoAlheio;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->escritorio = $this->escritorio();
        $this->restrito = $this->pessoa();
        $vinculo = $this->vinculo($this->restrito, $this->escritorio, permissoes: self::PERMISSOES);
        $vinculo->getTenantRole()?->restringirAsPastasSobResponsabilidade('Parceiro');
        $this->comum = $this->pessoa();
        $this->vinculo($this->comum, $this->escritorio, permissoes: self::PERMISSOES);

        $this->processoMeu    = $this->processo('Classe Restrita Conector');
        $this->processoAlheio = $this->processo('Classe Restrita Conector');
        $this->minha  = $this->pasta($this->restrito, $this->processoMeu);
        $this->alheia = $this->pasta($this->comum, $this->processoAlheio);
    }

    protected function tearDown(): void
    {
        static::getContainer()->get('services_resetter')->reset();
        parent::tearDown();
    }

    #[TestDox('buscar_pastas: o restrito só acha a pasta dele, pela lista e pelo número; o comum acha as duas')]
    public function testBuscarPastas(): void
    {
        $ids = array_column($this->chamar(BuscarPastasFerramenta::class, ['acao' => 'buscar'], $this->restrito)->dados['pastas'], 'id');
        self::assertSame([$this->minha->getId()], $ids);

        $porNumero = $this->chamar(BuscarPastasFerramenta::class, ['acao' => 'numero', 'numero' => (string) $this->alheia->getNup()], $this->restrito);
        self::assertStringNotContainsString((string) $this->alheia->getNup(), json_encode($porNumero->dados, \JSON_THROW_ON_ERROR));

        $todas = array_column($this->chamar(BuscarPastasFerramenta::class, ['acao' => 'buscar'], $this->comum)->dados['pastas'], 'id');
        sort($todas);
        self::assertSame([$this->minha->getId(), $this->alheia->getId()], $todas);
    }

    #[TestDox('ler_pasta: a pasta alheia é "não encontrado" para o restrito, igual à inexistente')]
    public function testLerPasta(): void
    {
        $this->chamar(LerPastaFerramenta::class, ['pasta_id' => $this->minha->getId()], $this->restrito);
        $this->assertMotivo(LerPastaFerramenta::class, ['pasta_id' => $this->alheia->getId()], ErroDaFerramenta::MOTIVO_NAO_ENCONTRADO);
    }

    #[TestDox('ler_processos: na busca o restrito só vê o processo da pasta dele')]
    public function testLerProcessos(): void
    {
        $resposta = $this->chamar(LerProcessosFerramenta::class, ['acao' => 'buscar', 'termo' => 'Classe Restrita Conector'], $this->restrito);

        self::assertSame([$this->processoMeu->getId()], array_column($resposta->dados['processos'], 'id'));
    }

    #[TestDox('a lixeira do escritório e a agenda ficam fechadas ao restrito; o comum segue usando a lixeira')]
    public function testLixeiraEAgenda(): void
    {
        $this->assertMotivo(BuscarPastasFerramenta::class, ['acao' => 'lixeira'], ErroDaFerramenta::MOTIVO_SEM_PERMISSAO);
        $this->assertMotivo(LerAgendaFerramenta::class, ['acao' => 'minhas_metas', 'periodo' => 'hoje'], ErroDaFerramenta::MOTIVO_SEM_PERMISSAO);

        self::assertSame([], $this->chamar(BuscarPastasFerramenta::class, ['acao' => 'lixeira'], $this->comum)->dados['pastas']);
    }

    /**
     * @param class-string<FerramentaDoConector> $ferramenta
     * @param array<string, mixed>     $argumentos
     */
    private function chamar(string $ferramenta, array $argumentos, User $pessoa): RespostaDaFerramenta
    {
        $contexto = $this->portao()->abrir((int) $pessoa->getId(), (int) $this->escritorio->getId());
        $chamada = new ChamadaDoConector($contexto, 41, 42, 'claude.ai', ['mcp:leitura'], new \DateTimeImmutable('+7 days'));

        try {
            return static::getContainer()->get($ferramenta)->executar($argumentos, $chamada);
        } finally {
            static::getContainer()->get('services_resetter')->reset();
        }
    }

    /**
     * @param class-string<FerramentaDoConector> $ferramenta
     * @param array<string, mixed>     $argumentos
     */
    private function assertMotivo(string $ferramenta, array $argumentos, string $motivo): void
    {
        try {
            $this->chamar($ferramenta, $argumentos, $this->restrito);
        } catch (ErroDaFerramenta $e) {
            self::assertSame($motivo, $e->motivo);

            return;
        }
        self::fail('deveria recusar com ' . $motivo);
    }

    private function processo(string $classe): Processo
    {
        $processo = new Processo();
        $processo->setNumeroProcesso(sprintf('0701134572025807%04d', random_int(1000, 9999)));
        $processo->setSiglaTribunal('TJDFT');
        $processo->setClasseProcessual($classe);
        $processo->setAssuntoProcessual('Assunto');
        $processo->setTenant($this->escritorio);
        $this->em()->persist($processo);
        $this->em()->flush();

        return $processo;
    }

    private function pasta(User $responsavel, Processo $processo): Pasta
    {
        $pasta = (new Pasta())->setNup('RES-' . uniqid());
        $pasta->setTenant($this->escritorio);
        $pasta->setResponsavel($responsavel);
        $this->em()->persist($pasta);
        $vinculo = new PastaProcesso($pasta, $processo);
        $this->em()->persist($vinculo);
        $pasta->getPastaProcessos()->add($vinculo);
        $this->em()->flush();

        return $pasta;
    }
}
