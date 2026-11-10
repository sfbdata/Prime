<?php

declare(strict_types=1);

namespace App\Tests\Ponto\Unit;

use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Ponto\DTO\DadosDaFolhaEmitida;
use App\Ponto\DTO\DadosDoAceiteDaFolha;
use App\Ponto\DTO\DadosDoComprovante;
use App\Ponto\Entity\ComprovanteDoPonto;
use App\Ponto\Entity\FolhaEmitidaDoPonto;
use App\Ponto\Entity\RegistroPonto;
use App\Ponto\Enum\SituacaoDoAceiteDaFolha;
use App\Ponto\Repository\ComprovanteDoPontoRepository;
use App\Ponto\Repository\FolhaEmitidaDoPontoRepository;
use App\Ponto\Service\ChavesDoHmacDoPonto;
use App\Ponto\Service\SeloDaFolha;
use App\Ponto\Service\SeloDaFolhaHmac;
use App\Ponto\Service\SeloDoComprovanteHmac;
use App\Ponto\UseCase\ValidarCodigoDoPontoUseCase;
use App\Ponto\UseCase\ValidarComprovanteUseCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/**
 * Validação de um código do Ponto (E15, P50; E16, decisão 17): o prefixo escolhe o que conferir. `BJP` segue para a
 * validação do comprovante da E15, como era; `BJF` confere a folha emitida (código no formato, linha no banco, HMAC
 * com a chave `ponto-folha` da versão gravada, dados no formato e no tipo da folha, mesmo código). Todo o resto devolve
 * null, sem dizer qual. O selo é o de produção, com as chaves da E9 sobre um segredo de teste.
 */
#[Group('ponto')]
#[CoversClass(ValidarCodigoDoPontoUseCase::class)]
final class ValidarCodigoDoPontoUseCaseTest extends TestCase
{
    private const FOLHA = 'BJF-ABCD-EFGH-JK23';
    private const COMPROVANTE = 'BJP-ABCD-EFGH-JK23';
    private const SEGREDO = 'segredo-de-teste';

    #[TestDox('Folha que existe e selo que confere: o texto do desenho (L803), com o código digitado normalizado')]
    public function testFolhaAutentica(): void
    {
        $selo = $this->selo();

        $texto = $this->validar($this->folha($selo), $selo, '  bjf-abcd-efgh-jk23 ');

        self::assertSame('Folha de Ponto 10/2026 de Maria da Silva Pereira em 03/11/2026, 09:00:00.', $texto);
    }

    #[TestDox('Folha com o selo que não confere (dado mexido depois de selado ou HMAC trocado): null')]
    public function testFolhaComSeloQueNaoConfere(): void
    {
        $selo = $this->selo();
        $mexida = $this->folha($selo, adulterar: fn (string $c): string => str_replace('Maria', 'Mário', $c));
        self::assertNull($this->validar($mexida, $selo, self::FOLHA), 'nome trocado depois do selo');

        self::assertNull($this->validar($this->folha($selo, hmac: str_repeat('0', 64)), $selo, self::FOLHA), 'HMAC trocado');
    }

    #[TestDox('Versão de chave gravada que o servidor não conhece, ou outro segredo: null')]
    public function testFolhaComOutraVersaoOuOutroSegredo(): void
    {
        $selo = $this->selo();
        self::assertNull($this->validar($this->folha($selo, versao: 2), $selo, self::FOLHA), 'versão 2 desconhecida');

        $outroSegredo = new SeloDaFolhaHmac(new ChavesDoHmacDoPonto('outro-segredo'));
        self::assertNull($this->validar($this->folha($selo), $outroSegredo, self::FOLHA), 'outro segredo');
    }

    #[TestDox('Selo que confere, mas com dados de outro código (linha trocada de código): null')]
    public function testFolhaComDadosDeOutroCodigo(): void
    {
        $selo = $this->selo();

        self::assertNull($this->validar($this->folha($selo, codigoNosDados: 'BJF-ZZZZ-ZZZZ-ZZZZ'), $selo, self::FOLHA));
    }

    #[TestDox('Formato que o leitor não conhece, ou o texto selado do aceite (mesma chave), com o selo conferindo: null')]
    public function testFolhaComFormatoOuTipoDesconhecido(): void
    {
        $selo = $this->selo();
        $formato2 = $this->folha($selo, antesDeSelar: fn (string $c): string => str_replace('"formato":1,', '"formato":2,', $c));
        self::assertTrue($selo->conferir($formato2->getDadosAssinados(), $formato2->getHmac(), 1), 'o selo confere');
        self::assertNull($this->validar($formato2, $selo, self::FOLHA), 'formato 2');

        $aceite = (new DadosDoAceiteDaFolha(7, 9, 2026, 10, 1, self::FOLHA, SituacaoDoAceiteDaFolha::Concordo, null, new \DateTimeImmutable('2026-11-03T09:05:00-03:00'), null, null))->canonico();
        $comAceite = $this->folha($selo, antesDeSelar: fn (): string => $aceite);
        self::assertTrue($selo->conferir($comAceite->getDadosAssinados(), $comAceite->getHmac(), 1), 'o selo confere');
        self::assertNull($this->validar($comAceite, $selo, self::FOLHA), 'texto do aceite');
    }

    #[TestDox('Folha que não existe: null')]
    public function testFolhaQueNaoExiste(): void
    {
        self::assertNull($this->validar(null, $this->selo(), self::FOLHA));
    }

    #[TestDox('Formato errado com o prefixo da folha: null, sem consultar o banco')]
    public function testFolhaComFormatoErradoNemConsulta(): void
    {
        $folhas = $this->createMock(FolhaEmitidaDoPontoRepository::class);
        $folhas->expects(self::never())->method('doCodigo');
        $usecase = new ValidarCodigoDoPontoUseCase($this->validarComprovante(null), $folhas, $this->selo());

        foreach (["BJF-ABCD' OR 1=1", 'BJF-ABCD-EFGH-JK2', 'BJF-ABCD-EFGH-JK10', 'BJF'] as $digitado) {
            self::assertNull($usecase->executar($digitado), $digitado);
        }
    }

    #[TestDox('Sem o segredo do HMAC (vazio ou ausente): nem a folha que existe é autêntica, e o banco não é consultado')]
    public function testFolhaComSeloIndisponivel(): void
    {
        $folha = $this->folha($this->selo());
        foreach ([null, ''] as $segredo) {
            $folhas = $this->createMock(FolhaEmitidaDoPontoRepository::class);
            $folhas->expects(self::never())->method('doCodigo');
            $indisponivel = new SeloDaFolhaHmac(new ChavesDoHmacDoPonto($segredo));

            self::assertNull((new ValidarCodigoDoPontoUseCase($this->validarComprovante(null), $folhas, $indisponivel))->executar($folha->getCodigo()));
        }
    }

    #[TestDox('Código BJP: segue para a validação do comprovante da E15, com o texto dela; a folha nem é consultada')]
    public function testComprovanteSegueParaAE15(): void
    {
        $folhas = $this->createMock(FolhaEmitidaDoPontoRepository::class);
        $folhas->expects(self::never())->method('doCodigo');
        $usecase = new ValidarCodigoDoPontoUseCase($this->validarComprovante($this->comprovante()), $folhas, $this->selo());

        self::assertSame('Entrada de Maria da Silva Pereira em 09/11/2026, 08:01:02, NSR 000000005.', $usecase->executar(' bjp-abcd-efgh-jk23'));
    }

    #[TestDox('Código BJP que a E15 não reconhece: null')]
    public function testComprovanteNaoAutentico(): void
    {
        $usecase = new ValidarCodigoDoPontoUseCase($this->validarComprovante(null), $this->createStub(FolhaEmitidaDoPontoRepository::class), $this->selo());

        self::assertNull($usecase->executar(self::COMPROVANTE));
    }

    #[TestDox('Prefixo desconhecido ou vazio: null, sem consultar comprovante nem folha')]
    public function testPrefixoDesconhecido(): void
    {
        $folhas = $this->createMock(FolhaEmitidaDoPontoRepository::class);
        $folhas->expects(self::never())->method('doCodigo');
        $comprovantes = $this->createMock(ComprovanteDoPontoRepository::class);
        $comprovantes->expects(self::never())->method('porCodigo');
        $usecase = new ValidarCodigoDoPontoUseCase(new ValidarComprovanteUseCase($comprovantes, $this->seloDoComprovante()), $folhas, $this->selo());

        foreach (['XYZ-ABCD-EFGH-JK23', 'ABCD-EFGH-JK23', '', '   ', 'BJ'] as $digitado) {
            self::assertNull($usecase->executar($digitado), "'" . $digitado . "'");
        }
    }

    private function selo(): SeloDaFolhaHmac
    {
        return new SeloDaFolhaHmac(new ChavesDoHmacDoPonto(self::SEGREDO));
    }

    private function seloDoComprovante(): SeloDoComprovanteHmac
    {
        return new SeloDoComprovanteHmac(new ChavesDoHmacDoPonto(self::SEGREDO));
    }

    private function validar(?FolhaEmitidaDoPonto $encontrada, SeloDaFolha $selo, string $digitado): ?string
    {
        $folhas = $this->createStub(FolhaEmitidaDoPontoRepository::class);
        $folhas->method('doCodigo')->willReturn($encontrada);

        return (new ValidarCodigoDoPontoUseCase($this->validarComprovante(null), $folhas, $selo))->executar($digitado);
    }

    private function validarComprovante(?ComprovanteDoPonto $encontrado): ValidarComprovanteUseCase
    {
        $comprovantes = $this->createStub(ComprovanteDoPontoRepository::class);
        $comprovantes->method('porCodigo')->willReturn($encontrado);

        return new ValidarComprovanteUseCase($comprovantes, $this->seloDoComprovante());
    }

    /**
     * @param (callable(string): string)|null $adulterar    troca o texto gravado DEPOIS de selado
     * @param (callable(string): string)|null $antesDeSelar troca o texto ANTES de selar (o selo confere com ele)
     */
    private function folha(SeloDaFolha $selo, ?callable $adulterar = null, ?string $hmac = null, ?int $versao = null, string $codigoNosDados = self::FOLHA, ?callable $antesDeSelar = null): FolhaEmitidaDoPonto
    {
        $emitidaEm = new \DateTimeImmutable('2026-11-03T09:00:00-03:00');
        $impressao = hash('sha256', 'resumo');
        $dados = new DadosDaFolhaEmitida($codigoNosDados, 2026, 10, 7, 9, 'Maria da Silva Pereira', 'Escritório Modelo', '12345678000190', $emitidaEm, 9, 'Maria da Silva Pereira', $impressao, ['totais' => ['trabalhado' => 0]]);
        $canonico = $antesDeSelar !== null ? $antesDeSelar($dados->canonico()) : $dados->canonico();
        $selado = $selo->selar($canonico);

        return new FolhaEmitidaDoPonto(new Tenant(), new User(), null, 2026, 10, self::FOLHA, $hmac ?? $selado['hmac'], $versao ?? $selado['versao'], $emitidaEm, $impressao, $adulterar !== null ? $adulterar($canonico) : $canonico);
    }

    private function comprovante(): ComprovanteDoPonto
    {
        $dados = new DadosDoComprovante(self::COMPROVANTE, 5, 7, 'Maria da Silva Pereira', 'Escritório Modelo', null, new \DateTimeImmutable('2026-11-09T08:01:02-03:00'), 'entrada', DadosDoComprovante::METODO_SISTEMA, '-23.55000, -46.63000 · precisão 12 m', false, false);
        $selado = $this->seloDoComprovante()->selar($dados->canonico());

        return new ComprovanteDoPonto(new Tenant(), (new RegistroPonto())->setUser(new User()), 5, self::COMPROVANTE, $selado['hmac'], $selado['versao'], new \DateTimeImmutable(), $dados->canonico());
    }
}
