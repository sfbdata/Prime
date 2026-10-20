<?php

declare(strict_types=1);

namespace App\Tests\Pasta\Functional;

use App\Central\Service\TiposDeAvisoDaCentral;
use App\Entity\Auth\User;
use App\Entity\Auth\UserTenant;
use App\Entity\Tenant\Tenant;
use App\Pasta\DTO\RelatorioPastasParadasOutput;
use App\Pasta\Repository\EnvioDoRelatorioPastasParadasRepository;
use App\Pasta\Service\MontagemDoRelatorioPastasParadas;
use App\Pasta\UseCase\EnviarRelatorioPastasParadasUseCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Envio mensal do relatório de pastas paradas (spec docs/specs/pastas-paradas.md, "Envio
 * automático"; desenho `paradasEnviar` e `paradasNotificar`). Integração: as consultas
 * (Controladoria, primários, pastas, trava do mês) são parte da regra e só se provam no banco.
 * Roda sem request, como o comando: sem TenantFilter, com o escritório explícito.
 *
 * Escritório-base, relógio na primeira segunda-feira de outubro de 2026 (05/10, 10h05):
 * Paula (primária), Carla (Controladoria, secundária com Expediente), Rui (responsável com
 * Expediente), Nina (responsável sem o módulo) e Ivo (responsável com vínculo inativo).
 * Pastas: 1180 e 1181 do Rui (abertas em 01/06 e 01/08), 1182 da Nina, 1183 do Rui aberta em 20/09
 * (só 15 dias: não está parada) e 1184 do Ivo.
 */
#[CoversClass(EnviarRelatorioPastasParadasUseCase::class)]
#[CoversClass(MontagemDoRelatorioPastasParadas::class)]
#[CoversClass(EnvioDoRelatorioPastasParadasRepository::class)]
#[Group('pasta')]
final class EnviarRelatorioPastasParadasUseCaseTest extends KernelTestCase
{
    use CriaEscritorioPastasParadasTrait;

    private const PRIMEIRA_SEGUNDA = '2026-10-05 10:05:00';

    private Tenant $tenant;
    private User $paula;
    private User $carla;
    private User $rui;
    private User $nina;
    private User $ivo;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->tenant = $this->novoEscritorio('Paradas');
        $primario     = $this->novoPerfil($this->tenant, true);
        $secundario   = $this->novoPerfil($this->tenant, false, ['modules.expediente.view']);
        $this->paula  = $this->novaPessoa($this->tenant, 'Paula Primária', $primario);
        $this->carla  = $this->novaPessoa($this->tenant, 'Carla Controladoria', $secundario, controladoria: true);
        $this->rui    = $this->novaPessoa($this->tenant, 'Rui Responsável', $secundario);
        $this->nina   = $this->novaPessoa($this->tenant, 'Nina Sem Módulo', $this->novoPerfil($this->tenant, false));
        $this->ivo    = $this->novaPessoa($this->tenant, 'Ivo Inativo', $secundario, vinculoAtivo: false);

        $this->pastaAbertaEm($this->tenant, '1180', '2026-06-01', $this->rui);
        $this->pastaAbertaEm($this->tenant, '1181', '2026-08-01', $this->rui);
        $this->pastaAbertaEm($this->tenant, '1182', '2026-07-01', $this->nina);
        $this->pastaAbertaEm($this->tenant, '1183', '2026-09-20', $this->rui);
        $this->pastaAbertaEm($this->tenant, '1184', '2026-07-15', $this->ivo);
    }

    private function useCase(string $agora = self::PRIMEIRA_SEGUNDA): EnviarRelatorioPastasParadasUseCase
    {
        return $this->enviarParadasComRelogio($agora);
    }

    #[TestDox('Primeira segunda-feira, 10h05: o responsável recebe as pastas dele; Controladoria e primária, a lista; link para a página')]
    public function testAvisosDaPrimeiraSegunda(): void
    {
        $resultado = $this->useCase()->executar($this->tenant);

        self::assertTrue($resultado->enviado);
        self::assertSame(EnviarRelatorioPastasParadasUseCase::MENSAGEM_ENVIADO, $resultado->mensagem);
        self::assertSame(4, $resultado->paradas);
        $avisos = $this->avisosDasParadas($this->tenant);
        self::assertEqualsCanonicalizing([$this->paula->getId(), $this->carla->getId(), $this->rui->getId()], array_keys($avisos));

        $doRui = $avisos[$this->rui->getId()][0];
        self::assertSame('Pastas paradas: 2 sob sua responsabilidade', $doRui->getTitulo());
        self::assertSame(
            'Pasta 1180 (CLIENTE 1180), 126 dias. Confirmar com o cliente a assinatura do contrato e juntar à pasta. | '
            . 'Pasta 1181 (CLIENTE 1181), 65 dias. Confirmar com o cliente a assinatura do contrato e juntar à pasta. Informe se vai movimentar ou arquivar.',
            $doRui->getMensagem(),
        );
        self::assertSame('/expediente/pastas-paradas', $doRui->getUrl());

        $daCarla = $avisos[$this->carla->getId()][0];
        self::assertSame('Relatório mensal: 4 pasta(s) paradas', $daCarla->getTitulo());
        self::assertStringStartsWith('1180 · CLIENTE 1180 · 126 dias · Rui Responsável | ', (string) $daCarla->getMensagem());
        self::assertSame('/expediente/pastas-paradas', $daCarla->getUrl());
        self::assertCount(1, $avisos[$this->paula->getId()]);
        self::assertSame($daCarla->getMensagem(), $avisos[$this->paula->getId()][0]->getMensagem());
        self::assertSame(1, $this->enviosDasParadas($this->tenant));
    }

    #[TestDox('Ordem do aviso geral: a mais parada primeiro')]
    public function testOrdemDoAvisoGeral(): void
    {
        $this->useCase()->executar($this->tenant);

        self::assertSame(
            '1180 · CLIENTE 1180 · 126 dias · Rui Responsável | 1182 · CLIENTE 1182 · 96 dias · Nina Sem Módulo | 1184 · CLIENTE 1184 · 82 dias · Ivo Inativo | 1181 · CLIENTE 1181 · 65 dias · Rui Responsável',
            $this->avisosDasParadas($this->tenant)[$this->carla->getId()][0]->getMensagem(),
        );
    }

    #[TestDox('Uma vez por mês: a segunda chamada no mesmo mês não envia nem grava de novo')]
    public function testUmaVezPorMes(): void
    {
        $primeiro = $this->useCase()->executar($this->tenant);
        $segundo  = $this->useCase('2026-10-05 15:00:00')->executar($this->tenant);

        self::assertTrue($primeiro->enviado);
        self::assertFalse($segundo->enviado);
        self::assertSame('O relatório deste mês já foi enviado.', $segundo->mensagem);
        self::assertSame(1, $this->enviosDasParadas($this->tenant));
        self::assertCount(1, $this->avisosDasParadas($this->tenant)[$this->rui->getId()]);
    }

    #[TestDox('A trava do mês é a gravação com ON CONFLICT: só a primeira chamada grava')]
    public function testGravacaoSoUmaVez(): void
    {
        $repositorio = static::getContainer()->get(EnvioDoRelatorioPastasParadasRepository::class);
        $relatorio   = new RelatorioPastasParadasOutput(new \DateTimeImmutable(self::PRIMEIRA_SEGUNDA), []);

        self::assertTrue($repositorio->registrarSeNovo($this->tenant, $relatorio, new \DateTimeImmutable(self::PRIMEIRA_SEGUNDA)));
        self::assertFalse($repositorio->registrarSeNovo($this->tenant, $relatorio, new \DateTimeImmutable('2026-10-05 10:06:00')));
        self::assertTrue($repositorio->registrarSeNovo($this->tenant, $relatorio, new \DateTimeImmutable('2026-11-02 10:05:00')), 'outro mês grava');
        self::assertSame(2, $this->enviosDasParadas($this->tenant));
    }

    #[TestDox('Fora da primeira segunda-feira, ou antes das 10h: não envia nem grava')]
    public function testForaDoDia(): void
    {
        $segundaSegunda = $this->useCase('2026-10-12 10:05:00')->executar($this->tenant);
        $terca          = $this->useCase('2026-10-06 10:05:00')->executar($this->tenant);
        $cedo           = $this->useCase('2026-10-05 09:59:00')->executar($this->tenant);

        self::assertSame('Hoje não é a primeira segunda-feira do mês.', $segundaSegunda->mensagem);
        self::assertSame('Hoje não é a primeira segunda-feira do mês.', $terca->mensagem);
        self::assertSame('O envio automático sai a partir das 10h.', $cedo->mensagem);
        self::assertSame(0, $this->enviosDasParadas($this->tenant));
        self::assertSame([], $this->avisosDasParadas($this->tenant));
    }

    #[TestDox('--dry-run: conta os avisos, mas não grava aviso nem o mês')]
    public function testSimular(): void
    {
        $resultado = $this->useCase()->executar($this->tenant, simular: true);

        self::assertTrue($resultado->enviado);
        self::assertSame(3, $resultado->avisados);
        self::assertSame([], $this->avisosDasParadas($this->tenant));
        self::assertSame(0, $this->enviosDasParadas($this->tenant));
    }

    #[TestDox('Controladoria que também é primária recebe um aviso só')]
    public function testSemRepetir(): void
    {
        $this->em()->getConnection()->executeStatement('UPDATE user_tenant SET controladoria = true WHERE user_id = ?', [$this->paula->getId()]);

        $this->useCase()->executar($this->tenant);

        self::assertCount(1, $this->avisosDasParadas($this->tenant)[$this->paula->getId()]);
    }

    #[TestDox('Sem o módulo Expediente não recebe: nem a Controladoria, nem o responsável')]
    public function testSoQuemTemExpediente(): void
    {
        $semModulo = $this->novoPerfil($this->tenant, false);
        foreach ([$this->carla, $this->rui] as $pessoa) {
            $this->em()->getRepository(UserTenant::class)->findOneBy(['user' => $pessoa, 'tenant' => $this->tenant])?->setTenantRole($semModulo);
        }
        $this->em()->flush();

        $this->useCase()->executar($this->tenant);

        self::assertSame([(int) $this->paula->getId()], array_keys($this->avisosDasParadas($this->tenant)));
    }

    #[TestDox('Texto longo: cada aviso é cortado em 255; o do responsável mantém o pedido no fim')]
    public function testCortadoEm255(): void
    {
        foreach (range(1, 8) as $i) {
            $this->pastaAbertaEm($this->tenant, '20' . $i, '2026-05-0' . $i, $this->rui);
        }

        $this->useCase()->executar($this->tenant);

        $avisos = $this->avisosDasParadas($this->tenant);
        $doRui  = (string) $avisos[$this->rui->getId()][0]->getMensagem();
        self::assertSame('Pastas paradas: 10 sob sua responsabilidade', $avisos[$this->rui->getId()][0]->getTitulo());
        self::assertLessThanOrEqual(255, mb_strlen($doRui));
        self::assertStringEndsWith('… Informe se vai movimentar ou arquivar.', $doRui);
        $geral = (string) $avisos[$this->carla->getId()][0]->getMensagem();
        self::assertSame(255, mb_strlen($geral));
        self::assertStringEndsWith('…', $geral);
    }

    #[TestDox('Sem pasta parada: grava o mês, não avisa ninguém e diz que não há pasta parada')]
    public function testSemPastaParada(): void
    {
        $this->em()->getConnection()->executeStatement("UPDATE pasta SET data_abertura = '2026-09-30' WHERE tenant_id = ?", [$this->tenant->getId()]);
        $this->em()->clear();
        $this->tenant = $this->em()->find(Tenant::class, $this->tenant->getId()) ?? $this->tenant;

        $resultado = $this->useCase()->executar($this->tenant);

        self::assertTrue($resultado->enviado);
        self::assertSame('Nenhuma pasta parada há mais de 30 dias.', $resultado->mensagem);
        self::assertSame(0, $resultado->avisados);
        self::assertSame([], $this->avisosDasParadas($this->tenant));
        self::assertSame(1, $this->enviosDasParadas($this->tenant));
    }

    #[TestDox('Outro escritório não vaza: nem as pastas no aviso, nem a Controladoria dele recebe')]
    public function testIsolamentoEntreEscritorios(): void
    {
        $outro  = $this->novoEscritorio('Outro');
        $alheia = $this->novaPessoa($outro, 'Controladoria Alheia', $this->novoPerfil($outro, true), controladoria: true);
        $this->pastaAbertaEm($outro, '9999', '2026-01-01', $alheia);

        $resultado = $this->useCase()->executar($this->tenant);

        self::assertSame(4, $resultado->paradas);
        self::assertSame([], $this->avisosDasParadas($outro));
        self::assertSame(0, $this->enviosDasParadas($outro));
        self::assertStringNotContainsString('9999', (string) $this->avisosDasParadas($this->tenant)[$this->carla->getId()][0]->getMensagem());
    }

    #[TestDox('O tipo do aviso está no mapa da Central: grupo Atenção, rótulo Relatório')]
    public function testTipoRegistradoNaCentral(): void
    {
        $tipo = static::getContainer()->get(TiposDeAvisoDaCentral::class)->para(EnviarRelatorioPastasParadasUseCase::TIPO_NOTIFICACAO);

        self::assertSame('atencao', $tipo['grupo']);
        self::assertSame('Relatório', $tipo['rotulo']);
        self::assertSame('bi-pause-circle', $tipo['icone']);
    }
}
