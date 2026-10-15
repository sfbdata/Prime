<?php

declare(strict_types=1);

namespace App\Tests\TrabalhoExterno\Unit;

use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\TrabalhoExterno\DTO\Autor;
use App\TrabalhoExterno\DTO\DadosDaSolicitacao;
use App\TrabalhoExterno\Entity\ConfiguracaoCentral;
use App\TrabalhoExterno\Entity\DemandaExterna;
use App\TrabalhoExterno\Entity\PropostaDemanda;
use App\TrabalhoExterno\Enum\AcaoDemanda;
use App\TrabalhoExterno\Enum\Perfil;
use App\TrabalhoExterno\Enum\Situacao;
use App\TrabalhoExterno\Service\Contexto;

/** Montagem de objetos de domínio para os testes unitários, sem banco (ids postos por reflexão). */
final class Apoio
{
    public static function tenant(int $id): Tenant
    {
        $t = new Tenant();
        $t->setName('Escritório ' . $id);
        self::id($t, $id);

        return $t;
    }

    public static function usuario(int $id, array $roles = ['ROLE_USER']): User
    {
        $u = new User();
        $u->setEmail("u$id@test.com");
        $u->setFullName("Usuário $id");
        $u->setRoles($roles);
        self::id($u, $id);

        return $u;
    }

    public static function contexto(Perfil $perfil, ?Tenant $tenant = null, ?ConfiguracaoCentral $cfg = null, ?User $usuario = null): Contexto
    {
        return new Contexto($usuario ?? self::usuario(99), $tenant ?? self::tenant(1), $perfil, $cfg ?? new ConfiguracaoCentral());
    }

    public static function autor(Perfil $perfil = Perfil::Master): Autor
    {
        return new Autor(self::usuario(50), 'Fulano', $perfil);
    }

    /** Uma demanda do escritório informado levada até a situação pedida pelo caminho real das ações. */
    public static function demanda(Situacao $ate, ?Tenant $tenant = null): DemandaExterna
    {
        $d = new DemandaExterna($tenant ?? self::tenant(1), self::usuario(10));
        self::id($d, 7);
        $dados = new DadosDaSolicitacao();
        $dados->descricao = 'Audiência';
        $dados->cidadeExecucao = 'Brasília';
        $d->preencher($dados);
        if ($ate === Situacao::Rascunho) {
            return $d;
        }

        $m = self::autor(Perfil::Master);
        $s = self::autor(Perfil::Solicitante);
        $d->enviar($s);
        if ($ate === Situacao::Info) {
            $d->aplicar(AcaoDemanda::IniciarAnalise, $m, null);
            $d->aplicar(AcaoDemanda::SolicitarInformacoes, $m, 'Falta a procuração');

            return $d;
        }
        $caminho = [
            Situacao::Analise->value => fn () => $d->aplicar(AcaoDemanda::IniciarAnalise, $m, null),
            Situacao::Proposta->value => fn () => $d->enviarProposta(new PropostaDemanda($d, 1, '100.00', null, null, new \DateTimeImmutable(), null, null, null, $m), $m),
            Situacao::Aceita->value => fn () => $d->aplicar(AcaoDemanda::AceitarProposta, $s, null),
            Situacao::Execucao->value => fn () => $d->aplicar(AcaoDemanda::IniciarExecucao, $m, null),
            Situacao::Validacao->value => fn () => $d->aplicar(AcaoDemanda::EnviarParaValidacao, $m, null),
            Situacao::Concluida->value => fn () => $d->aplicar(AcaoDemanda::Concluir, $m, null),
            Situacao::Arquivada->value => fn () => $d->aplicar(AcaoDemanda::Arquivar, $m, null),
        ];
        foreach ($caminho as $situacao => $passo) {
            if ($d->getSituacao() === $ate) {
                break;
            }
            $passo();
        }

        return $d;
    }

    private static function id(object $objeto, int $id): void
    {
        $r = new \ReflectionProperty($objeto, 'id');
        $r->setValue($objeto, $id);
    }
}
