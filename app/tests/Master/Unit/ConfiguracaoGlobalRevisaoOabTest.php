<?php

declare(strict_types=1);

namespace App\Tests\Master\Unit;

use App\Master\Entity\ConfiguracaoGlobal;
use App\Master\Repository\ConfiguracaoGlobalRepository;
use App\Master\UseCase\AlterarValidacaoAutomaticaOabUseCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

#[CoversClass(ConfiguracaoGlobal::class)]
#[CoversClass(AlterarValidacaoAutomaticaOabUseCase::class)]
final class ConfiguracaoGlobalRevisaoOabTest extends TestCase
{
    #[TestDox('a configuração da Revisão de OAB não aparece nos valores do catálogo e sobrevive a salvar a tela de configurações')]
    public function testSeparadaDoCatalogo(): void
    {
        $linha = new ConfiguracaoGlobal();
        $linha->definirValores(['sessao' => '60'], null, new \DateTimeImmutable('2026-10-01'));
        $linha->definirRevisaoOab(['ligada' => '1', 'frequencia' => 'cadastro']);

        self::assertSame(['sessao' => '60'], $linha->getValores());
        self::assertSame('2026-10-01', $linha->getAlteradoEm()->format('Y-m-d'), 'gravar a revisão não muda a última alteração da tela de configurações');

        $linha->definirValores(['sessao' => '90'], null, new \DateTimeImmutable());

        self::assertSame(['sessao' => '90'], $linha->getValores());
        self::assertSame(['ligada' => '1', 'frequencia' => 'cadastro'], $linha->getRevisaoOab());
    }

    #[TestDox('liga e desliga com "Ao cadastrar"; Diária e Semanal são recusadas enquanto o CNA está desligado')]
    public function testFrequencia(): void
    {
        $linha = new ConfiguracaoGlobal();
        $repo = $this->createStub(ConfiguracaoGlobalRepository::class);
        $repo->method('buscarUnica')->willReturn($linha);
        $sut = new AlterarValidacaoAutomaticaOabUseCase($repo);

        $sut->executar(true, 'cadastro');
        self::assertSame('1', $linha->getRevisaoOab()['ligada']);
        $sut->executar(false, 'cadastro');
        self::assertSame('0', $linha->getRevisaoOab()['ligada']);

        foreach (['diaria', 'semanal', 'xyz'] as $frequencia) {
            try {
                $sut->executar(true, $frequencia);
                self::fail($frequencia . ' não pode ser aceita sem o CNA');
            } catch (\DomainException $e) {
                self::assertStringNotContainsString('—', $e->getMessage());
            }
        }
        self::assertSame('0', $linha->getRevisaoOab()['ligada'], 'recusa não grava');
    }
}
