<?php

declare(strict_types=1);

namespace App\Master\UseCase;

use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Master\Entity\Convenio;
use App\Master\Entity\ConvenioVinculo;
use App\Master\Enum\AbrangenciaDoConvenio;
use App\Master\Enum\OrigemDoVinculo;
use App\Master\Enum\ProvaDoConvenio;
use App\Master\Exception\ConvenioRecusado;
use App\Master\Repository\ConvenioRepository;
use App\Master\Repository\ConvenioVinculoRepository;
use App\Master\Service\ChaveDoConvenio;
use App\Master\Service\LocalizacaoDaPessoa;
use App\Repository\UserTenantRepository;
use App\Shared\Audit\EscopoDeAuditoriaDePlataforma;
use App\Tenant\Exception\OperacaoDePrimarioRecusada;
use App\Tenant\Service\RegraDosPrimarios;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;

/**
 * Aplica um código de convite: o escritório entra no convênio (spec docs/specs/convenios.md, §5.3, PR 5).
 *
 * Quem dispara: um primário do escritório (titular ou sócio, {@see RegraDosPrimarios}) no Meu Perfil, ou o cadastro,
 * que guarda o `?convenio=` e aplica ao criar o escritório (quem cria é o primário).
 * Confere: chave geral ligada; o código existe; convênio por código, ligado, dentro da validade e sem fim marcado;
 * abaixo do limite de usos; no convênio de um estado, o estado do escritório bate (sem estado ainda, aceita: a tarefa
 * diária fecha se o estado vier outro). Toda recusa tem o mesmo texto, sem dizer qual regra falhou.
 * Numa transação, com a trava do escritório (a mesma da sincronização) e a do convênio (o limite de usos). O vínculo
 * grava na Trilha do escritório.
 */
final class AplicarCodigoDeConvenioUseCase
{
    public const RECUSA = 'Código inválido ou encerrado.';
    public const RECUSA_PRIMARIO = 'Só o usuário primário do escritório pode aplicar um código de convênio.';

    public function __construct(
        private readonly ConvenioRepository $convenios,
        private readonly ConvenioVinculoRepository $vinculos,
        private readonly ChaveDoConvenio $chave,
        private readonly UserTenantRepository $userTenants,
        private readonly RegraDosPrimarios $regraDosPrimarios,
        private readonly EscopoDeAuditoriaDePlataforma $escopoDePlataforma,
        private readonly EntityManagerInterface $em,
        private readonly Connection $conexao,
        private readonly ClockInterface $relogio,
    ) {
    }

    /** O código como o Master o vê: maiúsculas, sem espaço nem hífen (a pessoa pode copiar "abcd-2345"). */
    public static function normalizar(string $codigo): string
    {
        return strtoupper((string) preg_replace('/[\s\-]+/', '', $codigo));
    }

    /**
     * @throws OperacaoDePrimarioRecusada quem pede não é primário do escritório
     * @throws ConvenioRecusado           código que não vale (texto único) ou convênio que já vale
     */
    public function executar(User $quem, Tenant $tenant, string $codigo): Convenio
    {
        $vinculo = $this->userTenants->findAtivoPorUserETenant($quem, $tenant);
        if ($vinculo === null || !$this->regraDosPrimarios->ehPrimario($vinculo)) {
            throw new OperacaoDePrimarioRecusada(self::RECUSA_PRIMARIO);
        }
        $codigo = self::normalizar($codigo);
        if (!$this->chave->ativo() || preg_match('/^[A-Z0-9]{4,16}$/', $codigo) !== 1) {
            throw new ConvenioRecusado(self::RECUSA);
        }
        $tenantId = (int) $tenant->getId();

        return $this->conexao->transactional(function () use ($quem, $tenant, $tenantId, $codigo): Convenio {
            $this->conexao->executeQuery("SELECT pg_advisory_xact_lock(hashtextextended('convenio_vinculo:' || ?, 0))", [$tenantId]);
            $convenio = $this->convenios->porCodigo($codigo) ?? throw new ConvenioRecusado(self::RECUSA);
            $this->conexao->executeQuery("SELECT pg_advisory_xact_lock(hashtextextended('convenio:' || ?, 0))", [$convenio->getId()]);
            $this->em->refresh($convenio);
            $agora = \DateTimeImmutable::createFromInterface($this->relogio->now());
            $this->exigirQueVale($convenio, $tenant, $agora);
            foreach ($this->vinculos->abertosDosTenants([$tenantId])[$tenantId] ?? [] as $aberto) {
                if ($aberto->getConvenio()->getId() === $convenio->getId()) {
                    throw new ConvenioRecusado('Este convênio já vale para o seu escritório.');
                }
            }
            $novo = new ConvenioVinculo($tenant, $convenio, OrigemDoVinculo::Codigo, $agora, $quem);
            $this->escopoDePlataforma->executarComoConta($tenantId, fn () => $this->vinculos->salvar($novo, flush: true));

            return $convenio;
        });
    }

    private function exigirQueVale(Convenio $convenio, Tenant $tenant, \DateTimeImmutable $agora): void
    {
        $limite = $convenio->getLimiteDeUsos();
        $estado = LocalizacaoDaPessoa::uf($tenant->getEstado());
        $vale = $convenio->getProva() === ProvaDoConvenio::Codigo
            && $convenio->vigenteEm($agora)
            && $convenio->getEncerraEm() === null
            && ($limite === null || $this->vinculos->usosDoCodigo((int) $convenio->getId()) < $limite)
            && ($convenio->getAbrangencia() !== AbrangenciaDoConvenio::Uf || $estado === null || $estado === $convenio->getUf());
        if (!$vale) {
            throw new ConvenioRecusado(self::RECUSA);
        }
    }
}
