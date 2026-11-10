<?php

declare(strict_types=1);

namespace App\Tests\Ponto\Unit;

use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Master\Entity\RecursoEstado;
use App\Master\Repository\ContasDoSistemaRepository;
use App\Master\Repository\RecursoEstadoRepository;
use App\Master\Service\RecursosDoSistema;
use App\Master\Service\RegraDosMasters;
use App\Ponto\DTO\FuncaoDoPontoOutput;
use App\Ponto\Entity\ConfiguracaoDoPonto;
use App\Ponto\Repository\ConfiguracaoDoPontoRepository;
use App\Ponto\Service\FuncoesDoPonto;
use App\Ponto\Service\SeloDoComprovante;
use App\Ponto\UseCase\ObterConfiguracoesDoPontoUseCase;
use App\Service\PermissionChecker;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * O conteúdo da seção das funções da aba Configurações (E7, E15, P67): só para quem administra o ponto do escritório; o
 * Master ganha a chave Global; travada mostra a do escritório desligada sem perder o valor guardado; sem o selo do
 * comprovante, o cartão do comprovante não aparece.
 */
#[CoversClass(ObterConfiguracoesDoPontoUseCase::class)]
#[CoversClass(FuncaoDoPontoOutput::class)]
#[Group('ponto')]
final class ObterConfiguracoesDoPontoUseCaseTest extends TestCase
{
    #[TestDox('Sem a permissão de administrar o ponto: null (a seção não aparece)')]
    public function testSemPermissao(): void
    {
        self::assertNull($this->casoDeUso(false, null, true)->executar(new User(), new Tenant()));
    }

    #[TestDox('Sem linha e sem registro do Master: as seis funções (E7, E8, E11 e E15) aparecem ligadas nas duas camadas, na ordem do desenho')]
    public function testNasceLigada(): void
    {
        $saida = $this->casoDeUso(true, null, true)->executar($this->pessoa(['ROLE_USER']), new Tenant());

        self::assertNotNull($saida);
        self::assertFalse($saida->ehMaster);
        self::assertSame(
            [
                ['ponto-animacao', 'Validação em tela cheia', 'bi-clock-history', 'Relógio completando a volta e confirmação ocupando toda a tela.'],
                ['ponto-sons', 'Sons de confirmação e erro', 'bi-volume-up', 'Plim no registro e alerta diferente quando o ponto falha.'],
                ['ponto-comprovante', 'Comprovante em imagem', 'bi-receipt', "Imagem em alta definição com código de validação, QR Code, marca d'água e padrões de segurança."],
                ['ponto-geo', 'Geolocalização', 'bi-geo-alt', 'Exige que o registro seja feito dentro do raio de uma sede.'],
                ['ponto-lembrete', 'Lembrete de saída', 'bi-bell', 'Avisa quando passar do horário previsto e a saída não foi registrada.'],
                ['ponto-alertas', 'Alertas de intervalo', 'bi-exclamation-triangle', 'Avisa antes da batida sobre intervalo, interjornada e descanso semanal.'],
            ],
            array_map(static fn (FuncaoDoPontoOutput $f): array => [$f->chave, $f->titulo, $f->icone, $f->ajuda], $saida->funcoes),
        );
        foreach ($saida->funcoes as $funcao) {
            self::assertTrue($funcao->global, $funcao->chave);
            self::assertTrue($funcao->escritorio, $funcao->chave);
            self::assertFalse($funcao->travada(), $funcao->chave);
            self::assertTrue($funcao->escritorioEfetivo(), $funcao->chave);
        }
    }

    #[TestDox('Global desligado: travada, a do escritório aparece desligada e o valor guardado continua')]
    public function testGlobalDesligadoTrava(): void
    {
        $tenant = new Tenant();
        $configuracao = new ConfiguracaoDoPonto($tenant);
        $configuracao->definir('ponto-geo', true, new User(), new \DateTimeImmutable());

        $funcoes = $this->casoDeUso(true, $configuracao, false)->executar($this->pessoa(['ROLE_SUPER_ADMIN']), $tenant)?->funcoes ?? [];
        $geo = array_values(array_filter($funcoes, static fn (FuncaoDoPontoOutput $f): bool => $f->chave === 'ponto-geo'))[0] ?? null;

        self::assertNotNull($geo);
        self::assertFalse($geo->global);
        self::assertTrue($geo->escritorio, 'o valor guardado');
        self::assertTrue($geo->travada());
        self::assertFalse($geo->escritorioEfetivo());
    }

    #[TestDox('O Master é marcado para ver a chave Global')]
    public function testMaster(): void
    {
        self::assertTrue($this->casoDeUso(true, null, true)->executar($this->pessoa(['ROLE_SUPER_ADMIN']), new Tenant())?->ehMaster);
    }

    #[TestDox('Sem o selo do comprovante (segredo do HMAC ausente): as outras cinco funções, sem o cartão do comprovante')]
    public function testSemSeloSoAGeolocalizacao(): void
    {
        $saida = $this->casoDeUso(true, null, true, seloDisponivel: false)->executar($this->pessoa(['ROLE_SUPER_ADMIN']), new Tenant());

        self::assertNotNull($saida);
        self::assertSame(['ponto-animacao', 'ponto-sons', 'ponto-geo', 'ponto-lembrete', 'ponto-alertas'], array_map(static fn (FuncaoDoPontoOutput $funcao): string => $funcao->chave, $saida->funcoes));
        self::assertTrue($saida->funcoes[2]->escritorioEfetivo(), 'a geolocalização não muda');
    }

    /** @param list<string> $papeis */
    private function pessoa(array $papeis): User
    {
        $user = new User();
        $user->setRoles($papeis);

        return $user;
    }

    private function casoDeUso(bool $podeConfigurar, ?ConfiguracaoDoPonto $configuracao, bool $globalLigado, bool $seloDisponivel = true): ObterConfiguracoesDoPontoUseCase
    {
        $estados = $this->createStub(RecursoEstadoRepository::class);
        $gravados = [];
        if (!$globalLigado) {
            $gravados['ponto-geo'] = new RecursoEstado('ponto-geo');
            $gravados['ponto-geo']->desativar(null, null, null, new \DateTimeImmutable('2026-11-01'));
        }
        $estados->method('todosPorChave')->willReturn($gravados);
        $regra = new RegraDosMasters($this->createStub(ContasDoSistemaRepository::class));
        $recursos = new RecursosDoSistema($estados, new MockClock('2026-11-09 10:00:00'), $this->createStub(Security::class), $regra, new RequestStack(), new NullLogger());
        $configuracoes = $this->createStub(ConfiguracaoDoPontoRepository::class);
        $configuracoes->method('doTenant')->willReturn($configuracao);
        $permissoes = $this->createStub(PermissionChecker::class);
        $permissoes->method('canAdminister')->willReturn($podeConfigurar);
        $selo = $this->createStub(SeloDoComprovante::class);
        $selo->method('disponivel')->willReturn($seloDisponivel);

        return new ObterConfiguracoesDoPontoUseCase(new FuncoesDoPonto($recursos, $configuracoes, $permissoes), $configuracoes, $regra, $selo);
    }
}
