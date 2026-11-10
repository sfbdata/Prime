<?php

declare(strict_types=1);

namespace App\Tests\Ponto\Functional;

use App\Entity\Auth\User;
use App\Entity\Auth\UserTenant;
use App\Entity\Tenant\Tenant;
use App\Ponto\Service\GestaoDoPonto;
use App\Service\PermissionChecker;
use App\Tenant\Enum\SituacaoNaEquipe;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/** Spec `docs/specs/ponto-desenho-0811.md`, P58: quem vê as abas Equipe e Configurações da /ponto. */
#[CoversClass(GestaoDoPonto::class)]
final class GestaoDoPontoTest extends KernelTestCase
{
    use CenarioDeJustificativaTrait;

    #[TestDox('O primário e o Master veem as duas abas; o colaborador, nenhuma')]
    public function testPrimarioMasterEColaborador(): void
    {
        self::bootKernel();
        $escritorio = $this->escritorio('Gestão');
        $primario = $this->pessoa('primario');
        $this->vincularPrimario($primario, $escritorio);
        $colaborador = $this->pessoa('colaborador');
        $this->vincularColaborador($colaborador, $escritorio);
        $master = $this->pessoa('master', ['ROLE_USER', 'ROLE_SUPER_ADMIN']);

        foreach ([$primario, $master] as $quem) {
            self::assertTrue($this->gestao()->veEquipe($quem, $escritorio));
            self::assertTrue($this->gestao()->veConfiguracoes($quem, $escritorio));
        }
        self::assertFalse($this->gestao()->veEquipe($colaborador, $escritorio));
        self::assertFalse($this->gestao()->veConfiguracoes($colaborador, $escritorio));
    }

    #[TestDox('Pelo perfil: gerir usuários abre a Equipe; gerir o ponto ou as configurações abre as Configurações')]
    public function testPeloPerfil(): void
    {
        self::bootKernel();
        $escritorio = $this->escritorio('Gestão');
        $usuarios = $this->pessoa('usuarios');
        $this->vincular($usuarios, $escritorio, ['modules.ponto.view', GestaoDoPonto::EQUIPE], false);
        $sedes = $this->pessoa('sedes');
        $this->vincular($sedes, $escritorio, ['modules.ponto.view', GestaoDoPonto::SEDES], false);
        $feriados = $this->pessoa('feriados');
        $this->vincular($feriados, $escritorio, ['modules.ponto.view', GestaoDoPonto::FERIADOS], false);

        self::assertTrue($this->gestao()->veEquipe($usuarios, $escritorio));
        self::assertFalse($this->gestao()->veConfiguracoes($usuarios, $escritorio));
        self::assertFalse($this->gestao()->veEquipe($sedes, $escritorio));
        self::assertTrue($this->gestao()->veConfiguracoes($sedes, $escritorio));
        self::assertTrue($this->gestao()->veConfiguracoes($feriados, $escritorio));
    }

    #[TestDox('A permissão de um escritório não vale em outro, e o vínculo inativo não vale')]
    public function testIsolamentoEVinculoInativo(): void
    {
        self::bootKernel();
        $meu = $this->escritorio('Meu');
        $outro = $this->escritorio('Outro');
        $gestor = $this->pessoa('gestor');
        $this->vincularGestor($gestor, $meu);
        $this->vincularColaborador($gestor, $outro);
        self::assertFalse($this->gestao()->veEquipe($gestor, $outro));

        $inativo = $this->pessoa('inativo');
        $this->vincularGestor($inativo, $meu);
        $this->desativar($inativo, $meu);
        self::assertFalse($this->gestao()->veEquipe($inativo, $meu));
    }

    private function gestao(): GestaoDoPonto
    {
        return new GestaoDoPonto(static::getContainer()->get(PermissionChecker::class));
    }

    private function desativar(User $user, Tenant $tenant): void
    {
        $vinculo = $this->em()->getRepository(UserTenant::class)->findOneBy(['user' => $user, 'tenant' => $tenant]);
        self::assertNotNull($vinculo);
        $vinculo->alterarSituacao(SituacaoNaEquipe::Suspenso);
        $this->em()->flush();
    }
}
