<?php

declare(strict_types=1);

namespace App\Tests\Voto\Unit;

use App\Entity\Tenant\Tenant;
use App\Voto\DTO\AssembleiaInput;
use App\Voto\Entity\Assembleia;
use App\Voto\Enum\PesoDoVoto;
use App\Voto\Exception\OperacaoInvalidaNaAssembleiaException;
use App\Voto\Repository\AssembleiaRepository;
use App\Voto\Service\CnpjDaEntidade;
use App\Voto\Service\SerializacaoDaConfiguracao;
use App\Voto\UseCase\CriarAssembleiaUseCase;
use App\Voto\UseCase\EditarAssembleiaUseCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\InMemoryStore;
use Symfony\Component\Validator\Validation;

/** Nome da entidade e CNPJ da assembleia (F1-4): opcionais, só dígitos gravados, só em rascunho. */
#[CoversClass(Assembleia::class)]
#[CoversClass(CriarAssembleiaUseCase::class)]
#[CoversClass(EditarAssembleiaUseCase::class)]
final class EntidadeDaAssembleiaTest extends TestCase
{
    private function cnpjs(): CnpjDaEntidade
    {
        return new CnpjDaEntidade(Validation::createValidator());
    }

    #[TestDox('Criar grava o nome aparado e o CNPJ só com dígitos')]
    public function testCriarGravaEntidade(): void
    {
        $salva = null;
        $repo = $this->createMock(AssembleiaRepository::class);
        $repo->expects(self::once())->method('salvar')->willReturnCallback(static function (Assembleia $a) use (&$salva): void {
            $salva = $a;
        });

        (new CriarAssembleiaUseCase($repo, $this->cnpjs()))->executar(new Tenant(), new AssembleiaInput('AGO', 'fracao', '  Residencial Jardim ', '11.222.333/0001-81'), 'Gestora');

        self::assertInstanceOf(Assembleia::class, $salva);
        self::assertSame('Residencial Jardim', $salva->getEntidadeNome());
        self::assertSame('11222333000181', $salva->getCnpj());
    }

    #[TestDox('Criar sem entidade nem CNPJ deixa os dois nulos')]
    public function testCriarSemEntidade(): void
    {
        $salva = null;
        $repo = $this->createMock(AssembleiaRepository::class);
        $repo->method('salvar')->willReturnCallback(static function (Assembleia $a) use (&$salva): void {
            $salva = $a;
        });

        (new CriarAssembleiaUseCase($repo, $this->cnpjs()))->executar(new Tenant(), new AssembleiaInput('AGO', 'unidade'), 'Gestora');

        self::assertNull($salva->getEntidadeNome());
        self::assertNull($salva->getCnpj());
    }

    #[TestDox('Criar recusa CNPJ com dígito verificador errado e não salva nada')]
    public function testCriarRecusaCnpjInvalido(): void
    {
        $repo = $this->createMock(AssembleiaRepository::class);
        $repo->expects(self::never())->method('salvar');

        $this->expectException(OperacaoInvalidaNaAssembleiaException::class);
        (new CriarAssembleiaUseCase($repo, $this->cnpjs()))->executar(new Tenant(), new AssembleiaInput('AGO', 'fracao', 'Jardim', '11.222.333/0001-82'), 'Gestora');
    }

    #[TestDox('Editar troca a entidade e limpa o CNPJ quando vem vazio; CNPJ inválido recusa sem mexer no título')]
    public function testEditar(): void
    {
        $tenant = new Tenant();
        $assembleia = new Assembleia($tenant, 'AGO', PesoDoVoto::Fracao, 'Gestora');
        $assembleia->identificarEntidade('Velho', '11222333000181');
        $repo = $this->createMock(AssembleiaRepository::class);
        $repo->method('buscarDoEscritorio')->willReturn($assembleia);
        $usecase = new EditarAssembleiaUseCase($repo, new SerializacaoDaConfiguracao(new LockFactory(new InMemoryStore())), $this->cnpjs());

        $usecase->executar($tenant, 1, new AssembleiaInput('AGO nova', 'fracao', 'Novo', ''));
        self::assertSame('Novo', $assembleia->getEntidadeNome());
        self::assertNull($assembleia->getCnpj());
        self::assertSame('AGO nova', $assembleia->getTitulo());

        try {
            $usecase->executar($tenant, 1, new AssembleiaInput('Outro título', 'fracao', 'Novo', '123'));
            self::fail('CNPJ inválido devia recusar');
        } catch (OperacaoInvalidaNaAssembleiaException) {
            self::assertSame('AGO nova', $assembleia->getTitulo(), 'o título não mudou');
        }
    }

    #[TestDox('A entidade não muda depois de publicada e a coluna nunca guarda máscara')]
    public function testSoEmRascunhoESemMascara(): void
    {
        $assembleia = new Assembleia(new Tenant(), 'AGO', PesoDoVoto::Unidade, 'Gestora');
        try {
            $assembleia->identificarEntidade('Jardim', '11.222.333/0001-81');
            self::fail('a entidade recebe só dígitos');
        } catch (OperacaoInvalidaNaAssembleiaException) {
        }

        $publicada = new Assembleia(new Tenant(), 'AGO', PesoDoVoto::Unidade, 'Gestora');
        (new \ReflectionProperty(Assembleia::class, 'estado'))->setValue($publicada, \App\Voto\Enum\EstadoDaAssembleia::Publicada);
        $this->expectException(OperacaoInvalidaNaAssembleiaException::class);
        $publicada->identificarEntidade('Jardim', null);
    }
}
